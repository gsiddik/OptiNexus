<?php

namespace App\Services\Workflow;

use App\Models\Event;
use App\Models\User;
use App\Models\Workflow;
use App\Models\WorkflowInstance;
use App\Models\WorkflowInstanceStep;
use App\Models\WorkflowStep;
use App\Models\WorkflowTransition;
use App\Services\AuditService;
use App\Services\Event\EventDispatchService;
use App\Services\Policy\PolicyConditionEvaluator;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Deterministic workflow execution: advances an instance step by step
 * inside DB transactions until it hits a blocking step (APPROVAL,
 * INTEGRATION, WAIT) or END, then stops. APPROVAL/INTEGRATION/WAIT
 * completion resumes execution via resumeFromApproval()/resumeFromWait()/
 * resumeFromIntegration(), called by those respective domains once they
 * exist (see Cross-Module Orchestration wiring).
 *
 * Every step execution is wrapped so a step that throws is recorded as a
 * FAILED instance/instance-step with a sanitized error rather than
 * bubbling an exception into the trigger caller.
 */
class WorkflowExecutionService
{
    /** Internal action / integration / approval hooks are late-bound via
     * the container so this service has no hard constructor dependency on
     * domains that may not exist yet in earlier build steps. */
    public function __construct(
        private readonly PolicyConditionEvaluator $conditionEvaluator,
        private readonly EventDispatchService $events,
        private readonly AuditService $audit,
    ) {}

    /**
     * @return array{0: ?WorkflowInstance, 1: ?string} [instance, errorCode]
     */
    public function trigger(
        Workflow $workflow,
        string $triggerType,
        array $payload = [],
        ?string $correlationId = null,
        ?string $causationId = null,
        ?string $idempotencyKey = null,
        ?User $actor = null,
        ?Event $triggerEvent = null,
    ): array {
        if (! $workflow->isActive()) {
            return [null, 'WORKFLOW_NOT_ACTIVE'];
        }

        $version = $workflow->activeVersion();
        if (! $version) {
            return [null, 'WORKFLOW_NOT_ACTIVE'];
        }

        if ($idempotencyKey) {
            $existing = WorkflowInstance::query()->where('workflow_id', $workflow->id)->where('idempotency_key', $idempotencyKey)->first();
            if ($existing) {
                return [$existing, null];
            }
        }

        $startStep = $version->startStep();
        if (! $startStep) {
            return [null, 'WORKFLOW_INVALID'];
        }

        try {
            $instance = DB::transaction(function () use ($workflow, $version, $triggerType, $payload, $correlationId, $causationId, $idempotencyKey, $actor, $triggerEvent, $startStep) {
                $instance = WorkflowInstance::create([
                    'workflow_id' => $workflow->id,
                    'workflow_version_id' => $version->id,
                    'tenant_id' => $workflow->tenant_id,
                    'application_id' => $workflow->application_id,
                    'status' => WorkflowInstance::STATUS_RUNNING,
                    'trigger_type' => $triggerType,
                    'trigger_event_id' => $triggerEvent?->id,
                    'trigger_payload' => $payload,
                    'current_step_id' => $startStep->id,
                    'correlation_id' => $correlationId ?? $triggerEvent?->correlation_id ?? (string) Str::uuid(),
                    'causation_id' => $causationId ?? $triggerEvent?->id,
                    'idempotency_key' => $idempotencyKey,
                    'started_by' => $actor?->id,
                    'started_at' => now(),
                ]);

                $instance->steps()->create([
                    'workflow_step_id' => $startStep->id,
                    'status' => WorkflowInstanceStep::STATUS_COMPLETED,
                    'started_at' => now(),
                    'completed_at' => now(),
                ]);

                return $instance;
            });
        } catch (UniqueConstraintViolationException) {
            // Lost a race against a concurrent identical trigger.
            return [WorkflowInstance::query()->where('workflow_id', $workflow->id)->where('idempotency_key', $idempotencyKey)->firstOrFail(), null];
        }

        $this->audit->record(
            'workflow.instance.started',
            actor: $actor,
            resourceType: 'WorkflowInstance',
            resourceId: $instance->id,
            newValue: ['workflow_id' => $workflow->id, 'trigger_type' => $triggerType],
            tenantId: $instance->tenant_id,
            applicationId: $instance->application_id,
            correlationId: $instance->correlation_id,
            causationId: $instance->causation_id,
        );

        $this->advance($instance);

        return [$instance->refresh(), null];
    }

