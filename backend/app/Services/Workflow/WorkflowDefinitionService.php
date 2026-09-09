<?php

namespace App\Services\Workflow;

use App\Models\WorkflowStep;
use App\Models\WorkflowVersion;
use App\Services\Policy\InvalidPolicyConditionException;
use App\Services\Policy\PolicyConditionEvaluator;
use Illuminate\Support\Facades\DB;

/**
 * Structural validation and persistence of a workflow version's steps and
 * transitions. Only DRAFT versions may be replaced - an ACTIVE version is
 * immutable (see WorkflowController), and historical workflow_instances
 * stay linked to the exact version that executed them.
 */
class WorkflowDefinitionService
{
    public function __construct(private readonly PolicyConditionEvaluator $conditionEvaluator) {}

    /**
     * @param  array<int, array{step_key: string, step_type: string, name?: string, config?: ?array, sort_order?: int}>  $steps
     * @param  array<int, array{from_step_key: string, to_step_key: string, condition?: ?array, sort_order?: int}>  $transitions
     * @return string[] validation error messages, empty when structurally valid
     */
    public function validateStructure(array $steps, array $transitions): array
    {
        $errors = [];

        if (empty($steps)) {
            return ['At least one step is required.'];
        }

        $keys = array_map(fn ($s) => $s['step_key'] ?? null, $steps);
        if (count(array_filter($keys)) !== count($steps) || count($keys) !== count(array_unique($keys))) {
            $errors[] = 'Every step requires a unique, non-empty step_key.';
        }

        $starts = array_values(array_filter($steps, fn ($s) => ($s['step_type'] ?? null) === WorkflowStep::TYPE_START));
        if (count($starts) !== 1) {
            $errors[] = 'Exactly one START step is required.';
        }

        if (empty(array_filter($steps, fn ($s) => ($s['step_type'] ?? null) === WorkflowStep::TYPE_END))) {
            $errors[] = 'At least one END step is required.';
        }

        foreach ($steps as $s) {
            if (! in_array($s['step_type'] ?? null, WorkflowStep::TYPES, true)) {
                $errors[] = "Invalid step_type for step [{$s['step_key']}].";

                continue;
            }
            $errors = [...$errors, ...$this->validateStepConfig($s)];
        }

        $stepKeySet = array_flip(array_filter($keys));
        $outgoingCount = [];
        $adjacency = [];

        foreach ($transitions as $t) {
            foreach (['from_step_key', 'to_step_key'] as $field) {
                if (empty($t[$field]) || ! isset($stepKeySet[$t[$field]])) {
                    $badKey = $t[$field] ?? '';
                    $errors[] = "Transition references an unknown step [{$badKey}].";
                }
            }

            if (! empty($t['condition'])) {
                try {
                    $this->conditionEvaluator->validate($t['condition']);
                } catch (InvalidPolicyConditionException $e) {
                    $errors[] = "Invalid transition condition: {$e->getMessage()}";
                }
            }

            if (! empty($t['from_step_key'])) {
                $outgoingCount[$t['from_step_key']] = ($outgoingCount[$t['from_step_key']] ?? 0) + 1;
                $adjacency[$t['from_step_key']][] = $t['to_step_key'] ?? null;
            }
        }

        foreach ($steps as $s) {
            if (($s['step_type'] ?? null) === WorkflowStep::TYPE_END) {
                continue;
            }
            if (empty($outgoingCount[$s['step_key'] ?? ''] ?? null)) {
                $errors[] = "Step [{$s['step_key']}] has no outgoing transition (dangling required step).";
            }
        }

        if ($starts) {
            $errors = [...$errors, ...$this->checkReachability($starts[0]['step_key'], $steps, $adjacency)];
        }

        return $errors;
    }

    /**
     * @return string[]
     */
    private function checkReachability(string $startKey, array $steps, array $adjacency): array
    {
        $visited = [$startKey => true];
        $queue = [$startKey];

        while ($queue) {
            $current = array_shift($queue);
            foreach ($adjacency[$current] ?? [] as $next) {
                if ($next && ! isset($visited[$next])) {
                    $visited[$next] = true;
                    $queue[] = $next;
                }
            }
        }

        $errors = [];
        foreach ($steps as $s) {
            if (! empty($s['step_key']) && ! isset($visited[$s['step_key']])) {
                $errors[] = "Step [{$s['step_key']}] is unreachable from START.";
            }
        }

        return $errors;
    }

    private function validateStepConfig(array $step): array
    {
        $config = $step['config'] ?? [];
        $key = $step['step_key'] ?? '?';

        return match ($step['step_type']) {
            WorkflowStep::TYPE_APPROVAL => empty($config['approval_definition_code'])
                ? ["APPROVAL step [{$key}] requires config.approval_definition_code."] : [],
            WorkflowStep::TYPE_INTEGRATION => (empty($config['integration_code']) || empty($config['endpoint_key']))
                ? ["INTEGRATION step [{$key}] requires config.integration_code and config.endpoint_key."] : [],
            WorkflowStep::TYPE_INTERNAL_ACTION => (empty($config['action']) || ! in_array($config['action'], InternalActionRegistry::ACTIONS, true))
                ? ["INTERNAL_ACTION step [{$key}] requires config.action to be one of: ".implode(', ', InternalActionRegistry::ACTIONS).'.'] : [],
            WorkflowStep::TYPE_WAIT => empty($config['duration_seconds'])
                ? ["WAIT step [{$key}] requires config.duration_seconds."] : [],
            default => [],
        };
    }

    public function replaceDraftDefinition(WorkflowVersion $version, array $steps, array $transitions): void
    {
        DB::transaction(function () use ($version, $steps, $transitions) {
            $version->transitions()->delete();
            $version->steps()->delete();

            $keyToId = [];
            foreach ($steps as $i => $s) {
                $step = $version->steps()->create([
                    'step_key' => $s['step_key'],
                    'step_type' => $s['step_type'],
                    'name' => $s['name'] ?? $s['step_key'],
                    'config' => $s['config'] ?? null,
                    'sort_order' => $s['sort_order'] ?? $i,
                ]);
                $keyToId[$s['step_key']] = $step->id;
            }

            foreach ($transitions as $i => $t) {
                $version->transitions()->create([
                    'from_step_id' => $keyToId[$t['from_step_key']],
                    'to_step_id' => $keyToId[$t['to_step_key']],
                    'condition' => $t['condition'] ?? null,
                    'sort_order' => $t['sort_order'] ?? $i,
                ]);
            }
        });
    }
}
