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
            SystemRoleSeeder::class,
            CommercialRoleSeeder::class,
            OrchestrationRoleSeeder::class,
            AdminUserSeeder::class,
            DemoDataSeeder::class,
            CommercialDemoDataSeeder::class,
        ]);
    }
}