    /**
     * Advances the instance from its current step until it blocks
     * (WAITING/WAITING_APPROVAL) or reaches a terminal state.
     */
    public function advance(WorkflowInstance $instance): void
    {
        if (! $instance->isOpen()) {
            return;
        }

        while (true) {
            $current = $instance->currentStep()->first();

            if (! $current || $current->step_type === WorkflowStep::TYPE_END) {
                $this->complete($instance);

                return;
            }

            $next = $this->resolveNextStep($current, $this->buildContext($instance));

            if (! $next) {
                $this->fail($instance, 'WORKFLOW_STEP_FAILED', "No transition matched from step [{$current->step_key}].");

                return;
            }

            $instanceStep = $instance->steps()->create([
                'workflow_step_id' => $next->id,
                'status' => WorkflowInstanceStep::STATUS_RUNNING,
                'attempt_count' => 1,
                'started_at' => now(),
            ]);

            $instance->update(['current_step_id' => $next->id]);

            $outcome = $this->executeStep($instance, $next, $instanceStep);

            if ($outcome === 'wait') {
                return; // instance status already set to WAITING/WAITING_APPROVAL by executeStep
            }

            if ($outcome === 'failed') {
                return; // instance status already set to FAILED by executeStep
            }

            // 'continue': loop to advance through the next step automatically.
        }
    }

    /**
     * @return string 'continue'|'wait'|'failed'
     */
    private function executeStep(WorkflowInstance $instance, WorkflowStep $step, WorkflowInstanceStep $instanceStep): string
    {
        try {
            return match ($step->step_type) {
                WorkflowStep::TYPE_CONDITION, WorkflowStep::TYPE_END => $this->completeStep($instanceStep),
                WorkflowStep::TYPE_TASK => $this->completeStep($instanceStep, ['note' => 'TASK step logged, no further action tracked by CGO.']),
                WorkflowStep::TYPE_INTERNAL_ACTION => $this->executeInternalAction($instance, $step, $instanceStep),
                WorkflowStep::TYPE_APPROVAL => $this->executeApprovalStep($instance, $step, $instanceStep),
                WorkflowStep::TYPE_INTEGRATION => $this->executeIntegrationStep($instance, $step, $instanceStep),
                WorkflowStep::TYPE_WAIT => $this->executeWaitStep($instance, $step, $instanceStep),
                default => $this->failStep($instance, $instanceStep, 'WORKFLOW_STEP_FAILED', "Unsupported step_type [{$step->step_type}]."),
            };
        } catch (\Throwable $e) {
            return $this->failStep($instance, $instanceStep, 'WORKFLOW_STEP_FAILED', $e->getMessage());
        }
    }

    private function completeStep(WorkflowInstanceStep $instanceStep, array $output = []): string
    {
        $instanceStep->update(['status' => WorkflowInstanceStep::STATUS_COMPLETED, 'output' => $output ?: null, 'completed_at' => now()]);

        return 'continue';
    }

    private function failStep(WorkflowInstance $instance, WorkflowInstanceStep $instanceStep, string $code, string $message): string
    {
        $instanceStep->update([
            'status' => WorkflowInstanceStep::STATUS_FAILED,
            'last_error_code' => $code,
            'last_error_message' => $this->sanitizeError($message),
        ]);
        $this->fail($instance, $code, $message);

        return 'failed';
    }

    private function executeInternalAction(WorkflowInstance $instance, WorkflowStep $step, WorkflowInstanceStep $instanceStep): string
    {
        $action = $step->config['action'] ?? null;

        if ($action === InternalActionRegistry::EMIT_EVENT) {
            $eventKey = $step->config['event_key'] ?? null;
            if (! $eventKey) {
                return $this->failStep($instance, $instanceStep, 'WORKFLOW_STEP_FAILED', 'emit_event requires config.event_key.');
            }

            [$event, $errorCode] = $this->events->ingest([
                'event_key' => $eventKey,
                'tenant_id' => $instance->tenant_id,
                'correlation_id' => $instance->correlation_id,
                'causation_id' => $instance->id,
                'data' => $step->config['data'] ?? ['workflow_instance_id' => $instance->id],
            ], $this->systemProducer($instance));

            if ($errorCode) {
                return $this->failStep($instance, $instanceStep, 'WORKFLOW_STEP_FAILED', "emit_event failed: {$errorCode}");
            }

            return $this->completeStep($instanceStep, ['emitted_event_id' => $event->id]);
        }

        // no_op and any other whitelisted no-effect action.
        return $this->completeStep($instanceStep);
    }

