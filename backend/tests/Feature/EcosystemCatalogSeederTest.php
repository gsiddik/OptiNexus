<?php

namespace Tests\Feature;

use App\Models\Application;
use App\Models\Capability;
use App\Models\EventCatalogEntry;
use App\Models\Permission;
use App\Models\Plan;
use App\Models\PlanLimit;
use App\Models\Product;
use App\Models\Role;
use App\Models\Tenant;
use Database\Seeders\EcosystemCatalogSeeder;
use Database\Seeders\ProductionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EcosystemCatalogSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_registers_every_opti_application_with_its_catalog(): void
    {
        $this->seed(EcosystemCatalogSeeder::class);

        foreach (array_keys(EcosystemCatalogSeeder::MANIFESTS) as $code) {
            $application = Application::query()->where('application_code', $code)->first();

            $this->assertNotNull($application, "{$code} is registered");
            $this->assertSame(Application::STATUS_PUBLISHED, $application->status);
            $this->assertGreaterThan(0, Capability::query()->where('application_id', $application->id)->count(), "{$code} has capabilities");
            $this->assertGreaterThan(0, Permission::query()->where('application_id', $application->id)->count(), "{$code} has permissions");
        }

        $this->assertTrue(EventCatalogEntry::query()->where('event_key', 'like', 'optiaccounting.%')->exists());
    }

    public function test_the_suite_plan_holds_every_capability_so_a_tenant_can_be_provisioned(): void
    {
        $this->seed(EcosystemCatalogSeeder::class);

        $product = Product::query()->where('product_code', EcosystemCatalogSeeder::SUITE_PRODUCT_CODE)->firstOrFail();
        $plan = Plan::query()->where('plan_code', EcosystemCatalogSeeder::SUITE_PLAN_CODE)->firstOrFail();
        $applicationIds = Application::query()->whereIn('application_code', array_keys(EcosystemCatalogSeeder::MANIFESTS))->pluck('id');
        $capabilities = Capability::query()->whereIn('application_id', $applicationIds)->count();

        $this->assertSame(Product::STATUS_ACTIVE, $product->status);
        $this->assertSame(Plan::STATUS_ACTIVE, $plan->status);
        $this->assertSame($applicationIds->count(), $product->applications()->count());
        $this->assertSame($capabilities, $product->capabilities()->count());
        $this->assertSame($capabilities, $plan->capabilities()->count());
    }

    public function test_the_suite_plan_is_unlimited_for_every_declared_limit_key_and_keeps_edited_limits(): void
    {
        $this->seed(EcosystemCatalogSeeder::class);

        $plan = Plan::query()->where('plan_code', EcosystemCatalogSeeder::SUITE_PLAN_CODE)->firstOrFail();
        $keys = $plan->limits()->pluck('limit_key')->sort()->values()->all();

        $this->assertSame(['branch_limit', 'business_unit_limit', 'device_limit', 'user_limit'], $keys);
        $this->assertSame(4, $plan->limits()->where('is_unlimited', true)->count());

        PlanLimit::query()->where('plan_id', $plan->id)->where('limit_key', 'user_limit')->update(['is_unlimited' => false, 'limit_value' => 25]);
        $this->seed(EcosystemCatalogSeeder::class);

        $limit = PlanLimit::query()->where('plan_id', $plan->id)->where('limit_key', 'user_limit')->firstOrFail();
        $this->assertFalse((bool) $limit->is_unlimited);
        $this->assertEquals(25, $limit->limit_value);
    }

    public function test_seeding_again_changes_nothing_and_keeps_an_operators_application_urls(): void
    {
        $this->seed(EcosystemCatalogSeeder::class);

        Application::query()->where('application_code', 'optifleet')->update(['frontend_url' => 'https://fleet.example.org']);
        $counts = [Capability::query()->count(), Permission::query()->count(), EventCatalogEntry::query()->count(), Application::query()->count()];

        $this->seed(EcosystemCatalogSeeder::class);

        $this->assertSame($counts, [Capability::query()->count(), Permission::query()->count(), EventCatalogEntry::query()->count(), Application::query()->count()]);
        $this->assertSame('https://fleet.example.org', Application::query()->where('application_code', 'optifleet')->value('frontend_url'));
        $this->assertSame(1, Product::query()->where('product_code', EcosystemCatalogSeeder::SUITE_PRODUCT_CODE)->count());
        $this->assertSame(0, Tenant::query()->count(), 'no tenants are created');
    }

    public function test_the_production_seeder_gives_the_platform_superadmin_the_imported_permissions(): void
    {
        $this->seed(ProductionSeeder::class);

        $role = Role::query()->where('code', 'PLATFORM_SUPERADMIN')->whereNull('tenant_id')->firstOrFail();
        $imported = Permission::query()->where('permission_key', 'like', 'optiaccounting.%')->count();

        $this->assertGreaterThan(0, $imported);
        $this->assertSame(
            $imported,
            $role->permissions()->where('permission_key', 'like', 'optiaccounting.%')->count(),
            'PLATFORM_SUPERADMIN holds every imported permission',
        );
    }
}
