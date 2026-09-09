<?php

namespace Database\Seeders;

use App\Models\Addon;
use App\Models\AddonLimit;
use App\Models\Application;
use App\Models\Capability;
use App\Models\Plan;
use App\Models\PlanLimit;
use App\Models\Price;
use App\Models\Product;
use App\Models\TaxCode;
use App\Models\Tenant;
use Illuminate\Database\Seeder;

/**
 * Extends Phase 1's DemoDataSeeder (ACME / ACME-SG / optifleet) with a
 * commercial catalog, so the commercial integration simulation and manual
 * QA have a realistic Product -> Plan -> Price chain to subscribe against.
 */
class CommercialDemoDataSeeder extends Seeder
{
    public function run(): void
    {
        $optifleet = Application::query()->where('application_code', 'optifleet')->first();
        $vehicleFeature = Capability::query()->where('code', 'vehicle_feature')->first();

        if (! $optifleet || ! $vehicleFeature) {
            $this->command?->warn('Phase 1 demo data (optifleet application / vehicle_feature capability) not found; skipping commercial demo data.');

            return;
        }

        $taxCode = TaxCode::query()->updateOrCreate(
            ['tax_code' => 'PPN'],
            ['label' => 'PPN (Indonesian VAT)', 'rate' => '11.0000', 'status' => TaxCode::STATUS_ACTIVE, 'effective_from' => now()->subYear()],
        );

        $product = Product::query()->updateOrCreate(
            ['product_code' => 'FLEET-SUITE'],
            ['name' => 'Fleet Operations Suite', 'description' => 'Vehicle and fleet operations management.', 'status' => Product::STATUS_ACTIVE, 'currency' => 'IDR'],
        );

        if (! $product->applications()->where('applications.id', $optifleet->id)->exists()) {
            $product->applications()->attach($optifleet->id, ['id' => (string) \Illuminate\Support\Str::uuid()]);
        }
        if (! $product->capabilities()->where('capabilities.id', $vehicleFeature->id)->exists()) {
            $product->capabilities()->attach($vehicleFeature->id, ['id' => (string) \Illuminate\Support\Str::uuid()]);
        }

        $plan = Plan::query()->updateOrCreate(
            ['plan_code' => 'OPTIFLEET-PRO'],
            [
                'product_id' => $product->id,
                'name' => 'OptiFleet Professional',
                'description' => 'Professional tier: up to 100 vehicles included.',
                'billing_interval' => Plan::INTERVAL_MONTHLY,
                'status' => Plan::STATUS_ACTIVE,
                'trial_days' => 14,
                'currency' => 'IDR',
            ],
        );

        if (! $plan->capabilities()->where('capabilities.id', $vehicleFeature->id)->exists()) {
            $plan->capabilities()->attach($vehicleFeature->id, ['id' => (string) \Illuminate\Support\Str::uuid()]);
        }

        PlanLimit::query()->updateOrCreate(
            ['plan_id' => $plan->id, 'limit_key' => 'vehicle_limit'],
            ['limit_value' => '100.0000', 'is_unlimited' => false, 'unit' => 'vehicle'],
        );

        Price::query()->updateOrCreate(
            ['plan_id' => $plan->id, 'price_type' => Price::TYPE_FLAT, 'unit_name' => null, 'meter_key' => null],
            [
                'currency' => 'IDR', 'unit_amount' => '2000000.00', 'billing_interval' => Plan::INTERVAL_MONTHLY,
                'status' => Price::STATUS_ACTIVE, 'approval_status' => Price::APPROVAL_APPROVED, 'effective_from' => now()->subYear(),
                'tax_code_id' => $taxCode->id,
            ],
        );

        Price::query()->updateOrCreate(
            ['plan_id' => $plan->id, 'price_type' => Price::TYPE_PER_VEHICLE, 'meter_key' => 'vehicles'],
            [
                'currency' => 'IDR', 'unit_amount' => '30000.00', 'billing_interval' => Plan::INTERVAL_MONTHLY,
                'unit_name' => 'vehicle', 'included_quantity' => '100.0000',
                'status' => Price::STATUS_ACTIVE, 'approval_status' => Price::APPROVAL_APPROVED, 'effective_from' => now()->subYear(),
                'tax_code_id' => $taxCode->id,
            ],
        );

        $addon = Addon::query()->updateOrCreate(
            ['addon_code' => 'EXTRA-50-VEHICLES'],
            ['product_id' => $product->id, 'name' => 'Extra 50 Vehicles', 'description' => 'Raises the vehicle limit by 50.', 'status' => Addon::STATUS_ACTIVE],
        );

        AddonLimit::query()->updateOrCreate(
            ['addon_id' => $addon->id, 'limit_key' => 'vehicle_limit'],
            ['limit_delta' => '50.0000', 'unit' => 'vehicle'],
        );

        Price::query()->updateOrCreate(
            ['addon_id' => $addon->id, 'price_type' => Price::TYPE_FLAT, 'unit_name' => null, 'meter_key' => null],
            [
                'currency' => 'IDR', 'unit_amount' => '500000.00', 'billing_interval' => Plan::INTERVAL_MONTHLY,
                'status' => Price::STATUS_ACTIVE, 'approval_status' => Price::APPROVAL_APPROVED, 'effective_from' => now()->subYear(),
            ],
        );

        // A custom, cheaper per-vehicle contract price for ACME specifically
        // (Custom Tenant Pricing) - approved so simulate()/billing can use
        // it immediately in the demo environment.
        $tenant = Tenant::query()->where('tenant_code', 'ACME-SG')->first();
        if ($tenant) {
            Price::query()->updateOrCreate(
                ['plan_id' => $plan->id, 'tenant_id' => $tenant->id, 'price_type' => Price::TYPE_PER_VEHICLE, 'meter_key' => 'vehicles'],
                [
                    'currency' => 'IDR', 'unit_amount' => '25000.00', 'billing_interval' => Plan::INTERVAL_MONTHLY,
                    'unit_name' => 'vehicle', 'included_quantity' => '100.0000',
                    'status' => Price::STATUS_ACTIVE, 'approval_status' => Price::APPROVAL_APPROVED, 'effective_from' => now()->subMonth(),
                    'tax_code_id' => $taxCode->id,
                ],
            );
        }

        $this->command?->info('Seeded commercial demo data: FLEET-SUITE product, OPTIFLEET-PRO plan, pricing, EXTRA-50-VEHICLES add-on.');
    }
}
