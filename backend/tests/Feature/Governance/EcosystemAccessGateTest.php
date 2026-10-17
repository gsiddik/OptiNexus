<?php

namespace Tests\Feature\Governance;

use App\Models\AuditLog;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\OrchestrationPermissionSeeder;
use Database\Seeders\OrchestrationRoleSeeder;
use Database\Seeders\PlatformIntegrationPermissionSeeder;
use Database\Seeders\PlatformIntegrationRoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesCommercialFixtures;
use Tests\Concerns\CreatesGovernanceFixtures;
use Tests\TestCase;

/**
 * Audit R07/A24: tenant users and tenant administrators must be refused by the BACKEND at the ecosystem portal and at the
 * ecosystem admin API, whatever the front end shows and however their tenant-scoped roles are composed.
 */
class EcosystemAccessGateTest extends TestCase
{
    use CreatesCommercialFixtures, CreatesGovernanceFixtures, RefreshDatabase;

    private function tenantAdmin(array $permissionKeys = ['cgo.tenant.view', 'cgo.user.view', 'cgo.role.view']): array
    {
        $this->seedGovernanceBaseline();

        $tenant = $this->makeTenant();
        $role = $this->makeTenantRole($tenant, $permissionKeys);
        $user = User::factory()->create(['password' => Hash::make('s3cret-pass'), 'status' => User::STATUS_ACTIVE]);
        $this->attachUserToTenant($user, $tenant, $role, isTenantAdmin: true);

        return [$user, $tenant];
    }

    public function test_tenant_admin_is_refused_by_every_admin_route_family_even_with_matching_tenant_permissions(): void
    {
        [$user, $tenant] = $this->tenantAdmin();
        Sanctum::actingAs($user);

        foreach (["/api/v1/tenants/{$tenant->id}", '/api/v1/users', '/api/v1/roles', '/api/v1/auth/context'] as $path) {
            $this->getJson($path)
                ->assertStatus(403)
                ->assertJsonPath('error.code', 'ECOSYSTEM_ACCESS_REQUIRED');
        }

        $this->assertDatabaseHas('audit_logs', ['action' => 'authorization.ecosystem_denied', 'actor_user_id' => $user->id]);
    }

