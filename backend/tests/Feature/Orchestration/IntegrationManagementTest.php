<?php

namespace Tests\Feature\Orchestration;

use App\Models\Integration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesGovernanceFixtures;
use Tests\Concerns\CreatesOrchestrationFixtures;
use Tests\TestCase;

class IntegrationManagementTest extends TestCase
{
    use CreatesGovernanceFixtures, CreatesOrchestrationFixtures, RefreshDatabase;

    private function makeIntegration(): string
    {
        $this->actingAsOrchestrationRole('INTEGRATION_ADMIN');
        $app = $this->cgoApplication();

        return $this->postJson('/api/v1/integrations', [
            'integration_code' => 'INT-'.uniqid(),
            'name' => 'Test Integration',
            'source_application_id' => $app->id,
            'integration_type' => Integration::TYPE_REST,
            'base_url' => 'https://example.com',
            'timeout_seconds' => 10,
        ])->assertStatus(201)->json('data.id');
    }

    public function test_credential_secret_is_never_returned_by_the_api(): void
    {
        $id = $this->makeIntegration();

        $response = $this->postJson("/api/v1/integrations/{$id}/credentials", [
            'credential_type' => 'API_KEY',
            'secret' => 'super-secret-value',
            'reference_label' => 'primary key',
        ])->assertStatus(201);

        $response->assertJsonMissing(['encrypted_secret']);
        $this->assertStringNotContainsString('super-secret-value', $response->getContent());

        $show = $this->getJson("/api/v1/integrations/{$id}")->assertStatus(200);
        $this->assertStringNotContainsString('super-secret-value', $show->getContent());
    }

    public function test_secret_is_encrypted_at_rest(): void
    {
        $id = $this->makeIntegration();
        $this->postJson("/api/v1/integrations/{$id}/credentials", [
            'credential_type' => 'API_KEY', 'secret' => 'super-secret-value', 'reference_label' => 'k',
        ]);

        $raw = \Illuminate\Support\Facades\DB::table('integration_credentials')->where('integration_id', $id)->value('encrypted_secret');
        $this->assertNotSame('super-secret-value', $raw);
        $this->assertSame('super-secret-value', \Illuminate\Support\Facades\Crypt::decryptString($raw));
    }

    public function test_rotation_leaves_exactly_one_active_credential(): void
    {
        $id = $this->makeIntegration();
        $this->postJson("/api/v1/integrations/{$id}/credentials", ['credential_type' => 'API_KEY', 'secret' => 'first', 'reference_label' => 'k1']);
        $this->postJson("/api/v1/integrations/{$id}/credentials/rotate", ['credential_type' => 'API_KEY', 'secret' => 'second', 'reference_label' => 'k2']);

        $this->assertSame(1, \App\Models\IntegrationCredential::where('integration_id', $id)->where('status', 'ACTIVE')->count());
        $this->assertSame(1, \App\Models\IntegrationCredential::where('integration_id', $id)->where('status', 'ROTATED')->count());
    }

    public function test_ssrf_protected_endpoints_are_rejected_by_test_connection(): void
    {
        $this->actingAsOrchestrationRole('INTEGRATION_ADMIN');
        $app = $this->cgoApplication();

        $id = $this->postJson('/api/v1/integrations', [
            'integration_code' => 'INT-SSRF',
            'name' => 'Local target',
            'source_application_id' => $app->id,
            'integration_type' => Integration::TYPE_REST,
            'base_url' => 'http://127.0.0.1:9999',
        ])->json('data.id');
        $this->postJson("/api/v1/integrations/{$id}/activate");

        $response = $this->postJson("/api/v1/integrations/{$id}/test")->assertStatus(200);
        $this->assertFalse($response->json('data.ok'));
    }

    public function test_user_without_permission_cannot_rotate_credentials(): void
    {
        $id = $this->makeIntegration();

        $noPermUser = \App\Models\User::factory()->create(['status' => \App\Models\User::STATUS_ACTIVE]);
        \Laravel\Sanctum\Sanctum::actingAs($noPermUser);

        $this->postJson("/api/v1/integrations/{$id}/credentials/rotate", [
            'credential_type' => 'API_KEY', 'secret' => 'x', 'reference_label' => 'x',
        ])->assertStatus(403);
    }
}
