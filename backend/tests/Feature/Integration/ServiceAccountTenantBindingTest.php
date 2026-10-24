<?php

namespace Tests\Feature\Integration;

use App\Models\Application;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\CreatesGovernanceFixtures;
use Tests\Concerns\IssuesServiceAccountTokens;
use Tests\TestCase;

/** Wave 0 (audit 2026-10-24): M2M routes that take tenant_id from the body honour the service account's tenant binding. */
class ServiceAccountTenantBindingTest extends TestCase
{
    use CreatesGovernanceFixtures, IssuesServiceAccountTokens, RefreshDatabase;

    private Application $application;

    private Tenant $tenant;

    private Tenant $otherTenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->application = $this->makeApplication(['application_code' => 'binding-test']);
        $this->tenant = $this->makeTenant();
        $this->otherTenant = $this->makeTenant();
    }

    public static function tenantScopedRoutes(): array
    {
        return [
            'entitlements/check' => ['/api/v1/entitlements/check', 'entitlement.check'],
            'authorization/check' => ['/api/v1/authorization/check', 'authorization.check'],
            'access/evaluate' => ['/api/v1/access/evaluate', 'access.evaluate'],
            'events' => ['/api/v1/events', 'event.write'],
            'usage-events' => ['/api/v1/usage-events', 'usage.write'],
        ];
    }

    private function body(Tenant $tenant): array
    {
        return [
            'tenant_id' => $tenant->id,
            'user_id' => User::factory()->create()->id,
            'application_code' => 'binding-test',
            'entitlement_key' => 'binding-test',
            'permission' => 'binding-test.res.act',
        ];
    }

    #[DataProvider('tenantScopedRoutes')]
    public function test_a_tenant_bound_service_account_cannot_name_another_tenant(string $uri, string $scope): void
    {
        [$token] = $this->issueServiceAccountTokenFor($this->application->id, [$scope], tenantId: $this->tenant->id);

        $this->withHeader('Authorization', "Bearer {$token}")->postJson($uri, $this->body($this->otherTenant))
            ->assertForbidden()->assertJsonPath('error.code', 'TENANT_MISMATCH');
    }

    #[DataProvider('tenantScopedRoutes')]
    public function test_a_platform_service_account_is_not_tenant_bound(string $uri, string $scope): void
    {
        [$token] = $this->issueServiceAccountTokenFor($this->application->id, [$scope]);

        $response = $this->withHeader('Authorization', "Bearer {$token}")->postJson($uri, $this->body($this->otherTenant));

        $this->assertNotSame('TENANT_MISMATCH', $response->json('error.code'));
    }

    public function test_a_missing_tenant_is_filled_with_the_bound_tenant(): void
    {
        [$token] = $this->issueServiceAccountTokenFor($this->application->id, ['entitlement.check'], tenantId: $this->tenant->id);
        $body = $this->body($this->tenant);
        unset($body['tenant_id']);

        $this->withHeader('Authorization', "Bearer {$token}")->postJson('/api/v1/entitlements/check', $body)->assertOk();
    }

    public function test_the_own_tenant_is_still_accepted(): void
    {
        [$token] = $this->issueServiceAccountTokenFor($this->application->id, ['entitlement.check'], tenantId: $this->tenant->id);

        $this->withHeader('Authorization', "Bearer {$token}")->postJson('/api/v1/entitlements/check', $this->body($this->tenant))->assertOk();
    }
}
