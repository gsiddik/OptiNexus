<?php

namespace Tests\Feature;

use App\Models\Capability;
use App\Models\Entitlement;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\AuthorizationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesGovernanceFixtures;
use Tests\TestCase;

class RoleMembershipWorkflowTest extends TestCase
{
    use CreatesGovernanceFixtures, RefreshDatabase;

    public function test_tenant_role_code_is_generated_and_assignment_uses_memberships(): void
    {
        $this->actingAsSuperAdmin();
        $tenant = $this->makeTenant();
        $user = User::factory()->create(['status' => 'ACTIVE']);
        $this->attachUserToTenant($user, $tenant);
        $role = $this->postJson('/api/v1/roles', ['name' => 'Tenant Team', 'role_type' => 'TENANT', 'code' => 'UNTRUSTED'])->assertCreated();
        $id = $role->json('data.id');
        $this->assertStringStartsWith('ROL-', $role->json('data.code'));
        $this->assertNull($role->json('data.tenant_id'));
        $this->postJson("/api/v1/users/{$user->id}/roles/{$id}")->assertCreated();
        $this->assertDatabaseHas('user_roles', ['user_id' => $user->id, 'tenant_id' => $tenant->id, 'role_id' => $id]);
    }

    public function test_auto_permissions_follow_subscribed_capabilities_per_tenant(): void
    {
        $this->actingAsSuperAdmin();
        $tenant = $this->makeTenant();
        $other = $this->makeTenant();
        $app = $this->makeApplication();
        $cap = Capability::create(['application_id' => $app->id, 'code' => 'fleet.vehicles', 'name' => 'Vehicles', 'type' => 'MODULE', 'status' => 'ACTIVE']);
        $permission = Permission::create(['application_id' => $app->id, 'capability_id' => $cap->id, 'permission_key' => 'fleet.vehicles.view', 'name' => 'View Vehicles', 'status' => 'ACTIVE']);
        $role = Role::create(['name' => 'Auto Team', 'code' => 'AUTO', 'role_type' => 'TENANT', 'auto_capability_permissions' => true, 'status' => 'ACTIVE']);
        Entitlement::create(['tenant_id' => $tenant->id, 'application_id' => $app->id, 'capability_id' => $cap->id, 'entitlement_type' => 'CAPABILITY', 'entitlement_key' => $cap->code, 'source_type' => 'PLAN', 'value' => 'true', 'status' => 'ACTIVE', 'effective_from' => now()->subMinute()]);
        $resolver = app(AuthorizationService::class);
        $this->assertSame([$permission->id], $resolver->capabilityPermissions($role, $tenant->id)->pluck('id')->all());
        $this->assertEmpty($resolver->capabilityPermissions($role, $other->id));
        Entitlement::query()->update(['status' => 'REVOKED']);
        $this->assertEmpty($resolver->capabilityPermissions($role, $tenant->id));
    }

    public function test_system_matrix_is_checked_from_seeded_permissions_and_not_editable(): void
    {
        $this->actingAsSuperAdmin();
        $role = Role::where('code', 'PLATFORM_SUPERADMIN')->firstOrFail();
        $permission = Permission::where('permission_key', 'cgo.user.view')->firstOrFail();
        $this->getJson("/api/v1/roles/{$role->id}/permissions")->assertOk()->assertJsonFragment(['permission_key' => $permission->permission_key]);
        $this->deleteJson("/api/v1/roles/{$role->id}/permissions/{$permission->id}")->assertForbidden()->assertJsonPath('error.code', 'SYSTEM_ROLE_IMMUTABLE');
    }
}