    private function executeApprovalStep(WorkflowInstance $instance, WorkflowStep $step, WorkflowInstanceStep $instanceStep): string
    {
        /** @var \App\Services\Approval\ApprovalService $approvals */
        $approvals = app(\App\Services\Approval\ApprovalService::class);

        [$approvalRequest, $error] = $approvals->createRequestForWorkflow($instance, $step);

        if ($error) {
            return $this->failStep($instance, $instanceStep, 'WORKFLOW_STEP_FAILED', "Could not create approval request: {$error}");
        }

        $instanceStep->update(['status' => WorkflowInstanceStep::STATUS_WAITING, 'output' => ['approval_request_id' => $approvalRequest->id]]);
        $instance->update(['status' => WorkflowInstance::STATUS_WAITING_APPROVAL]);

        return 'wait';
    }

    /**
     * The dispatch (which queues DeliverIntegrationRequest) and the
     * WAITING status transition are wrapped in one transaction so a queue
     * worker can never resume the step before this instance is actually
     * marked WAITING - DeliverIntegrationRequest implements
     * ShouldQueueAfterCommit, so Laravel holds the job off the queue
     * until this transaction (and therefore the status update) commits,
     * closing what would otherwise be a race between a fast worker and
     * this method's own follow-up writes.
     */
    private function executeIntegrationStep(WorkflowInstance $instance, WorkflowStep $step, WorkflowInstanceStep $instanceStep): string
    {
        /** @var \App\Services\Integration\IntegrationDeliveryService $integrations */
        $integrations = app(\App\Services\Integration\IntegrationDeliveryService::class);

        [$log, $error] = DB::transaction(function () use ($integrations, $instance, $step, $instanceStep) {
            [$log, $error] = $integrations->dispatchForWorkflow($instance, $step);

            if (! $error) {
                $instanceStep->update(['status' => WorkflowInstanceStep::STATUS_WAITING, 'output' => ['integration_log_id' => $log->id]]);
                $instance->update(['status' => WorkflowInstance::STATUS_WAITING]);
            }

            return [$log, $error];
        });

        if ($error) {
            return $this->failStep($instance, $instanceStep, 'WORKFLOW_STEP_FAILED', "Integration dispatch failed: {$error}");
        }

        return 'wait';
    }

    private function executeWaitStep(WorkflowInstance $instance, WorkflowStep $step, WorkflowInstanceStep $instanceStep): string
    {
        $seconds = (int) ($step->config['duration_seconds'] ?? 0);

        $instanceStep->update(['status' => WorkflowInstanceStep::STATUS_WAITING, 'next_retry_at' => now()->addSeconds(max(1, $seconds))]);
        $instance->update(['status' => WorkflowInstance::STATUS_WAITING]);

        return 'wait';
    }

    /**
     * Called by the scheduled sweep once a WAIT step's duration elapses.
     */
    public function resumeFromWait(WorkflowInstanceStep $instanceStep): void
    {
        $instance = $instanceStep->instance;
        if (! $instance || $instance->status !== WorkflowInstance::STATUS_WAITING) {
            return;
        }

        $this->completeStep($instanceStep);
        $instance->update(['status' => WorkflowInstance::STATUS_RUNNING]);
        $this->advance($instance);
    }

    /**
     * Called by ApprovalService once a request tied to a workflow step
     * reaches a terminal decision.
     */
    public function resumeFromApproval(WorkflowInstanceStep $instanceStep, string $decision): void
    {
        $instance = $instanceStep->instance;
        if (! $instance || $instance->status !== WorkflowInstance::STATUS_WAITING_APPROVAL) {
            return;
        }

        $this->completeStep($instanceStep, ['approval_decision' => $decision]);
        $instance->update(['status' => WorkflowInstance::STATUS_RUNNING]);
        $this->advance($instance);
    }

    /**
     * Called by the Integration delivery job once an outbound call
     * initiated by an INTEGRATION step finishes (success or failure).
     */
    public function resumeFromIntegration(WorkflowInstanceStep $instanceStep, bool $success, ?string $errorMessage = null): void
    {
        $instance = $instanceStep->instance;
        if (! $instance || $instance->status !== WorkflowInstance::STATUS_WAITING) {
            return;
        }

        if (! $success) {
            $this->failStep($instance, $instanceStep, 'WORKFLOW_STEP_FAILED', $errorMessage ?? 'Integration delivery failed.');

            return;
        }

        $this->completeStep($instanceStep);
        $instance->update(['status' => WorkflowInstance::STATUS_RUNNING]);
        $this->advance($instance);
    }

