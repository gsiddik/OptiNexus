<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/** Local development: the catalog plus demo data. Installations use ProductionSeeder (no demo customers or users). */
class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call([
            PermissionSeeder::class,
            CommercialPermissionSeeder::class,
            OrchestrationPermissionSeeder::class,
            PlatformIntegrationPermissionSeeder::class,
            // Applications, modules, features, permissions and events of every Opti product. Before the system roles, which resolve
            // "every permission" when they run.
            EcosystemCatalogSeeder::class,
            SystemRoleSeeder::class,
            CommercialRoleSeeder::class,
            OrchestrationRoleSeeder::class,
            PlatformIntegrationRoleSeeder::class,
            AdminUserSeeder::class,
            DemoDataSeeder::class,
            OptiRadarApplicationSeeder::class,
            CommercialDemoDataSeeder::class,
            // After the applications exist; each does nothing (with a warning) when its application is not registered yet.
            OptiFleetEventCatalogSeeder::class,
            OptiRadarEventCatalogSeeder::class,
        ]);
    }
}
