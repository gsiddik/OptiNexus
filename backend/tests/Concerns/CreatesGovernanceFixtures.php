<?php

namespace Tests\Concerns;

use App\Models\Application;
use App\Models\Capability;
use App\Models\Customer;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use App\Models\UserApplicationAccess;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\SystemRoleSeeder;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

trait CreatesGovernanceFixtures
{
    protected function seedGovernanceBaseline(): void
    {
        $this->seed(PermissionSeeder::class);
        $this->seed(SystemRoleSeeder::class);
    }

    protected function actingAsSuperAdmin(): User
    {
        $this->seedGovernanceBaseline();

        $user = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $role = Role::query()->where('code', 'PLATFORM_SUPERADMIN')->whereNull('tenant_id')->firstOrFail();
        $user->userRoles()->create(['role_id' => $role->id, 'tenant_id' => null]);

        Sanctum::actingAs($user);

        return $user;
    }

    /**
     * A platform operator whose only global role carries the ecosystem gate plus the given cgo permissions - the way to
     * exercise a permission denial now that users without ecosystem access are stopped one step earlier (audit R07/A24).
     *
     * @param  array<int, string>  $permissionKeys
     * @param  array<string, mixed>  $userOverrides
     */
    protected function makeOperatorWith(array $permissionKeys = [], array $userOverrides = []): User
    {
        $this->seedGovernanceBaseline();

        $role = Role::create([
            'tenant_id' => null,
            'name' => 'Test operator',
            'code' => 'OPERATOR-'.Str::upper(Str::random(6)),
            'role_type' => Role::TYPE_SYSTEM,
            'is_system' => false,
            'status' => Role::STATUS_ACTIVE,
        ]);

        $ids = Permission::query()->whereIn('permission_key', [Permission::ECOSYSTEM_ACCESS, ...$permissionKeys])->pluck('id');
        foreach ($ids as $id) {
            $role->permissions()->attach($id, ['id' => (string) Str::uuid()]);
        }

        $user = User::factory()->create(array_merge(['status' => User::STATUS_ACTIVE], $userOverrides));
        $user->userRoles()->create(['role_id' => $role->id, 'tenant_id' => null]);

        return $user;
    }

    protected function actingAsOperatorWith(array $permissionKeys = []): User
    {
        $user = $this->makeOperatorWith($permissionKeys);
        Sanctum::actingAs($user);

        return $user;
    }

    protected function makeCustomer(array $overrides = []): Customer
    {
        return Customer::create(array_merge([
            'customer_code' => 'CUST-'.Str::upper(Str::random(6)),
            'legal_name' => 'Test Customer Pte Ltd',
            'status' => Customer::STATUS_ACTIVE,
        ], $overrides));
    }

    protected function makeTenant(?Customer $customer = null, array $overrides = []): Tenant
    {
        return Tenant::create(array_merge([
            'tenant_code' => 'TEN-'.Str::upper(Str::random(6)),
            'customer_id' => ($customer ?? $this->makeCustomer())->id,
            'name' => 'Test Tenant',
            'status' => Tenant::STATUS_ACTIVE,
        ], $overrides));
    }

    protected function makeApplication(array $overrides = []): Application
    {
        return Application::create(array_merge([
            'application_code' => 'app-'.Str::lower(Str::random(6)),
            'name' => 'Test Application',
            'status' => Application::STATUS_PUBLISHED,
        ], $overrides));
    }

    protected function makePermission(Application $application, string $key, ?Capability $capability = null): Permission
    {
        return Permission::create([
            'application_id' => $application->id,
            'capability_id' => $capability?->id,
            'permission_key' => $key,
            'name' => $key,
            'status' => Permission::STATUS_ACTIVE,
        ]);
    }

    protected function makeTenantRole(Tenant $tenant, array $permissionKeys, array $overrides = []): Role
    {
        $role = Role::create(array_merge([
            'tenant_id' => $tenant->id,
            'name' => 'Test Tenant Role',
            'code' => 'ROLE-'.Str::upper(Str::random(6)),
            'role_type' => Role::TYPE_TENANT,
            'is_system' => false,
            'status' => Role::STATUS_ACTIVE,
        ], $overrides));

        $ids = Permission::query()->whereIn('permission_key', $permissionKeys)->pluck('id');
        foreach ($ids as $id) {
            $role->permissions()->attach($id, ['id' => (string) Str::uuid()]);
        }

        return $role;
    }

    protected function attachUserToTenant(User $user, Tenant $tenant, ?Role $role = null, bool $isTenantAdmin = false): void
    {
        TenantMembership::create([
            'user_id' => $user->id,
            'tenant_id' => $tenant->id,
            'status' => TenantMembership::STATUS_ACTIVE,
            'is_tenant_admin' => $isTenantAdmin,
        ]);

        if ($role) {
            $user->userRoles()->create(['role_id' => $role->id, 'tenant_id' => $tenant->id]);
        }
    }

    protected function makeActiveApplicationSubscription(Tenant $tenant, Application $application): \App\Models\Subscription
    {
        $product = \App\Models\Product::create(['product_code' => 'P-'.Str::random(12), 'name' => 'Subscribed product', 'status' => 'ACTIVE', 'currency' => 'IDR']);
        $product->applications()->attach($application->id, ['id' => (string) Str::uuid()]);
        $plan = \App\Models\Plan::create(['product_id' => $product->id, 'plan_code' => 'P-'.Str::random(12), 'name' => 'Subscribed plan', 'status' => 'ACTIVE', 'currency' => 'IDR', 'billing_interval' => 'MONTHLY']);

        return \App\Models\Subscription::create([
            'subscription_number' => 'SUB-'.Str::random(12), 'customer_id' => $tenant->customer_id, 'tenant_id' => $tenant->id,
            'product_id' => $product->id, 'plan_id' => $plan->id, 'status' => 'ACTIVE', 'currency' => 'IDR', 'billing_interval' => 'MONTHLY',
            'start_at' => now()->subDay(), 'current_period_start' => now()->subDay(), 'current_period_end' => now()->addMonth(),
            'grace_end_at' => now()->addMonth()->addDays(7), 'grace_period_days' => 7,
        ]);
    }

    protected function grantApplicationAccess(User $user, Tenant $tenant, Application $application): void
    {
        if (! $tenant->applications()->where('applications.id', $application->id)->exists()) {
            $tenant->applications()->attach($application->id, ['id' => (string) Str::uuid(), 'status' => 'ACTIVE']);
        }

        UserApplicationAccess::create([
            'user_id' => $user->id,
            'tenant_id' => $tenant->id,
            'application_id' => $application->id,
            'status' => UserApplicationAccess::STATUS_ACTIVE,
        ]);
    }
}
