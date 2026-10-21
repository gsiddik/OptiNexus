<?php

namespace Tests\Feature;

use App\Models\Application;
use App\Models\Capability;
use App\Models\Entitlement;
use App\Models\Tenant;
use App\Models\User;
use App\Services\AuthorizationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesGovernanceFixtures;
use Tests\Concerns\IssuesServiceAccountTokens;
use Tests\TestCase;

class CapabilityRoleAccessTest extends TestCase
{
    use CreatesGovernanceFixtures, IssuesServiceAccountTokens, RefreshDatabase;

    private function entitlement(Tenant $tenant, string $applicationId, string $key, ?Capability $capability = null, array $overrides = []): Entitlement
    {
        return Entitlement::create(array_merge([
            'tenant_id' => $tenant->id, 'application_id' => $applicationId, 'capability_id' => $capability?->id,
            'entitlement_type' => $capability ? Entitlement::TYPE_CAPABILITY : Entitlement::TYPE_APPLICATION,
            'entitlement_key' => $key, 'source_type' => Entitlement::SOURCE_PLAN, 'value' => 'true',
            'status' => Entitlement::STATUS_ACTIVE, 'effective_from' => now()->subMinute(),
        ], $overrides));
    }

    private function capability(string $applicationId, string $code, ?Capability $parent = null): Capability
    {
        return Capability::create([
            'application_id' => $applicationId, 'code' => $code, 'name' => $code,
            'type' => $parent ? 'FEATURE' : 'MODULE', 'parent_id' => $parent?->id, 'status' => 'ACTIVE',
        ]);
    }

    public function test_all_subscribed_fleet_modules_and_core_permissions_reach_the_matrix_and_integration_api(): void
    {
        $this->actingAsSuperAdmin();
        $tenant = $this->makeTenant();
        $fleet = $this->makeApplication(['application_code' => 'optifleet']);
        $role = $this->makeTenantRole($tenant, [], ['role_type' => 'APPLICATION', 'application_id' => $fleet->id, 'auto_capability_permissions' => true]);
        $user = User::factory()->create(['status' => 'ACTIVE']);
        $this->attachUserToTenant($user, $tenant, $role);
        $this->grantApplicationAccess($user, $tenant, $fleet);
        $this->entitlement($tenant, $fleet->id, 'optifleet');
        $expected = [];
        foreach (['vehicle', 'inspection', 'maintenance', 'work_order', 'workshop', 'inventory', 'procurement', 'tire', 'component', 'partner', 'history', 'report', 'analytics', 'maintenance_intelligence'] as $module) {
            $capability = $this->capability($fleet->id, "optifleet.{$module}");
            $this->entitlement($tenant, $fleet->id, $capability->code, $capability);
            $expected[] = $this->makePermission($fleet, "optifleet.{$module}.view", $capability)->permission_key;
        }
        foreach (['configuration.view', 'vehicle_category.view', 'company.view', 'user.view', 'role.view', 'audit.view'] as $key) {
            $expected[] = $this->makePermission($fleet, "optifleet.{$key}")->permission_key;
        }
        $matrix = $this->getJson("/api/v1/roles/{$role->id}/permissions")->assertOk();
        $this->assertEqualsCanonicalizing($expected, array_column($matrix->json('data'), 'permission_key'));
        [$token] = $this->issueServiceAccountTokenFor($fleet->id);
        $response = $this->withHeader('Authorization', "Bearer {$token}")->postJson('/api/v1/authorization/effective-permissions', [
            'user_id' => $user->id, 'tenant_id' => $tenant->id, 'application_code' => 'optifleet',
        ])->assertOk()->assertJsonPath('data.allowed', true);
        $this->assertEqualsCanonicalizing($expected, array_column($response->json('data.permissions'), 'permission'));
    }

