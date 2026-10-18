<?php

namespace Tests\Feature\Governance;

use App\Models\Application;
use App\Models\AuditLog;
use App\Models\Capability;
use App\Models\Permission;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesGovernanceFixtures;
use Tests\TestCase;

/** Audit A02: additive, versioned catalog registration with the explicit delegable flag. */
class ManifestImportTest extends TestCase
{
    use CreatesGovernanceFixtures, RefreshDatabase;

    private Application $app1;

    protected function setUp(): void
    {
        parent::setUp();

        $this->app1 = $this->makeApplication(['application_code' => 'optiaccounting']);
    }

    private function manifest(array $overrides = []): array
    {
        return array_replace_recursive([
            'manifest_version' => '2.0',
            'revision' => 'r1',
            'capabilities' => [
                ['type' => 'MODULE', 'code' => 'accounting', 'name' => 'Accounting', 'sort_order' => 1],
                ['type' => 'FEATURE', 'code' => 'journals', 'name' => 'Journals', 'parent_code' => 'accounting'],
            ],
            'permissions' => [
                ['permission_key' => 'optiaccounting.journal.view', 'name' => 'View journals', 'capability_code' => 'journals', 'delegable' => true],
                ['permission_key' => 'optiaccounting.period.close', 'name' => 'Close periods', 'capability_code' => 'accounting', 'delegable' => false],
            ],
        ], $overrides);
    }

    private function import(array $manifest, array $flags = [])
    {
        return $this->postJson("/api/v1/applications/{$this->app1->id}/manifest", $manifest + $flags);
    }

    public function test_the_first_import_creates_the_catalog_and_records_the_revision(): void
    {
        $this->actingAsSuperAdmin();

        $this->import($this->manifest())->assertOk()
            ->assertJsonPath('data.capabilities.created', 2)
            ->assertJsonPath('data.permissions.created', 2)
            ->assertJsonPath('data.dry_run', false);

        $journals = Capability::query()->where('code', 'journals')->firstOrFail();
        $this->assertSame(Capability::query()->where('code', 'accounting')->value('id'), $journals->parent_id);
        $view = Permission::query()->where('permission_key', 'optiaccounting.journal.view')->firstOrFail();
        $this->assertTrue($view->delegable);
        $this->assertSame($journals->id, $view->capability_id);
        $this->assertFalse(Permission::query()->where('permission_key', 'optiaccounting.period.close')->value('delegable'));
        $this->assertSame('r1', $this->app1->refresh()->metadata['manifest']['revision']);
        $this->assertTrue(AuditLog::query()->where('action', 'application.manifest_imported')->exists());
    }

    public function test_a_repeat_import_changes_nothing_and_a_dry_run_changes_nothing_at_all(): void
    {
        $this->actingAsSuperAdmin();
        $this->import($this->manifest())->assertOk();

        $this->import($this->manifest())->assertOk()
            ->assertJsonPath('data.capabilities.unchanged', 2)->assertJsonPath('data.permissions.unchanged', 2);

        $changed = $this->manifest(['revision' => 'r2']);
        $changed['permissions'][] = ['permission_key' => 'optiaccounting.budget.view', 'name' => 'Budgets', 'delegable' => true];
        $this->import($changed, ['dry_run' => true])->assertOk()
            ->assertJsonPath('data.dry_run', true)->assertJsonPath('data.permissions.created', 1);

        $this->assertNull(Permission::query()->where('permission_key', 'optiaccounting.budget.view')->first());
        $this->assertSame('r1', $this->app1->refresh()->metadata['manifest']['revision']);
    }

    public function test_delegable_is_narrowed_at_once_but_widened_only_on_request(): void
    {
        $this->actingAsSuperAdmin();
        $this->import($this->manifest())->assertOk();

        $flipped = $this->manifest();
        $flipped['permissions'][0]['delegable'] = false;
        $flipped['permissions'][1]['delegable'] = true;
        $result = $this->import($flipped)->assertOk();

        $result->assertJsonPath('data.delegable_widening_pending', ['optiaccounting.period.close']);
        $this->assertFalse(Permission::query()->where('permission_key', 'optiaccounting.journal.view')->value('delegable'));
        $this->assertFalse(Permission::query()->where('permission_key', 'optiaccounting.period.close')->value('delegable'));

        $this->import($flipped, ['allow_delegable_widening' => true])->assertOk();
        $this->assertTrue(Permission::query()->where('permission_key', 'optiaccounting.period.close')->value('delegable'));
    }

