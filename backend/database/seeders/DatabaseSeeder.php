<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

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
            SystemRoleSeeder::class,
            CommercialRoleSeeder::class,
            OrchestrationRoleSeeder::class,
            PlatformIntegrationRoleSeeder::class,
            AdminUserSeeder::class,
            DemoDataSeeder::class,
            CommercialDemoDataSeeder::class,
        ]);
    }
}
