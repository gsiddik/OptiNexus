<?php

namespace Tests\Feature\Orchestration;

use App\Models\Workflow;
use App\Models\WorkflowInstance;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesGovernanceFixtures;
use Tests\Concerns\CreatesOrchestrationFixtures;
use Tests\TestCase;

class WorkflowManagementTest extends TestCase
{
    use CreatesGovernanceFixtures, CreatesOrchestrationFixtures, RefreshDatabase;

    private function linearWorkflowPayload(string $code): array
    {
        return [
            'workflow_code' => $code,
            'name' => 'Linear Test Workflow',
            'trigger_type' => Workflow::TRIGGER_MANUAL,
            'steps' => [
                ['step_key' => 'start', 'step_type' => 'START'],
                ['step_key' => 'task1', 'step_type' => 'TASK'],
                ['step_key' => 'end', 'step_type' => 'END'],
            ],
            'transitions' => [
                ['from_step_key' => 'start', 'to_step_key' => 'task1'],
                ['from_step_key' => 'task1', 'to_step_key' => 'end'],
            ],
        ];
    }

    public function test_workflow_admin_can_create_validate_and_activate(): void
    {
        $this->actingAsOrchestrationRole('WORKFLOW_ADMIN');

        $id = $this->postJson('/api/v1/workflows', $this->linearWorkflowPayload('WF-1'))
            ->assertStatus(201)
            ->json('data.id');

        $this->postJson("/api/v1/workflows/{$id}/validate")
            ->assertStatus(200)
            ->assertJsonPath('data.valid', true);

        $this->postJson("/api/v1/workflows/{$id}/activate")
            ->assertStatus(200)
            ->assertJsonPath('data.status', Workflow::STATUS_ACTIVE);
    }

    public function test_workflow_definition_requires_exactly_one_start_and_an_end(): void
    {
        $this->actingAsOrchestrationRole('WORKFLOW_ADMIN');

        $this->postJson('/api/v1/workflows', [
            'workflow_code' => 'WF-BAD',
            'name' => 'No end step',
            'trigger_type' => Workflow::TRIGGER_MANUAL,
            'steps' => [
                ['step_key' => 'start', 'step_type' => 'START'],
                ['step_key' => 'start2', 'step_type' => 'START'],
            ],
            'transitions' => [],
        ])->assertStatus(422)->assertJsonPath('error.code', 'WORKFLOW_INVALID');
    }

    public function test_manual_execute_runs_to_completion(): void
    {
        $this->actingAsOrchestrationRole('WORKFLOW_ADMIN');

        $id = $this->postJson('/api/v1/workflows', $this->linearWorkflowPayload('WF-2'))->json('data.id');
        $this->postJson("/api/v1/workflows/{$id}/activate");

        $response = $this->postJson("/api/v1/workflows/{$id}/execute", ['payload' => ['foo' => 'bar']])
            ->assertStatus(201);

        $response->assertJsonPath('data.status', WorkflowInstance::STATUS_COMPLETED);
    }

    public function test_duplicate_idempotency_key_does_not_create_a_second_instance(): void
    {
        $this->actingAsOrchestrationRole('WORKFLOW_ADMIN');

        $id = $this->postJson('/api/v1/workflows', $this->linearWorkflowPayload('WF-3'))->json('data.id');
        $this->postJson("/api/v1/workflows/{$id}/activate");

        $first = $this->postJson("/api/v1/workflows/{$id}/execute", ['idempotency_key' => 'idem-1'])->json('data.id');
        $second = $this->postJson("/api/v1/workflows/{$id}/execute", ['idempotency_key' => 'idem-1'])->json('data.id');

        $this->assertSame($first, $second);
        $this->assertSame(1, WorkflowInstance::where('workflow_id', $id)->count());
    }

    public function test_cannot_execute_an_inactive_workflow(): void
    {
        $this->actingAsOrchestrationRole('WORKFLOW_ADMIN');

        $id = $this->postJson('/api/v1/workflows', $this->linearWorkflowPayload('WF-4'))->json('data.id');
        // Never activated - stays DRAFT.

        $this->postJson("/api/v1/workflows/{$id}/execute")
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'WORKFLOW_NOT_ACTIVE');
    }

    public function test_condition_step_branches_on_trigger_payload(): void
    {
        $this->actingAsOrchestrationRole('WORKFLOW_ADMIN');

        $id = $this->postJson('/api/v1/workflows', [
            'workflow_code' => 'WF-5',
            'name' => 'Branching workflow',
            'trigger_type' => Workflow::TRIGGER_MANUAL,
            'steps' => [
                ['step_key' => 'start', 'step_type' => 'START'],
                ['step_key' => 'check', 'step_type' => 'CONDITION'],
                ['step_key' => 'high', 'step_type' => 'END'],
                ['step_key' => 'low', 'step_type' => 'END'],
            ],
            'transitions' => [
                ['from_step_key' => 'start', 'to_step_key' => 'check'],
                ['from_step_key' => 'check', 'to_step_key' => 'high', 'condition' => ['field' => 'trigger.amount', 'operator' => 'gt', 'value' => 100], 'sort_order' => 1],
                ['from_step_key' => 'check', 'to_step_key' => 'low', 'sort_order' => 2],
            ],
        ])->json('data.id');
        $this->postJson("/api/v1/workflows/{$id}/activate");

        $instanceId = $this->postJson("/api/v1/workflows/{$id}/execute", ['payload' => ['amount' => 500]])->json('data.id');
        $lastStep = \App\Models\WorkflowInstanceStep::where('workflow_instance_id', $instanceId)->orderByDesc('id')->first();
        $this->assertSame('high', $lastStep->step->step_key);
    }

    public function test_user_without_permission_cannot_activate_workflow(): void
    {
        $admin = $this->actingAsOrchestrationRole('WORKFLOW_ADMIN');
        $id = $this->postJson('/api/v1/workflows', $this->linearWorkflowPayload('WF-6'))->json('data.id');

        // Switch to a user with no workflow permissions at all.
        $noPermUser = \App\Models\User::factory()->create(['status' => \App\Models\User::STATUS_ACTIVE]);
        \Laravel\Sanctum\Sanctum::actingAs($noPermUser);

        $this->postJson("/api/v1/workflows/{$id}/activate")->assertStatus(403);
    }
}
