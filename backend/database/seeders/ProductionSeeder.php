<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * Catalog data every installation needs and nothing else: permissions, system roles, the bootstrap administrator (only when
 * CGO_SUPERADMIN_PASSWORD is set outside local/testing), the OptiRadar application and the event catalogs. No demo customers, tenants or
 * users. DatabaseSeeder adds the demo data for local development; the container bootstrap runs this one unless NEXUS_SEED=demo.
 */
class ProductionSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            PermissionSeeder::class,
            CommercialPermissionSeeder::class,
            OrchestrationPermissionSeeder::class,
            PlatformIntegrationPermissionSeeder::class,
            SystemRoleSeeder::class,
            CommercialRoleSeeder::class,
            OrchestrationRoleSeeder::class,
            PlatformIntegrationRoleSeeder::class,
            AdminUserSeeder::class,
            OptiRadarApplicationSeeder::class,
            OptiFleetEventCatalogSeeder::class,
            OptiRadarEventCatalogSeeder::class,
        ]);
    }
}
