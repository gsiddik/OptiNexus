<?php

namespace Tests\Feature\Orchestration;

use App\Models\FeatureFlag;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesGovernanceFixtures;
use Tests\Concerns\CreatesOrchestrationFixtures;
use Tests\TestCase;

class FeatureFlagManagementTest extends TestCase
{
    use CreatesGovernanceFixtures, CreatesOrchestrationFixtures, RefreshDatabase;

    private function makeFlag(bool $default = false): string
    {
        $this->actingAsOrchestrationRole('FEATURE_FLAG_ADMIN');

        $id = $this->postJson('/api/v1/feature-flags', [
            'flag_key' => 'ff.'.uniqid(),
            'name' => 'Test Flag',
            'flag_type' => FeatureFlag::TYPE_BOOLEAN,
            'default_value' => $default,
        ])->assertStatus(201)->json('data.id');

        $this->postJson("/api/v1/feature-flags/{$id}/activate")->assertStatus(200);

        return $id;
    }

    public function test_precedence_user_beats_tenant_beats_application_beats_default(): void
    {
        $id = $this->makeFlag(false);
        $tenant = $this->makeTenant();
        $user = \App\Models\User::factory()->create();

        $evaluate = fn () => $this->postJson('/api/v1/feature-flags/evaluate', [
            'flag_key' => FeatureFlag::find($id)->flag_key, 'tenant_id' => $tenant->id, 'user_id' => $user->id,
        ])->json('data');

        $this->assertFalse($evaluate()['enabled']);

        $this->postJson("/api/v1/feature-flags/{$id}/overrides", ['scope_type' => 'TENANT', 'scope_id' => $tenant->id, 'value' => true]);
        $this->assertTrue($evaluate()['enabled']);
        $this->assertSame('TENANT', $evaluate()['source']);

        $this->postJson("/api/v1/feature-flags/{$id}/overrides", ['scope_type' => 'USER', 'scope_id' => $user->id, 'value' => false]);
        $result = $evaluate();
        $this->assertFalse($result['enabled']);
        $this->assertSame('USER', $result['source']);
    }

    public function test_evaluate_falls_back_to_default_with_no_overrides(): void
    {
        $id = $this->makeFlag(true);
        $flagKey = FeatureFlag::find($id)->flag_key;

        $response = $this->postJson('/api/v1/feature-flags/evaluate', ['flag_key' => $flagKey])->assertStatus(200);
        $response->assertJsonPath('data.enabled', true)->assertJsonPath('data.source', 'GLOBAL_DEFAULT');
    }

    public function test_inactive_flag_always_evaluates_disabled(): void
    {
        $id = $this->makeFlag(true);
        $this->postJson("/api/v1/feature-flags/{$id}/deactivate")->assertStatus(200);

        $flagKey = FeatureFlag::find($id)->flag_key;
        $this->postJson('/api/v1/feature-flags/evaluate', ['flag_key' => $flagKey])
            ->assertStatus(200)
            ->assertJsonPath('data.enabled', false)
            ->assertJsonPath('data.source', 'FLAG_INACTIVE');
    }

    public function test_unknown_flag_key_returns_not_found(): void
    {
        $this->actingAsOrchestrationRole('FEATURE_FLAG_ADMIN');
        $this->postJson('/api/v1/feature-flags/evaluate', ['flag_key' => 'ff.does.not.exist'])
            ->assertStatus(404)
            ->assertJsonPath('error.code', 'FEATURE_FLAG_NOT_FOUND');
    }

    public function test_typed_value_mismatch_is_rejected(): void
    {
        $this->actingAsOrchestrationRole('FEATURE_FLAG_ADMIN');

        $this->postJson('/api/v1/feature-flags', [
            'flag_key' => 'ff.typed', 'name' => 'Typed', 'flag_type' => FeatureFlag::TYPE_BOOLEAN, 'default_value' => 'not-a-bool',
        ])->assertStatus(422);
    }

    public function test_user_without_permission_cannot_create_flag(): void
    {
        $this->seedOrchestrationBaseline();
        $user = \App\Models\User::factory()->create();
        \Laravel\Sanctum\Sanctum::actingAs($user);

        $this->postJson('/api/v1/feature-flags', [
            'flag_key' => 'ff.denied', 'name' => 'X', 'flag_type' => FeatureFlag::TYPE_BOOLEAN, 'default_value' => false,
        ])->assertStatus(403);
    }
}
