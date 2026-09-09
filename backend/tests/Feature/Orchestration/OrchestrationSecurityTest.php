<?php

namespace Tests\Feature\Orchestration;

use App\Models\FeatureFlag;
use App\Models\User;
use App\Services\Integration\SsrfProtectedException;
use App\Services\Integration\SsrfSafeHttpClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\CreatesGovernanceFixtures;
use Tests\Concerns\CreatesOrchestrationFixtures;
use Tests\TestCase;

class OrchestrationSecurityTest extends TestCase
{
    use CreatesGovernanceFixtures, CreatesOrchestrationFixtures, RefreshDatabase;

    #[DataProvider('blockedUrls')]
    public function test_ssrf_client_blocks_private_and_reserved_targets(string $url): void
    {
        $this->expectException(SsrfProtectedException::class);
        app(SsrfSafeHttpClient::class)->validateUrl($url);
    }

    public static function blockedUrls(): array
    {
        return [
            'loopback' => ['http://127.0.0.1/'],
            'localhost literal' => ['http://localhost/'],
            'link-local metadata' => ['http://169.254.169.254/latest/meta-data/'],
            'private class A' => ['http://10.0.0.5/'],
            'private class C' => ['http://192.168.1.1/'],
            'non-http scheme' => ['ftp://example.com/'],
        ];
    }

    public function test_ssrf_client_allows_a_public_host(): void
    {
        [$host, $port, $ip] = app(SsrfSafeHttpClient::class)->validateUrl('https://example.com/webhook');
        $this->assertSame('example.com', $host);
        $this->assertSame(443, $port);
        $this->assertNotEmpty($ip);
    }

    public function test_feature_flag_override_cannot_target_a_tenant_unrelated_to_the_flags_application(): void
    {
        $this->actingAsOrchestrationRole('FEATURE_FLAG_ADMIN');
        $app = $this->makeApplication();
        $unrelatedTenant = $this->makeTenant(); // never assigned to $app

        $flagId = $this->postJson('/api/v1/feature-flags', [
            'flag_key' => 'ff.scoped.'.uniqid(), 'name' => 'Scoped', 'application_id' => $app->id,
            'flag_type' => FeatureFlag::TYPE_BOOLEAN, 'default_value' => false,
        ])->json('data.id');
        $this->postJson("/api/v1/feature-flags/{$flagId}/activate");

        $this->postJson("/api/v1/feature-flags/{$flagId}/overrides", [
            'scope_type' => 'TENANT', 'scope_id' => $unrelatedTenant->id, 'value' => true,
        ])->assertStatus(422);
    }

    public function test_approval_decision_across_tenant_boundary_is_denied(): void
    {
        $this->actingAsOrchestrationRole('APPROVAL_ADMIN');
        $tenantA = $this->makeTenant();
        $tenantB = $this->makeTenant();

        // Holds the approver role and cgo.approval.approve permission, but
        // only scoped to tenant B - the route's permission middleware
        // resolves the target request's tenant (A) and rejects before the
        // approval service's own approver-resolution logic even runs,
        // demonstrating tenant isolation at two independent layers.
        $roleInTenantB = $this->makeTenantRole($tenantB, ['cgo.approval.approve'], ['code' => 'B-APPROVER']);
        $approverInB = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $this->attachUserToTenant($approverInB, $tenantB, $roleInTenantB);

        $definitionId = $this->postJson('/api/v1/approval-definitions', [
            'definition_code' => 'AD-CROSS-TENANT', 'name' => 'Tenant A approval', 'tenant_id' => $tenantA->id,
            'rule_type' => 'SEQUENTIAL', 'self_approval_allowed' => false,
            'levels' => [['level_order' => 1, 'name' => 'L1', 'approver_type' => 'TENANT_ROLE', 'approver_reference' => 'B-APPROVER']],
        ])->json('data.id');
        $this->putJson("/api/v1/approval-definitions/{$definitionId}", ['status' => 'ACTIVE']);

        $definition = \App\Models\ApprovalDefinition::find($definitionId);
        [$request] = app(\App\Services\Approval\ApprovalService::class)->createRequest($definition, [], tenantId: $tenantA->id);

        \Laravel\Sanctum\Sanctum::actingAs($approverInB);
        $this->postJson("/api/v1/approval-requests/{$request->id}/approve")
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'TENANT_ACCESS_DENIED');
    }

    public function test_access_evaluate_reports_every_check_independently(): void
    {
        $this->actingAsOrchestrationRole('POLICY_ADMIN');
        $app = $this->cgoApplication();
        $tenant = $this->makeTenant();
        $user = User::factory()->create(['status' => User::STATUS_ACTIVE]);

        $token = $this->issueServiceAccountTokenLike(['access.evaluate']);

        $response = $this->withHeader('Authorization', "Bearer {$token}")->postJson('/api/v1/access/evaluate', [
            'user_id' => $user->id, 'tenant_id' => $tenant->id, 'application_code' => $app->application_code,
            'permission' => 'cgo.policy.view',
        ])->assertStatus(200);

        $response->assertJsonStructure(['data' => ['allowed', 'checks' => ['entitlement', 'permission', 'policy', 'feature_flag'], 'reason_code']]);
        $response->assertJsonPath('data.allowed', false);
        $response->assertJsonPath('data.checks.permission', false);
    }

    public function test_tenant_scoped_role_cannot_execute_another_tenants_workflow(): void
    {
        $this->actingAsOrchestrationRole('WORKFLOW_ADMIN');
        $tenantA = $this->makeTenant();
        $tenantB = $this->makeTenant();

        $id = $this->postJson('/api/v1/workflows', [
            'workflow_code' => 'SEC-WF-'.uniqid(),
            'name' => 'Tenant A workflow',
            'tenant_id' => $tenantA->id,
            'trigger_type' => 'MANUAL',
            'steps' => [
                ['step_key' => 'start', 'step_type' => 'START'],
                ['step_key' => 'end', 'step_type' => 'END'],
            ],
            'transitions' => [['from_step_key' => 'start', 'to_step_key' => 'end']],
        ])->json('data.id');
        $this->postJson("/api/v1/workflows/{$id}/activate");

        // Holds cgo.workflow.execute, but scoped only to tenant B - must
        // not be able to execute tenant A's workflow via the same route.
        $roleInB = $this->makeTenantRole($tenantB, ['cgo.workflow.execute'], ['code' => 'WF-EXECUTOR-B']);
        $userInB = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $this->attachUserToTenant($userInB, $tenantB, $roleInB);

        \Laravel\Sanctum\Sanctum::actingAs($userInB);
        $this->postJson("/api/v1/workflows/{$id}/execute")
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'TENANT_ACCESS_DENIED');
    }

    private function issueServiceAccountTokenLike(array $scopes): string
    {
        $client = app(\Laravel\Passport\ClientRepository::class)->createClientCredentialsGrantClient('test-access-'.uniqid());
        \App\Models\ServiceAccount::create(['oauth_client_id' => $client->id, 'name' => 'test-access', 'status' => 'ACTIVE']);

        return $this->postJson('/api/v1/oauth/token', [
            'grant_type' => 'client_credentials', 'client_id' => $client->id, 'client_secret' => $client->plainSecret,
            'scope' => implode(' ', $scopes),
        ])->assertStatus(200)->json('access_token');
    }
}