    public function test_a_tenant_scoped_role_that_carries_the_ecosystem_permission_stays_inert(): void
    {
        [$user, $tenant] = $this->tenantAdmin([Permission::ECOSYSTEM_ACCESS, 'cgo.tenant.view']);
        Sanctum::actingAs($user);

        $this->getJson("/api/v1/tenants/{$tenant->id}")
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'ECOSYSTEM_ACCESS_REQUIRED');
    }

    public function test_a_platform_operator_keeps_access(): void
    {
        $this->actingAsSuperAdmin();
        $tenant = $this->makeTenant();

        $this->getJson("/api/v1/tenants/{$tenant->id}")->assertOk();
        $this->getJson('/api/v1/auth/context')->assertOk();
    }

    public function test_an_operator_role_without_the_ecosystem_permission_is_refused(): void
    {
        $this->seedGovernanceBaseline();
        $role = Role::query()->where('code', 'AUDITOR')->whereNull('tenant_id')->firstOrFail();
        $role->permissions()->detach(Permission::query()->where('permission_key', Permission::ECOSYSTEM_ACCESS)->value('id'));
        $user = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $user->userRoles()->create(['role_id' => $role->id, 'tenant_id' => null]);
        Sanctum::actingAs($user);

        $this->getJson('/api/v1/tenants')->assertStatus(403)->assertJsonPath('error.code', 'ECOSYSTEM_ACCESS_REQUIRED');
    }

    public function test_every_seeded_system_role_carries_the_ecosystem_permission(): void
    {
        $this->seedCommercialBaseline();
        $this->seed(OrchestrationPermissionSeeder::class);
        $this->seed(OrchestrationRoleSeeder::class);
        $this->seed(PlatformIntegrationPermissionSeeder::class);
        $this->seed(PlatformIntegrationRoleSeeder::class);

        $withoutIt = Role::query()->where('role_type', Role::TYPE_SYSTEM)->whereNull('tenant_id')
            ->whereDoesntHave('permissions', fn ($q) => $q->where('permission_key', Permission::ECOSYSTEM_ACCESS))
            ->pluck('code')->all();

        $this->assertSame([], $withoutIt);
    }

    public function test_portal_login_refuses_a_tenant_admin_and_issues_no_token(): void
    {
        [$user] = $this->tenantAdmin();

        $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 's3cret-pass'])
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'ECOSYSTEM_ACCESS_REQUIRED')
            ->assertJsonMissingPath('data.token');

        $this->assertSame(0, $user->tokens()->count());
        $this->assertTrue(AuditLog::query()->where('action', 'auth.login_failed')->where('actor_user_id', $user->id)
            ->get()->contains(fn ($log) => ($log->metadata['reason'] ?? null) === 'ecosystem_access_required'));
    }

    public function test_portal_login_still_works_for_a_platform_operator(): void
    {
        $this->seedGovernanceBaseline();
        $operator = User::factory()->create(['password' => Hash::make('s3cret-pass'), 'status' => User::STATUS_ACTIVE]);
        $role = Role::query()->where('code', 'PLATFORM_ADMIN')->whereNull('tenant_id')->firstOrFail();
        $operator->userRoles()->create(['role_id' => $role->id, 'tenant_id' => null]);

        $this->postJson('/api/v1/auth/login', ['email' => $operator->email, 'password' => 's3cret-pass'])
            ->assertOk()
            ->assertJsonStructure(['data' => ['token']]);
    }

    public function test_a_token_can_always_be_revoked_even_when_ecosystem_access_is_gone(): void
    {
        [$user] = $this->tenantAdmin();
        Sanctum::actingAs($user);

        $this->postJson('/api/v1/auth/logout')->assertOk();
    }

    public function test_every_human_authenticated_route_is_behind_the_ecosystem_gate(): void
    {
        $open = [];

        foreach (Route::getRoutes() as $route) {
            $middleware = $route->gatherMiddleware();

            if (! in_array('auth:sanctum', $middleware, true)) {
                continue;
            }

            if (! in_array('ecosystem', $middleware, true) && $route->getName() !== 'api.v1.auth.logout') {
                $open[] = implode('|', $route->methods()).' '.$route->uri();
            }
        }

        $this->assertSame([], $open, 'A human-authenticated route is reachable without ecosystem access.');
    }

    public function test_the_migration_attaches_the_permission_to_global_system_roles_only_and_is_repeatable(): void
    {
        $this->seedGovernanceBaseline();
        [, $tenant] = $this->tenantAdmin();
        $tenantRole = $this->makeTenantRole($tenant, []);

        $permission = Permission::query()->where('permission_key', Permission::ECOSYSTEM_ACCESS)->firstOrFail();
        $permission->roles()->detach();
        $permission->delete();

        $custom = Role::create([
            'tenant_id' => null, 'name' => 'Custom operator', 'code' => 'CUSTOM-'.Str::upper(Str::random(4)),
            'role_type' => Role::TYPE_SYSTEM, 'is_system' => false, 'status' => Role::STATUS_ACTIVE,
        ]);

        $migration = require database_path('migrations/2026_10_17_100001_add_ecosystem_access_permission.php');
        $migration->up();
        $migration->up();

        $holders = Role::query()->whereHas('permissions', fn ($q) => $q->where('permission_key', Permission::ECOSYSTEM_ACCESS))->pluck('id');

        $this->assertTrue($holders->contains($custom->id));
        $this->assertTrue($holders->contains(Role::query()->where('code', 'PLATFORM_SUPERADMIN')->value('id')));
        $this->assertFalse($holders->contains($tenantRole->id));
        $this->assertSame(1, Permission::query()->where('permission_key', Permission::ECOSYSTEM_ACCESS)->count());
    }
}
