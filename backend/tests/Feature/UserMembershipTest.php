<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesGovernanceFixtures;
use Tests\TestCase;

class UserMembershipTest extends TestCase
{
    use CreatesGovernanceFixtures, RefreshDatabase;

    public function test_user_lifecycle_transitions(): void
    {
        $this->actingAsSuperAdmin();

        $id = $this->postJson('/api/v1/users', ['name' => 'New User', 'email' => 'new.user@example.com'])
            ->assertStatus(201)->assertJsonPath('data.status', User::STATUS_INVITED)->json('data.id');

        $this->postJson("/api/v1/users/{$id}/activate")->assertJsonPath('data.status', User::STATUS_ACTIVE);
        $this->postJson("/api/v1/users/{$id}/suspend")->assertJsonPath('data.status', User::STATUS_SUSPENDED);
        $this->postJson("/api/v1/users/{$id}/disable")->assertJsonPath('data.status', User::STATUS_DISABLED);
    }

    public function test_tenant_membership_is_required_before_application_or_role_assignment(): void
    {
        $this->actingAsSuperAdmin();
        $tenant = $this->makeTenant();
        $application = $this->makeApplication();
        $user = User::factory()->create(['status' => User::STATUS_ACTIVE]);

        // No membership yet: granting application access must fail.
        $this->postJson("/api/v1/users/{$user->id}/applications/{$application->id}", ['tenant_id' => $tenant->id])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'TENANT_ACCESS_DENIED');

        $this->postJson("/api/v1/users/{$user->id}/tenants/{$tenant->id}")->assertStatus(201);

        $this->postJson("/api/v1/users/{$user->id}/applications/{$application->id}", ['tenant_id' => $tenant->id])
            ->assertStatus(201);

        $this->getJson("/api/v1/users/{$user->id}/applications")
            ->assertJsonFragment(['id' => $application->id]);
    }

    public function test_role_assignment_requires_membership_and_respects_escalation_guard(): void
    {
        $this->actingAsSuperAdmin();
        $tenant = $this->makeTenant();
        $role = $this->makeTenantRole($tenant, ['cgo.user.view']);
        $user = User::factory()->create(['status' => User::STATUS_ACTIVE]);

        $this->postJson("/api/v1/users/{$user->id}/roles/{$role->id}", ['tenant_id' => $tenant->id])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'TENANT_ACCESS_DENIED');

        $this->postJson("/api/v1/users/{$user->id}/tenants/{$tenant->id}")->assertStatus(201);
        $this->postJson("/api/v1/users/{$user->id}/roles/{$role->id}", ['tenant_id' => $tenant->id])
            ->assertStatus(201);

        $this->getJson("/api/v1/users/{$user->id}/roles?tenant_id={$tenant->id}")
            ->assertJsonFragment(['code' => $role->code]);
    }

    public function test_effective_permissions_endpoint_reflects_granted_role(): void
    {
        $this->actingAsSuperAdmin();
        $tenant = $this->makeTenant();
        $role = $this->makeTenantRole($tenant, ['cgo.user.view', 'cgo.user.update']);
        $user = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $this->attachUserToTenant($user, $tenant, $role);

        $this->getJson("/api/v1/users/{$user->id}/effective-permissions?tenant_id={$tenant->id}")
            ->assertStatus(200)
            ->assertJsonFragment(['permission_key' => 'cgo.user.view', 'scope' => 'TENANT']);
    }

    public function test_privilege_escalation_denied_when_assigning_a_role_beyond_own_permissions(): void
    {
        $this->seedGovernanceBaseline();
        $tenant = $this->makeTenant();

        $limitedRole = $this->makeTenantRole($tenant, ['cgo.user.role.assign', 'cgo.user.view']);
        $caller = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $this->attachUserToTenant($caller, $tenant, $limitedRole);
        Sanctum::actingAs($caller);

        $broadRole = Role::query()->where('code', 'IAM_ADMIN')->firstOrFail();
        $target = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $this->attachUserToTenant($target, $tenant);

        // IAM_ADMIN carries many permissions the caller does not hold themselves.
        $this->postJson("/api/v1/users/{$target->id}/roles/{$broadRole->id}", ['tenant_id' => $tenant->id])
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'PRIVILEGE_ESCALATION_DENIED');
    }
}