    public function test_application_core_excludes_unpurchased_modules_and_other_applications(): void
    {
        $tenant = $this->makeTenant();
        $fleet = $this->makeApplication(['application_code' => 'optifleet']);
        $entry = $this->makeApplication(['application_code' => 'optiaccounting']);
        $role = $this->makeTenantRole($tenant, [], ['role_type' => 'APPLICATION', 'application_id' => $fleet->id, 'auto_capability_permissions' => true]);
        $core = $this->makePermission($fleet, 'optifleet.configuration.view');
        $this->makePermission($entry, 'optiaccounting.journal.view');
        $this->entitlement($tenant, $fleet->id, 'optifleet');
        $this->entitlement($tenant, $entry->id, 'optiaccounting');
        $unbought = $this->capability($fleet->id, 'optifleet.analytics');
        $this->makePermission($fleet, 'optifleet.analytics.overview.view', $unbought);
        $this->makePermission($fleet, 'optifleet.disabled')->update(['status' => 'DISABLED']);
        $resolver = app(AuthorizationService::class);
        $this->assertSame([$core->permission_key], $resolver->capabilityPermissions($role, $tenant->id)->pluck('permission_key')->all());
        $this->assertEmpty($resolver->capabilityPermissions($role, $this->makeTenant()->id));
        $this->entitlement($tenant, $fleet->id, 'optifleet', overrides: ['source_type' => Entitlement::SOURCE_MANUAL_OVERRIDE, 'value' => 'false']);
        $this->assertEmpty($resolver->capabilityPermissions($role, $tenant->id));
    }

    public function test_parent_capability_includes_children_but_overrides_and_disabled_ancestors_deny_them(): void
    {
        $tenant = $this->makeTenant();
        $fleet = $this->makeApplication(['application_code' => 'optifleet']);
        $role = $this->makeTenantRole($tenant, [], ['auto_capability_permissions' => true]);
        $root = $this->capability($fleet->id, 'optifleet.workshop');
        $child = $this->capability($fleet->id, 'optifleet.workshop.jobs', $root);
        $permission = $this->makePermission($fleet, 'optifleet.job.view', $child);
        $this->entitlement($tenant, $fleet->id, $root->code, $root);
        $this->entitlement($tenant, $fleet->id, $child->code, $child);
        $resolver = app(AuthorizationService::class);
        $this->assertSame([$permission->id], $resolver->capabilityPermissions($role, $tenant->id)->pluck('id')->all());
        $this->entitlement($tenant, $fleet->id, $root->code, $root, ['source_type' => Entitlement::SOURCE_MANUAL_OVERRIDE, 'value' => 'false']);
        $this->assertEmpty($resolver->capabilityPermissions($role, $tenant->id));
        Entitlement::query()->where('source_type', Entitlement::SOURCE_MANUAL_OVERRIDE)->delete();
        Entitlement::query()->where('capability_id', $child->id)->delete();
        $this->assertSame([$permission->id], $resolver->capabilityPermissions($role, $tenant->id)->pluck('id')->all());
        $root->update(['status' => 'DISABLED']);
        $this->assertEmpty($resolver->capabilityPermissions($role, $tenant->id));
    }

    public function test_code_based_overrides_resolve_without_capability_ids_and_never_include_governance_permissions(): void
    {
        $this->seedGovernanceBaseline();
        $tenant = $this->makeTenant();
        $fleet = $this->makeApplication(['application_code' => 'optifleet']);
        $role = $this->makeTenantRole($tenant, [], ['auto_capability_permissions' => true]);
        $module = $this->capability($fleet->id, 'optifleet.inspection');
        $permission = $this->makePermission($fleet, 'optifleet.inspection.view', $module);
        $this->entitlement($tenant, $fleet->id, $module->code, $module, ['capability_id' => null]);
        $cgo = Application::query()->where('application_code', 'cgo')->firstOrFail();
        $this->entitlement($tenant, $cgo->id, 'cgo');

        $this->assertSame([$permission->permission_key], app(AuthorizationService::class)
            ->capabilityPermissions($role, $tenant->id)->pluck('permission_key')->all());
    }

    public function test_customized_roles_remain_explicit_and_inactive_tenants_receive_no_automatic_permissions(): void
    {
        $tenant = $this->makeTenant();
        $fleet = $this->makeApplication(['application_code' => 'optifleet']);
        $core = $this->makePermission($fleet, 'optifleet.configuration.view');
        $role = $this->makeTenantRole($tenant, [], ['auto_capability_permissions' => false]);
        $user = User::factory()->create(['status' => 'ACTIVE']);
        $this->attachUserToTenant($user, $tenant, $role);
        $this->entitlement($tenant, $fleet->id, 'optifleet');
        $resolver = app(AuthorizationService::class);
        $this->assertFalse($resolver->userHasPermission($user, $core->permission_key, $tenant->id, $fleet->id));
        $role->update(['auto_capability_permissions' => true]);
        $this->assertTrue($resolver->userHasPermission($user, $core->permission_key, $tenant->id, $fleet->id));
        $tenant->update(['status' => Tenant::STATUS_SUSPENDED]);
        $this->assertEmpty($resolver->capabilityPermissions($role, $tenant->id));
    }
}
