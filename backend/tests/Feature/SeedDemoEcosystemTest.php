<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** The demo seed acts as the operator of a local installation and nothing else. */
class SeedDemoEcosystemTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_refuses_outside_local_installations_unless_allowed(): void
    {
        $this->app['env'] = 'production';
        putenv('ECOSYSTEM_ALLOW_DEMO_SEED');
        $this->artisan('ecosystem:seed-demo')->expectsOutputToContain('local installations')->assertFailed();
    }

    public function test_it_needs_the_operator_credentials(): void
    {
        putenv('CGO_SUPERADMIN_EMAIL=');
        putenv('CGO_SUPERADMIN_PASSWORD=');
        $this->artisan('ecosystem:seed-demo')->expectsOutputToContain('CGO_SUPERADMIN_EMAIL')->assertFailed();
    }

    public function test_it_stops_when_a_requested_application_is_not_in_the_catalog(): void
    {
        $operator = User::query()->create(['email' => 'seed.operator@test.local', 'name' => 'Seed Operator', 'password' => 'Seed-operator-passw0rd!', 'status' => User::STATUS_ACTIVE]);
        $role = Role::query()->where('code', 'SUPERADMIN')->whereNull('tenant_id')->first();
        if ($role === null) {
            $this->markTestSkipped('The system roles are not seeded in this database.');
        }
        $operator->userRoles()->create(['role_id' => $role->id, 'tenant_id' => null]);
        putenv('CGO_SUPERADMIN_EMAIL=seed.operator@test.local');
        putenv('CGO_SUPERADMIN_PASSWORD=Seed-operator-passw0rd!');

        $this->artisan('ecosystem:seed-demo', ['--applications' => 'no_such_app'])->expectsOutputToContain('not in the catalog')->assertFailed();
    }
}
