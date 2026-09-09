<?php

namespace Tests\Feature\Commercial;

use App\Models\UsageEvent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesCommercialFixtures;
use Tests\Concerns\CreatesGovernanceFixtures;
use Tests\TestCase;

class UsageEventTest extends TestCase
{
    use CreatesCommercialFixtures, CreatesGovernanceFixtures, RefreshDatabase;

    private function tenantWithApplication(): array
    {
        $this->seedCommercialBaseline();
        $application = $this->makeApplication(['application_code' => 'optifleet']);
        $tenant = $this->makeTenant();
        $this->grantApplicationAccess(User::factory()->create(), $tenant, $application);

        return [$tenant, $application];
    }

    public function test_service_account_can_submit_usage_for_its_own_application(): void
    {
        [$tenant, $application] = $this->tenantWithApplication();
        $token = $this->issueServiceAccountToken($application->id, ['usage.write']);

        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/usage-events', [
                'tenant_id' => $tenant->id,
                'application_code' => 'optifleet',
                'meter_key' => 'vehicles',
                'quantity' => 125,
            ])->assertStatus(201)->assertJsonPath('data.quantity', '125.0000');

        $this->assertSame(1, UsageEvent::query()->count());
    }

    public function test_duplicate_usage_with_the_same_idempotency_key_produces_no_duplicate_record(): void
    {
        [$tenant, $application] = $this->tenantWithApplication();
        $token = $this->issueServiceAccountToken($application->id, ['usage.write']);

        $payload = [
            'tenant_id' => $tenant->id,
            'application_code' => 'optifleet',
            'meter_key' => 'vehicles',
            'quantity' => 125,
            'idempotency_key' => 'evt-2026-09-vehicle-count',
        ];

        $first = $this->withHeader('Authorization', "Bearer {$token}")->postJson('/api/v1/usage-events', $payload)->assertStatus(201);
        $second = $this->withHeader('Authorization', "Bearer {$token}")->postJson('/api/v1/usage-events', $payload)->assertStatus(200);

        $this->assertSame($first->json('data.id'), $second->json('data.id'));
        $this->assertSame(1, UsageEvent::query()->count());
    }

    public function test_service_account_scoped_to_a_different_application_cannot_submit_usage(): void
    {
        [$tenant, $application] = $this->tenantWithApplication();
        $otherApplication = $this->makeApplication(['application_code' => 'other-app']);
        $token = $this->issueServiceAccountToken($otherApplication->id, ['usage.write']);

        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/usage-events', [
                'tenant_id' => $tenant->id,
                'application_code' => 'optifleet',
                'meter_key' => 'vehicles',
                'quantity' => 10,
            ])->assertStatus(403)->assertJsonPath('error.code', 'APPLICATION_ACCESS_DENIED');
    }

    public function test_usage_for_an_application_not_assigned_to_the_tenant_is_rejected(): void
    {
        $this->seedCommercialBaseline();
        $application = $this->makeApplication(['application_code' => 'optifleet']);
        $tenant = $this->makeTenant(); // application never attached to this tenant
        $token = $this->issueServiceAccountToken($application->id, ['usage.write']);

        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/usage-events', [
                'tenant_id' => $tenant->id,
                'application_code' => 'optifleet',
                'meter_key' => 'vehicles',
                'quantity' => 10,
            ])->assertStatus(403)->assertJsonPath('error.code', 'APPLICATION_ACCESS_DENIED');
    }

    public function test_usage_ingestion_without_a_service_account_token_is_rejected(): void
    {
        [$tenant] = $this->tenantWithApplication();

        $this->postJson('/api/v1/usage-events', [
            'tenant_id' => $tenant->id,
            'application_code' => 'optifleet',
            'meter_key' => 'vehicles',
            'quantity' => 10,
        ])->assertStatus(401);
    }

    public function test_usage_query_requires_permission_and_supports_tenant_scoping(): void
    {
        [$tenant, $application] = $this->tenantWithApplication();
        $token = $this->issueServiceAccountToken($application->id, ['usage.write']);
        $this->withHeader('Authorization', "Bearer {$token}")->postJson('/api/v1/usage-events', [
            'tenant_id' => $tenant->id, 'application_code' => 'optifleet', 'meter_key' => 'vehicles', 'quantity' => 42,
        ])->assertStatus(201);

        $this->actingAsCommercialRole('BILLING_ADMIN');

        $this->getJson("/api/v1/tenants/{$tenant->id}/usage")
            ->assertStatus(200)
            ->assertJsonCount(1, 'data');
    }
}
