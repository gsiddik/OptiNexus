<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesGovernanceFixtures;
use Tests\TestCase;

class RolePermissionTest extends TestCase
{
    use CreatesGovernanceFixtures, RefreshDatabase;

    public function test_permission_registration_enforces_naming_convention_and_uniqueness(): void
    {
        $this->actingAsSuperAdmin();
        $application = $this->makeApplication();

        $this->postJson('/api/v1/permissions', [
            'application_id' => $application->id,
            'permission_key' => 'not a valid key',
            'name' => 'Bad',
        ])->assertStatus(422)->assertJsonPath('error.code', 'VALIDATION_ERROR');

        $key = $application->application_code.'.thing.view';

        $this->postJson('/api/v1/permissions', [
            'application_id' => $application->id, 'permission_key' => $key, 'name' => 'View Thing',
        ])->assertStatus(201);

        $this->postJson('/api/v1/permissions', [
            'application_id' => $application->id, 'permission_key' => $key, 'name' => 'View Thing Again',
        ])->assertStatus(422);
    }

    public function test_role_can_be_granted_and_revoked_a_permission(): void
    {
        $this->actingAsSuperAdmin();
        $tenant = $this->makeTenant();
        $role = Role::create([
            'tenant_id' => $tenant->id, 'name' => 'Custom Role', 'code' => 'CUSTOM1',
            'role_type' => Role::TYPE_TENANT, 'status' => Role::STATUS_ACTIVE,
        ]);
        $permission = Permission::query()->where('permission_key', 'cgo.user.view')->firstOrFail();

        $this->postJson("/api/v1/roles/{$role->id}/permissions/{$permission->id}")->assertStatus(201);
        $this->getJson("/api/v1/roles/{$role->id}/permissions")->assertJsonFragment(['permission_key' => 'cgo.user.view']);

        $this->deleteJson("/api/v1/roles/{$role->id}/permissions/{$permission->id}")->assertStatus(200);
        $this->getJson("/api/v1/roles/{$role->id}/permissions")->assertJsonMissing(['permission_key' => 'cgo.user.view']);
    }

    public function test_cannot_grant_a_permission_the_caller_does_not_hold(): void
    {
        $this->seedGovernanceBaseline();
        $tenant = $this->makeTenant();

        // The caller only has cgo.role.view/update on this tenant - not cgo.user.suspend.
        $limitedRole = $this->makeTenantRole($tenant, ['cgo.role.view', 'cgo.role.update', 'cgo.role.permission.grant']);
        $caller = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $this->attachUserToTenant($caller, $tenant, $limitedRole);
        Sanctum::actingAs($caller);

        $targetRole = $this->makeTenantRole($tenant, []);
        $sensitivePermission = Permission::query()->where('permission_key', 'cgo.user.suspend')->firstOrFail();

        $this->postJson("/api/v1/roles/{$targetRole->id}/permissions/{$sensitivePermission->id}")
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'PRIVILEGE_ESCALATION_DENIED');
    }

    public function test_role_can_be_cloned_with_its_permissions(): void
    {
        $this->actingAsSuperAdmin();
        $tenant = $this->makeTenant();
        $role = $this->makeTenantRole($tenant, ['cgo.user.view', 'cgo.user.update']);

        $response = $this->postJson("/api/v1/roles/{$role->id}/clone", ['name' => 'Cloned', 'code' => 'CLONED1'])
            ->assertStatus(201);

        $this->assertEquals(2, $response->json('data.permissions_count'));
    }

    public function test_system_role_cannot_be_disabled(): void
    {
        $this->actingAsSuperAdmin();
        $role = Role::query()->where('code', 'AUDITOR')->firstOrFail();

        $this->postJson("/api/v1/roles/{$role->id}/disable")
            ->assertStatus(403);
    }
}
