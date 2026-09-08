<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesGovernanceFixtures;
use Tests\TestCase;

class TenantIsolationTest extends TestCase
{
    use CreatesGovernanceFixtures, RefreshDatabase;

    public function test_tenant_scoped_admin_cannot_read_a_different_tenant(): void
    {
        $this->seedGovernanceBaseline();

        $tenantA = $this->makeTenant();
        $tenantB = $this->makeTenant();

        $role = $this->makeTenantRole($tenantA, ['cgo.tenant.view', 'cgo.tenant.update']);

        $user = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $this->attachUserToTenant($user, $tenantA, $role, isTenantAdmin: true);

        Sanctum::actingAs($user);

        // Allowed: their own tenant.
        $this->getJson("/api/v1/tenants/{$tenantA->id}")->assertStatus(200);

        // Denied: someone else's tenant, even though the permission name matches.
        $this->getJson("/api/v1/tenants/{$tenantB->id}")
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'TENANT_ACCESS_DENIED');
    }

    public function test_effective_permissions_do_not_leak_across_tenants(): void
    {
        $this->seedGovernanceBaseline();

        $tenantA = $this->makeTenant();
        $tenantB = $this->makeTenant();
        $roleA = $this->makeTenantRole($tenantA, ['cgo.user.view']);

        $user = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $this->attachUserToTenant($user, $tenantA, $roleA);

        $authorization = app(\App\Services\AuthorizationService::class);

        $this->assertTrue($authorization->userHasPermission($user, 'cgo.user.view', $tenantA->id));
        $this->assertFalse($authorization->userHasPermission($user, 'cgo.user.view', $tenantB->id));
        $this->assertFalse($authorization->userHasPermission($user, 'cgo.user.view'));
    }

    public function test_suspended_tenant_membership_no_longer_grants_access(): void
    {
        $this->seedGovernanceBaseline();
        $tenant = $this->makeTenant();
        $role = $this->makeTenantRole($tenant, ['cgo.tenant.view']);

        $user = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $this->attachUserToTenant($user, $tenant, $role);
        $user->tenantMemberships()->where('tenant_id', $tenant->id)->update(['status' => 'SUSPENDED']);

        Sanctum::actingAs($user);

        $this->getJson("/api/v1/tenants/{$tenant->id}")
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'TENANT_ACCESS_DENIED');
    }
}
