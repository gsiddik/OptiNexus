<?php

namespace Tests\Feature\Orchestration;

use App\Models\ApprovalDefinition;
use App\Models\ApprovalRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesGovernanceFixtures;
use Tests\Concerns\CreatesOrchestrationFixtures;
use Tests\TestCase;

class ApprovalManagementTest extends TestCase
{
    use CreatesGovernanceFixtures, CreatesOrchestrationFixtures, RefreshDatabase;

    /**
     * Deciding on an approval requires BOTH the RBAC permission
     * cgo.approval.approve/reject/return AND being the resolved approver
     * for the active level - a plain factory user has neither role nor
     * permission, so approver/stranger fixtures need this to isolate the
     * "wrong approver" business-logic checks from the RBAC gate itself.
     */
    private function grantApprovalPermission(User $user): void
    {
        $this->seedOrchestrationBaseline();
        $role = \App\Models\Role::where('code', 'APPROVAL_ADMIN')->whereNull('tenant_id')->firstOrFail();
        $user->userRoles()->create(['role_id' => $role->id, 'tenant_id' => null]);
    }

    private function makeDefinition(array $levels): string
    {
        $this->actingAsOrchestrationRole('APPROVAL_ADMIN');

        $id = $this->postJson('/api/v1/approval-definitions', [
            'definition_code' => 'AD-'.uniqid(),
            'name' => 'Test Approval',
            'rule_type' => ApprovalDefinition::RULE_SEQUENTIAL,
            'self_approval_allowed' => false,
            'levels' => $levels,
        ])->assertStatus(201)->json('data.id');

        $this->putJson("/api/v1/approval-definitions/{$id}", ['status' => ApprovalDefinition::STATUS_ACTIVE])
            ->assertStatus(200);

        return $id;
    }

    public function test_sequential_approval_requires_all_levels_in_order(): void
    {
        $requester = $this->actingAsOrchestrationRole('APPROVAL_ADMIN');
        $approver1 = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $this->grantApprovalPermission($approver1);
        $approver2 = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $this->grantApprovalPermission($approver2);

        $definitionId = $this->makeDefinition([
            ['level_order' => 1, 'name' => 'L1', 'approver_type' => 'USER', 'approver_reference' => $approver1->id],
            ['level_order' => 2, 'name' => 'L2', 'approver_type' => 'USER', 'approver_reference' => $approver2->id],
        ]);

        $definition = ApprovalDefinition::find($definitionId);
        $request = app(\App\Services\Approval\ApprovalService::class)->createRequest($definition, [], requestedBy: $requester)[0];

        Sanctum::actingAs($approver1);
        $this->postJson("/api/v1/approval-requests/{$request->id}/approve", ['comment' => 'ok'])
            ->assertStatus(200)
            ->assertJsonPath('data.status', ApprovalRequest::STATUS_IN_PROGRESS);

        Sanctum::actingAs($approver2);
        $this->postJson("/api/v1/approval-requests/{$request->id}/approve", ['comment' => 'ok'])
            ->assertStatus(200)
            ->assertJsonPath('data.status', ApprovalRequest::STATUS_APPROVED);
    }

    public function test_self_approval_is_denied_when_not_allowed(): void
    {
        $requester = $this->actingAsOrchestrationRole('APPROVAL_ADMIN');

        $definitionId = $this->makeDefinition([
            ['level_order' => 1, 'name' => 'L1', 'approver_type' => 'USER', 'approver_reference' => $requester->id],
        ]);
        $definition = ApprovalDefinition::find($definitionId);
        $request = app(\App\Services\Approval\ApprovalService::class)->createRequest($definition, [], requestedBy: $requester)[0];

        Sanctum::actingAs($requester);
        $this->postJson("/api/v1/approval-requests/{$request->id}/approve")
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'SELF_APPROVAL_DENIED');
    }

    public function test_reject_terminates_the_request(): void
    {
        $requester = $this->actingAsOrchestrationRole('APPROVAL_ADMIN');
        $approver = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $this->grantApprovalPermission($approver);

        $definitionId = $this->makeDefinition([
            ['level_order' => 1, 'name' => 'L1', 'approver_type' => 'USER', 'approver_reference' => $approver->id],
        ]);
        $definition = ApprovalDefinition::find($definitionId);
        $request = app(\App\Services\Approval\ApprovalService::class)->createRequest($definition, [], requestedBy: $requester)[0];

        Sanctum::actingAs($approver);
        $this->postJson("/api/v1/approval-requests/{$request->id}/reject", ['comment' => 'no'])
            ->assertStatus(200)
            ->assertJsonPath('data.status', ApprovalRequest::STATUS_REJECTED);

        // A decision cannot be replayed once the request is terminal.
        $this->postJson("/api/v1/approval-requests/{$request->id}/approve")
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'APPROVAL_NOT_PENDING');
    }

    public function test_unauthorized_approver_cannot_decide(): void
    {
        $requester = $this->actingAsOrchestrationRole('APPROVAL_ADMIN');
        $approver = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $this->grantApprovalPermission($approver);
        $stranger = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $this->grantApprovalPermission($stranger);

        $definitionId = $this->makeDefinition([
            ['level_order' => 1, 'name' => 'L1', 'approver_type' => 'USER', 'approver_reference' => $approver->id],
        ]);
        $definition = ApprovalDefinition::find($definitionId);
        $request = app(\App\Services\Approval\ApprovalService::class)->createRequest($definition, [], requestedBy: $requester)[0];

        Sanctum::actingAs($stranger);
        $this->postJson("/api/v1/approval-requests/{$request->id}/approve")
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'APPROVAL_NOT_ALLOWED');
    }

    public function test_decision_is_immutable_once_recorded(): void
    {
        $requester = $this->actingAsOrchestrationRole('APPROVAL_ADMIN');
        $approver = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $this->grantApprovalPermission($approver);

        $definitionId = $this->makeDefinition([
            ['level_order' => 1, 'name' => 'L1', 'approver_type' => 'USER', 'approver_reference' => $approver->id],
        ]);
        $definition = ApprovalDefinition::find($definitionId);
        [$request] = app(\App\Services\Approval\ApprovalService::class)->createRequest($definition, [], requestedBy: $requester);

        Sanctum::actingAs($approver);
        $this->postJson("/api/v1/approval-requests/{$request->id}/approve");

        $decision = \App\Models\ApprovalDecision::first();
        $this->expectException(\RuntimeException::class);
        $decision->update(['comment' => 'tampered']);
    }
}
