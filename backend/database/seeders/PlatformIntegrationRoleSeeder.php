<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class PlatformIntegrationRoleSeeder extends Seeder
{
    public const ROLES = [
        'PLATFORM_INTEGRATION_ADMIN' => [
            'name' => 'Platform Integration Administrator',
            'description' => 'Registers SSO clients for integrated applications and maps fleet vehicles to telematics devices.',
            'permissions' => PlatformIntegrationPermissionSeeder::PERMISSIONS,
        ],
    ];

    public function run(): void
    {
        foreach (self::ROLES as $code => $definition) {
            $role = Role::query()->updateOrCreate(
                ['code' => $code, 'tenant_id' => null],
                [
                    'name' => $definition['name'],
                    'description' => $definition['description'],
                    'role_type' => Role::TYPE_SYSTEM,
                    'is_system' => true,
                    'status' => Role::STATUS_ACTIVE,
                ],
            );

            $sync = [];
            foreach (Permission::query()->whereIn('permission_key', $definition['permissions'])->pluck('id') as $id) {
                $sync[$id] = ['id' => (string) Str::uuid()];
            }
            $role->permissions()->sync($sync);
        }
    }
}
