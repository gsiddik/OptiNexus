<?php

namespace Tests\Feature\Orchestration;

use App\Models\Policy;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesGovernanceFixtures;
use Tests\Concerns\CreatesOrchestrationFixtures;
use Tests\TestCase;

class PolicyManagementTest extends TestCase
{
    use CreatesGovernanceFixtures, CreatesOrchestrationFixtures, RefreshDatabase;

    public function test_policy_admin_can_create_and_activate_a_policy(): void
    {
        $this->actingAsOrchestrationRole('POLICY_ADMIN');

        $response = $this->postJson('/api/v1/policies', [
            'policy_code' => 'PT-1',
            'name' => 'Test Policy',
            'policy_type' => Policy::TYPE_AUTHORIZATION,
            'effect' => Policy::EFFECT_ALLOW,
            'condition_definition' => ['field' => 'context.amount', 'operator' => 'lte', 'value' => 100],
        ])->assertStatus(201);

        $response->assertJsonPath('data.status', Policy::STATUS_DRAFT);
        $id = $response->json('data.id');

        $this->postJson("/api/v1/policies/{$id}/activate")
            ->assertStatus(200)
            ->assertJsonPath('data.status', Policy::STATUS_ACTIVE);
    }

    public function test_unsafe_condition_definition_is_rejected(): void
    {
        $this->actingAsOrchestrationRole('POLICY_ADMIN');

        $this->postJson('/api/v1/policies', [
            'policy_code' => 'PT-2',
            'name' => 'Unsafe',
            'policy_type' => Policy::TYPE_AUTHORIZATION,
            'effect' => Policy::EFFECT_ALLOW,
            'condition_definition' => ['field' => 'a; DROP TABLE users;', 'operator' => 'eq', 'value' => 1],
        ])->assertStatus(422)
            ->assertJsonPath('error.code', 'POLICY_INVALID');
    }

    public function test_unknown_operator_is_rejected(): void
    {
        $this->actingAsOrchestrationRole('POLICY_ADMIN');

        $this->postJson('/api/v1/policies', [
            'policy_code' => 'PT-3',
            'name' => 'Bad operator',
            'policy_type' => Policy::TYPE_AUTHORIZATION,
            'effect' => Policy::EFFECT_ALLOW,
            'condition_definition' => ['field' => 'context.amount', 'operator' => 'eval', 'value' => 1],
        ])->assertStatus(422)
            ->assertJsonPath('error.code', 'POLICY_INVALID');
    }

    public function test_simulate_matches_condition_without_side_effects(): void
    {
        $this->actingAsOrchestrationRole('POLICY_ADMIN');

        $id = $this->postJson('/api/v1/policies', [
            'policy_code' => 'PT-4',
            'name' => 'Threshold',
            'policy_type' => Policy::TYPE_COMMERCIAL,
            'effect' => Policy::EFFECT_DENY,
            'condition_definition' => ['field' => 'context.amount', 'operator' => 'gt', 'value' => 1000],
        ])->json('data.id');
        $this->postJson("/api/v1/policies/{$id}/activate");

        $this->postJson('/api/v1/policies/simulate', [
            'policy_type' => Policy::TYPE_COMMERCIAL,
            'context' => ['amount' => 5000],
        ])->assertStatus(200)->assertJsonPath('data.decision', 'DENY');

        $this->postJson('/api/v1/policies/simulate', [
            'policy_type' => Policy::TYPE_COMMERCIAL,
            'context' => ['amount' => 10],
        ])->assertStatus(200)->assertJsonPath('data.decision', 'NEUTRAL');

        // Simulation never persists anything.
        $this->assertDatabaseCount('policies', 1);
    }

    public function test_explicit_deny_wins_over_allow_regardless_of_priority(): void
    {
        $this->actingAsOrchestrationRole('POLICY_ADMIN');

        $allowId = $this->postJson('/api/v1/policies', [
            'policy_code' => 'PT-ALLOW', 'name' => 'Allow', 'policy_type' => Policy::TYPE_AUTHORIZATION,
            'priority' => 999, 'effect' => Policy::EFFECT_ALLOW,
            'condition_definition' => ['field' => 'context.x', 'operator' => 'eq', 'value' => 1],
        ])->json('data.id');
        $this->postJson("/api/v1/policies/{$allowId}/activate");

        $denyId = $this->postJson('/api/v1/policies', [
            'policy_code' => 'PT-DENY', 'name' => 'Deny', 'policy_type' => Policy::TYPE_AUTHORIZATION,
            'priority' => 1, 'effect' => Policy::EFFECT_DENY,
            'condition_definition' => ['field' => 'context.x', 'operator' => 'eq', 'value' => 1],
        ])->json('data.id');
        $this->postJson("/api/v1/policies/{$denyId}/activate");

        $this->postJson('/api/v1/policies/simulate', [
            'policy_type' => Policy::TYPE_AUTHORIZATION,
            'context' => ['x' => 1],
        ])->assertStatus(200)->assertJsonPath('data.decision', 'DENY');
    }

    public function test_user_without_permission_cannot_create_policy(): void
    {
        $this->seedOrchestrationBaseline();
        $user = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        Sanctum::actingAs($user);

        $this->postJson('/api/v1/policies', [
            'policy_code' => 'PT-5', 'name' => 'X', 'policy_type' => Policy::TYPE_AUTHORIZATION,
            'effect' => Policy::EFFECT_ALLOW, 'condition_definition' => ['field' => 'a', 'operator' => 'eq', 'value' => 1],
        ])->assertStatus(403);
    }
}
