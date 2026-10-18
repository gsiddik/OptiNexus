<?php

namespace Tests\Feature\Orchestration;

use App\Models\ApprovalDefinition;
use App\Models\FeatureFlag;
use App\Models\ServiceAccount;
use App\Models\User;
use App\Services\Approval\ApprovalService;
use App\Services\Integration\SsrfProtectedException;
use App\Services\Integration\SsrfSafeHttpClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Passport\ClientRepository;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
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

    /**
     * The only check that resolves a real public name. Needs working DNS: where there is none (an offline sandbox) it is skipped, never passed;
     * the deterministic equivalents are in SsrfSafeHttpClientTest. Run it on a host with DNS: `php artisan test --group=network`.
     */
    #[Group('network')]
    public function test_ssrf_client_allows_a_public_host(): void
    {
        if (gethostbyname('example.com') === 'example.com') {
            $this->markTestSkipped('NOT_RUN: this host cannot resolve public DNS names; run `php artisan test --group=network` where DNS works.');
        }

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
        $approverInB = $this->makeOperatorWith();
        $this->attachUserToTenant($approverInB, $tenantB, $roleInTenantB);

        $definitionId = $this->postJson('/api/v1/approval-definitions', [
            'definition_code' => 'AD-CROSS-TENANT', 'name' => 'Tenant A approval', 'tenant_id' => $tenantA->id,
            'rule_type' => 'SEQUENTIAL', 'self_approval_allowed' => false,
            'levels' => [['level_order' => 1, 'name' => 'L1', 'approver_type' => 'TENANT_ROLE', 'approver_reference' => 'B-APPROVER']],
        ])->json('data.id');
        $this->putJson("/api/v1/approval-definitions/{$definitionId}", ['status' => 'ACTIVE']);

        $definition = ApprovalDefinition::find($definitionId);
        [$request] = app(ApprovalService::class)->createRequest($definition, [], tenantId: $tenantA->id);

        Sanctum::actingAs($approverInB);
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
        $userInB = $this->makeOperatorWith();
        $this->attachUserToTenant($userInB, $tenantB, $roleInB);

        Sanctum::actingAs($userInB);
        $this->postJson("/api/v1/workflows/{$id}/execute")
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'TENANT_ACCESS_DENIED');
    }

    private function issueServiceAccountTokenLike(array $scopes): string
    {
        $client = app(ClientRepository::class)->createClientCredentialsGrantClient('test-access-'.uniqid());
        ServiceAccount::create(['oauth_client_id' => $client->id, 'name' => 'test-access', 'status' => 'ACTIVE']);

        return $this->postJson('/api/v1/oauth/token', [
            'grant_type' => 'client_credentials', 'client_id' => $client->id, 'client_secret' => $client->plainSecret,
            'scope' => implode(' ', $scopes),
        ])->assertStatus(200)->json('access_token');
    }
}
