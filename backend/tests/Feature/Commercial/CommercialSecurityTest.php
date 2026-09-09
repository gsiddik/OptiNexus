<?php

namespace Tests\Feature\Commercial;

use App\Models\AuditLog;
use App\Models\Price;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesCommercialFixtures;
use Tests\Concerns\CreatesGovernanceFixtures;
use Tests\TestCase;

class CommercialSecurityTest extends TestCase
{
    use CreatesCommercialFixtures, CreatesGovernanceFixtures, RefreshDatabase;

    private function subscribedTenant(string $tenantScopedRolePermission = 'cgo.subscription.view'): array
    {
        $this->seedCommercialBaseline();
        $customer = $this->makeCustomer();
        $tenant = $this->makeTenant($customer);
        $product = $this->makeProduct();
        $plan = $this->makePlan($product);
        $this->makeFlatPrice($plan);

        $admin = $this->actingAsCommercialRole('SUBSCRIPTION_ADMIN');
        $subscriptionId = $this->postJson('/api/v1/subscriptions', [
            'customer_id' => $customer->id, 'tenant_id' => $tenant->id, 'plan_id' => $plan->id,
        ])->json('data.id');
        $this->postJson("/api/v1/subscriptions/{$subscriptionId}/activate")->assertStatus(200);

        return [$tenant, $subscriptionId];
    }

    public function test_tenant_scoped_user_cannot_view_a_subscription_in_another_tenant(): void
    {
        [$tenantA, $subscriptionId] = $this->subscribedTenant();
        $tenantB = $this->makeTenant();

        $role = $this->makeTenantRole($tenantB, ['cgo.subscription.view']);
        $user = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $this->attachUserToTenant($user, $tenantB, $role);
        Sanctum::actingAs($user);

        // IDOR: a valid subscription id from a tenant the caller has no
        // grant in must be denied, not merely filtered.
        $this->getJson("/api/v1/subscriptions/{$subscriptionId}")
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'TENANT_ACCESS_DENIED');
    }

    public function test_tenant_scoped_user_can_view_their_own_tenants_subscription(): void
    {
        [$tenantA, $subscriptionId] = $this->subscribedTenant();

        $role = $this->makeTenantRole($tenantA, ['cgo.subscription.view']);
        $user = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $this->attachUserToTenant($user, $tenantA, $role);
        Sanctum::actingAs($user);

        $this->getJson("/api/v1/subscriptions/{$subscriptionId}")->assertStatus(200);
    }

    public function test_read_only_permission_cannot_be_used_to_cancel_a_subscription(): void
    {
        [, $subscriptionId] = $this->subscribedTenant();
        $tenant = \App\Models\Subscription::findOrFail($subscriptionId)->tenant;

        // Privilege escalation attempt: this role only has view, not cancel.
        $role = $this->makeTenantRole($tenant, ['cgo.subscription.view']);
        $user = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $this->attachUserToTenant($user, $tenant, $role);
        Sanctum::actingAs($user);

        $this->postJson("/api/v1/subscriptions/{$subscriptionId}/cancel")->assertStatus(403);
    }

    public function test_cross_tenant_billing_and_invoice_access_is_denied(): void
    {
        $this->actingAsCommercialRole('SUBSCRIPTION_ADMIN');
        $customer = $this->makeCustomer();
        $tenantA = $this->makeTenant($customer);
        $product = $this->makeProduct();
        $plan = $this->makePlan($product);
        $this->makeFlatPrice($plan, ['unit_amount' => '500000.00']);
        $subscriptionId = $this->postJson('/api/v1/subscriptions', [
            'customer_id' => $customer->id, 'tenant_id' => $tenantA->id, 'plan_id' => $plan->id,
        ])->json('data.id');
        $this->postJson("/api/v1/subscriptions/{$subscriptionId}/activate")->assertStatus(200);

        $this->actingAsCommercialRole('BILLING_ADMIN');
        $billingId = $this->postJson('/api/v1/billing-runs', ['subscription_id' => $subscriptionId])->json('data.results.0.id');

        $tenantB = $this->makeTenant();
        $role = $this->makeTenantRole($tenantB, ['cgo.billing.view']);
        $user = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $this->attachUserToTenant($user, $tenantB, $role);
        Sanctum::actingAs($user);

        $this->getJson("/api/v1/billings/{$billingId}")
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'TENANT_ACCESS_DENIED');
    }

    public function test_denied_authorization_attempt_is_audited(): void
    {
        [, $subscriptionId] = $this->subscribedTenant();
        $tenant = \App\Models\Subscription::findOrFail($subscriptionId)->tenant;
        $role = $this->makeTenantRole($tenant, ['cgo.subscription.view']);
        $user = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $this->attachUserToTenant($user, $tenant, $role);
        Sanctum::actingAs($user);

        $this->postJson("/api/v1/subscriptions/{$subscriptionId}/cancel")->assertStatus(403);

        $this->assertTrue(AuditLog::query()->where('action', 'authorization.denied')->where('actor_user_id', $user->id)->exists());
    }

    public function test_pricing_override_ignores_a_client_supplied_approval_status(): void
    {
        $this->actingAsCommercialRole('COMMERCIAL_ADMIN');
        $product = $this->makeProduct();
        $plan = $this->makePlan($product);
        $tenant = $this->makeTenant();

        // Mass-assignment attempt: try to force immediate approval through
        // an unvalidated field on the override endpoint.
        $response = $this->postJson('/api/v1/pricing/overrides', [
            'plan_id' => $plan->id,
            'tenant_id' => $tenant->id,
            'price_type' => Price::TYPE_FLAT,
            'currency' => 'IDR',
            'unit_amount' => '1.00',
            'billing_interval' => 'MONTHLY',
            'approval_status' => 'APPROVED',
            'approved_by' => 'attacker-controlled-id',
        ])->assertStatus(201);

        $this->assertSame('PENDING_APPROVAL', $response->json('data.approval_status'));
        $this->assertNull($response->json('data.approved_by'));
    }

    public function test_service_account_with_only_commercial_read_scope_cannot_submit_usage(): void
    {
        $this->seedCommercialBaseline();
        $application = $this->makeApplication(['application_code' => 'optifleet']);
        $tenant = $this->makeTenant();
        $this->grantApplicationAccess(User::factory()->create(), $tenant, $application);

        $token = $this->issueServiceAccountToken($application->id, ['commercial.read']);

        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/usage-events', [
                'tenant_id' => $tenant->id, 'application_code' => 'optifleet', 'meter_key' => 'vehicles', 'quantity' => 1,
            ])->assertStatus(403);
    }

    public function test_revoked_service_account_cannot_read_commercial_context(): void
    {
        $this->seedCommercialBaseline();
        $application = $this->makeApplication(['application_code' => 'optifleet']);
        $tenant = $this->makeTenant();
        $token = $this->issueServiceAccountToken($application->id, ['commercial.read']);
        \App\Models\ServiceAccount::query()->update(['status' => \App\Models\ServiceAccount::STATUS_REVOKED]);

        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson("/api/v1/tenants/{$tenant->id}/commercial-context")
            ->assertStatus(403);
    }

    public function test_unauthenticated_requests_to_admin_commercial_endpoints_are_rejected(): void
    {
        $this->getJson('/api/v1/products')->assertStatus(401);
        $this->getJson('/api/v1/subscriptions')->assertStatus(401);
        $this->getJson('/api/v1/billings')->assertStatus(401);
        $this->getJson('/api/v1/invoices')->assertStatus(401);
    }
}