    public function retry(WorkflowInstance $instance): ?string
    {
        if ($instance->status !== WorkflowInstance::STATUS_FAILED) {
            return 'INVALID_STATE_TRANSITION';
        }

        $failedStep = $instance->steps()->where('status', WorkflowInstanceStep::STATUS_FAILED)->latest('created_at')->first();
        if (! $failedStep) {
            return 'WORKFLOW_INSTANCE_FAILED';
        }

        $failedStep->update(['status' => WorkflowInstanceStep::STATUS_RUNNING, 'attempt_count' => $failedStep->attempt_count + 1, 'last_error_code' => null, 'last_error_message' => null]);
        $instance->update(['status' => WorkflowInstance::STATUS_RUNNING]);

        $step = $failedStep->step;
        $outcome = $this->executeStep($instance, $step, $failedStep);

        if ($outcome === 'continue') {
            $this->advance($instance);
        }

        return null;
    }

    public function cancel(WorkflowInstance $instance): ?string
    {
        if (! $instance->isOpen()) {
            return 'WORKFLOW_ALREADY_COMPLETED';
        }

        DB::transaction(function () use ($instance) {
            $instance->steps()->whereIn('status', [WorkflowInstanceStep::STATUS_PENDING, WorkflowInstanceStep::STATUS_RUNNING, WorkflowInstanceStep::STATUS_WAITING])
                ->update(['status' => WorkflowInstanceStep::STATUS_CANCELLED]);
            $instance->update(['status' => WorkflowInstance::STATUS_CANCELLED, 'completed_at' => now()]);
        });

        return null;
    }

    private function complete(WorkflowInstance $instance): void
    {
        $instance->update(['status' => WorkflowInstance::STATUS_COMPLETED, 'completed_at' => now()]);

        $this->audit->record(
            'workflow.instance.completed',
            resourceType: 'WorkflowInstance',
            resourceId: $instance->id,
            tenantId: $instance->tenant_id,
            applicationId: $instance->application_id,
            correlationId: $instance->correlation_id,
            causationId: $instance->causation_id,
        );
    }

    private function fail(WorkflowInstance $instance, string $code, string $message): void
    {
        $instance->update(['status' => WorkflowInstance::STATUS_FAILED, 'completed_at' => now()]);

        $this->audit->record(
            'workflow.instance.failed',
            resourceType: 'WorkflowInstance',
            resourceId: $instance->id,
            newValue: ['error_code' => $code, 'error_message' => $this->sanitizeError($message)],
            tenantId: $instance->tenant_id,
            applicationId: $instance->application_id,
            correlationId: $instance->correlation_id,
            causationId: $instance->causation_id,
        );
    }

    private function resolveNextStep(WorkflowStep $current, array $context): ?WorkflowStep
    {
        foreach ($current->outgoingTransitions as $transition) {
            if ($this->transitionMatches($transition, $context)) {
                return $transition->toStep;
            }
        }

        return null;
    }

    private function transitionMatches(WorkflowTransition $transition, array $context): bool
    {
        if (! $transition->condition) {
            return true;
        }

        try {
            return $this->conditionEvaluator->evaluate($transition->condition, $context);
        } catch (\Throwable) {
            return false;
        }
    }

    private function buildContext(WorkflowInstance $instance): array
    {
        $stepOutputs = [];
        foreach ($instance->steps()->with('step')->get() as $s) {
            if ($s->step) {
                $stepOutputs[$s->step->step_key] = $s->output ?? [];
            }
        }

        return [
            'trigger' => $instance->trigger_payload ?? [],
            'tenant' => ['id' => $instance->tenant_id],
            'application' => ['id' => $instance->application_id],
            'instance' => ['id' => $instance->id, 'status' => $instance->status],
            'steps' => $stepOutputs,
        ];
    }

    /** Never log secrets or full exception traces into instance_steps/audit. */
    private function sanitizeError(string $message): string
    {
        return Str::limit(preg_replace('/[A-Za-z0-9+\/=]{32,}/', '[redacted]', $message), 500);
    }

    private function systemProducer(WorkflowInstance $instance): \App\Models\ServiceAccount
    {
        return \App\Models\ServiceAccount::query()->firstOrCreate(
            ['name' => 'cgo-workflow-engine'],
            ['status' => \App\Models\ServiceAccount::STATUS_ACTIVE],
        );
    }
}
