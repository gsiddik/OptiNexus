<?php

namespace Database\Seeders;

use App\Models\Application;
use App\Models\Capability;
use App\Models\Customer;
use App\Models\Permission;
use App\Models\Role;
use App\Models\ServiceAccount;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use App\Models\UserApplicationAccess;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;
use Laravel\Passport\ClientRepository;

/**
 * Seeds a realistic end-to-end example of the governance model: a customer
 * with a tenant, the OptiFleet application registered with a capability
 * hierarchy and permissions, a tenant admin user, and an OptiFleet service
 * account - everything the integration simulation and manual QA need.
 */
class DemoDataSeeder extends Seeder
{
    public function run(): void
    {
        $customer = Customer::query()->updateOrCreate(
            ['customer_code' => 'ACME'],
            [
                'legal_name' => 'Acme Logistics Pte Ltd',
                'business_name' => 'Acme Logistics',
                'email' => 'ops@acme.example',
                'status' => Customer::STATUS_ACTIVE,
            ],
        );

        $tenant = Tenant::query()->updateOrCreate(
            ['tenant_code' => 'ACME-SG'],
            [
                'customer_id' => $customer->id,
                'name' => 'Acme Singapore',
                'region' => 'ap-southeast-1',
                'timezone' => 'Asia/Singapore',
                'currency' => 'SGD',
                'language' => 'en',
                'status' => Tenant::STATUS_ACTIVE,
            ],
        );

        $optifleet = Application::query()->updateOrCreate(
            ['application_code' => 'optifleet'],
            [
                'name' => 'OptiFleet',
                'description' => 'Fleet operations and vehicle maintenance management.',
                'owner' => 'OptiFleet Team',
                'version' => '1.0.0',
                'status' => Application::STATUS_PUBLISHED,
                'frontend_url' => 'https://optifleet.example.com',
                'backend_url' => 'https://api.optifleet.example.com',
            ],
        );

        if (! $tenant->applications()->where('applications.id', $optifleet->id)->exists()) {
            $tenant->applications()->attach($optifleet->id, ['id' => (string) Str::uuid(), 'status' => 'ACTIVE']);
        }

        $module = Capability::query()->updateOrCreate(
            ['application_id' => $optifleet->id, 'code' => 'fleet_module'],
            ['parent_id' => null, 'type' => Capability::TYPE_MODULE, 'name' => 'Fleet Management', 'sort_order' => 1, 'status' => Capability::STATUS_ACTIVE],
        );

        $feature = Capability::query()->updateOrCreate(
            ['application_id' => $optifleet->id, 'code' => 'vehicle_feature'],
            ['parent_id' => $module->id, 'type' => Capability::TYPE_FEATURE, 'name' => 'Vehicle Records', 'sort_order' => 1, 'status' => Capability::STATUS_ACTIVE],
        );

        $permissions = [
            'optifleet.vehicle.view' => 'View vehicles',
            'optifleet.vehicle.update' => 'Update vehicles',
            'optifleet.workorder.approve' => 'Approve work orders',
        ];

        foreach ($permissions as $key => $name) {
            Permission::query()->updateOrCreate(
                ['permission_key' => $key],
                ['application_id' => $optifleet->id, 'capability_id' => $feature->id, 'name' => $name, 'status' => Permission::STATUS_ACTIVE],
            );
        }

        $tenantAdminRole = Role::query()->updateOrCreate(
            ['code' => 'TENANT_ADMIN', 'tenant_id' => $tenant->id],
            ['name' => 'Tenant Administrator', 'role_type' => Role::TYPE_TENANT, 'is_system' => true, 'status' => Role::STATUS_ACTIVE],
        );
        $tenantAdminRole->permissions()->syncWithoutDetaching(
            Permission::query()->whereIn('permission_key', ['cgo.tenant.view', 'cgo.user.view'])->pluck('id')
                ->mapWithKeys(fn ($id) => [$id => ['id' => (string) Str::uuid()]]),
        );

        $applicationAdminRole = Role::query()->updateOrCreate(
            ['code' => 'OPTIFLEET_APPLICATION_ADMIN', 'tenant_id' => $tenant->id],
            ['name' => 'OptiFleet Application Administrator', 'application_id' => $optifleet->id, 'role_type' => Role::TYPE_APPLICATION, 'is_system' => true, 'status' => Role::STATUS_ACTIVE],
        );
        $applicationAdminRole->permissions()->syncWithoutDetaching(
            Permission::query()->whereIn('permission_key', array_keys($permissions))->pluck('id')
                ->mapWithKeys(fn ($id) => [$id => ['id' => (string) Str::uuid()]]),
        );

        $demoUser = User::query()->updateOrCreate(
            ['email' => 'tenant.admin@acme.example'],
            ['name' => 'Acme Tenant Admin', 'password' => 'ChangeMe!12345', 'status' => User::STATUS_ACTIVE, 'email_verified_at' => now()],
        );

        TenantMembership::query()->firstOrCreate(
            ['user_id' => $demoUser->id, 'tenant_id' => $tenant->id],
            ['status' => TenantMembership::STATUS_ACTIVE, 'is_tenant_admin' => true],
        );

        UserApplicationAccess::query()->firstOrCreate(
            ['user_id' => $demoUser->id, 'tenant_id' => $tenant->id, 'application_id' => $optifleet->id],
            ['status' => UserApplicationAccess::STATUS_ACTIVE],
        );

        foreach ([$tenantAdminRole, $applicationAdminRole] as $role) {
            if (! $demoUser->userRoles()->where('role_id', $role->id)->where('tenant_id', $tenant->id)->exists()) {
                $demoUser->userRoles()->create(['role_id' => $role->id, 'tenant_id' => $tenant->id]);
            }
        }

        // OptiFleet's own machine-to-machine credentials, used by the
        // integration simulation (php artisan cgo:simulate-integration).
        if (! ServiceAccount::query()->where('name', 'optifleet-integration')->exists()) {
            $clients = app(ClientRepository::class);
            $client = $clients->createClientCredentialsGrantClient('optifleet-integration');

            $serviceAccount = ServiceAccount::create([
                'name' => 'optifleet-integration',
                'application_id' => $optifleet->id,
                'tenant_id' => null,
                'oauth_client_id' => $client->id,
                'status' => ServiceAccount::STATUS_ACTIVE,
            ]);

            $this->command?->info("Seeded OptiFleet service account. client_id={$client->id} client_secret={$client->plainSecret}");
        }
    }
}