    public function test_nothing_is_deleted_or_re_enabled_and_unlisted_permissions_are_reported(): void
    {
        $this->actingAsSuperAdmin();
        $this->import($this->manifest())->assertOk();
        Capability::query()->where('code', 'journals')->update(['status' => Capability::STATUS_DISABLED]);
        Permission::query()->where('permission_key', 'optiaccounting.period.close')->update(['status' => Permission::STATUS_DEPRECATED]);

        $smaller = $this->manifest(['revision' => 'r2']);
        array_pop($smaller['permissions']);
        $this->import($smaller)->assertOk()->assertJsonPath('data.unlisted_permissions', ['optiaccounting.period.close']);

        $this->assertSame(Capability::STATUS_DISABLED, Capability::query()->where('code', 'journals')->value('status'));
        $this->assertSame(Permission::STATUS_DEPRECATED, Permission::query()->where('permission_key', 'optiaccounting.period.close')->value('status'));
    }

    public function test_keys_outside_the_applications_namespace_reject_the_whole_manifest(): void
    {
        $this->actingAsSuperAdmin();

        foreach (['cgo.tenant.update', 'optifleet.vehicle.view', 'optiaccounting'] as $key) {
            $bad = $this->manifest();
            $bad['permissions'][] = ['permission_key' => $key, 'name' => 'x', 'delegable' => true];
            $this->import($bad)->assertStatus(422);
        }

        $this->assertSame(0, Permission::query()->where('application_id', $this->app1->id)->count());
        $this->assertSame(0, Capability::query()->where('application_id', $this->app1->id)->count());
    }

    public function test_unknown_parents_unknown_capabilities_and_another_applications_permissions_are_rejected(): void
    {
        $this->actingAsSuperAdmin();
        $other = $this->makeApplication(['application_code' => 'optifleet']);
        $this->makePermission($other, 'optiaccounting.stolen.view');

        $orphan = $this->manifest();
        $orphan['capabilities'][1]['parent_code'] = 'nope';
        $this->import($orphan)->assertStatus(422);

        $unknownCapability = $this->manifest();
        $unknownCapability['permissions'][0]['capability_code'] = 'missing';
        $this->import($unknownCapability)->assertStatus(422);

        $clash = $this->manifest();
        $clash['permissions'][] = ['permission_key' => 'optiaccounting.stolen.view', 'name' => 'x'];
        $this->import($clash)->assertStatus(422);
        $this->assertSame($other->id, Permission::query()->where('permission_key', 'optiaccounting.stolen.view')->value('application_id'));
    }

    public function test_unsupported_versions_and_missing_revisions_are_rejected(): void
    {
        $this->actingAsSuperAdmin();

        $this->import($this->manifest(['manifest_version' => '1.0']))->assertStatus(422);
        $noRevision = $this->manifest();
        unset($noRevision['revision']);
        $this->import($noRevision)->assertStatus(422);
    }

    public function test_it_needs_the_catalog_permissions_and_ecosystem_access(): void
    {
        $this->actingAsOperatorWith(['cgo.application.update']);
        $this->import($this->manifest())->assertStatus(403)->assertJsonPath('error.code', 'UNAUTHORIZED');

        $tenant = $this->makeTenant();
        $role = $this->makeTenantRole($tenant, ['cgo.application.update', 'cgo.capability.create', 'cgo.permission.create']);
        $admin = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $this->attachUserToTenant($admin, $tenant, $role, isTenantAdmin: true);
        Sanctum::actingAs($admin);
        $this->import($this->manifest())->assertStatus(403)->assertJsonPath('error.code', 'ECOSYSTEM_ACCESS_REQUIRED');
    }

    public function test_the_command_registers_validates_and_imports_without_a_signed_in_operator(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'manifest');
        $payload = $this->manifest() + ['application' => ['application_code' => 'optitest', 'name' => 'OptiTest', 'frontend_url' => 'https://test.example']];
        $payload['permissions'] = [['permission_key' => 'optitest.thing.view', 'name' => 'View', 'delegable' => true]];
        $payload['capabilities'] = [];
        File::put($file, json_encode($payload));

        $this->artisan("ecosystem:import-manifest optitest {$file}")->assertFailed();
        $this->artisan("ecosystem:import-manifest optitest {$file} --create-application --dry-run")->assertSuccessful();
        $this->assertNull(Application::query()->where('application_code', 'optitest')->first());

        $this->artisan("ecosystem:import-manifest optitest {$file} --create-application")->assertSuccessful();
        $this->assertSame(Application::STATUS_PUBLISHED, Application::query()->where('application_code', 'optitest')->value('status'));
        $this->assertTrue((bool) Permission::query()->where('permission_key', 'optitest.thing.view')->value('delegable'));

        File::put($file, '{not json');
        $this->artisan("ecosystem:import-manifest optitest {$file}")->assertFailed();
        File::delete($file);
    }
}
