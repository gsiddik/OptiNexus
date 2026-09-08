<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class SystemRoleSeeder extends Seeder
{
    /**
     * @var array<string, array{name: string, description: string, permissions: array<int, string>}>
     */
    public const ROLES = [
        'PLATFORM_SUPERADMIN' => [
            'name' => 'Platform Super Administrator',
            'description' => 'Unrestricted platform-wide access to every governance capability.',
            'permissions' => ['*'],
        ],
        'PLATFORM_ADMIN' => [
            'name' => 'Platform Administrator',
            'description' => 'Platform-wide administrative access, excluding service-account credential management.',
            'permissions' => ['*', '!cgo.service_account.manage'],
        ],
        'CUSTOMER_ADMIN' => [
            'name' => 'Customer Administrator',
            'description' => 'Manages customer accounts and their tenants.',
            'permissions' => [
                'cgo.customer.view', 'cgo.customer.create', 'cgo.customer.update',
                'cgo.customer.activate', 'cgo.customer.suspend', 'cgo.customer.terminate', 'cgo.customer.archive',
                'cgo.tenant.view', 'cgo.tenant.create',
            ],
        ],
        'IAM_ADMIN' => [
            'name' => 'Identity & Access Administrator',
            'description' => 'Manages users, roles, and permissions platform-wide.',
            'permissions' => [
                'cgo.user.view', 'cgo.user.create', 'cgo.user.update', 'cgo.user.activate', 'cgo.user.suspend',
                'cgo.user.disable', 'cgo.user.tenant.assign', 'cgo.user.application.assign', 'cgo.user.role.assign',
                'cgo.role.view', 'cgo.role.create', 'cgo.role.update', 'cgo.role.clone', 'cgo.role.permission.grant',
                'cgo.permission.view', 'cgo.permission.create', 'cgo.permission.update',
            ],
        ],
        'AUDITOR' => [
            'name' => 'Auditor',
            'description' => 'Read-only access across the governance domain plus the audit trail.',
            'permissions' => [
                'cgo.audit.view', 'cgo.customer.view', 'cgo.tenant.view', 'cgo.application.view',
                'cgo.capability.view', 'cgo.permission.view', 'cgo.role.view', 'cgo.user.view',
            ],
        ],
        'SUPPORT_ADMIN' => [
            'name' => 'Support Administrator',
            'description' => 'Front-line support: can view accounts and suspend/reactivate users, read-only elsewhere.',
            'permissions' => [
                'cgo.customer.view', 'cgo.tenant.view', 'cgo.application.view', 'cgo.user.view',
                'cgo.user.suspend', 'cgo.user.activate', 'cgo.audit.view',
            ],
        ],
    ];

    public function run(): void
    {
        $all = Permission::query()->pluck('permission_key')->all();

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

            $keys = $this->resolvePermissionKeys($definition['permissions'], $all);

            $permissionIds = Permission::query()->whereIn('permission_key', $keys)->pluck('id');

            $sync = [];
            foreach ($permissionIds as $id) {
                $sync[$id] = ['id' => (string) Str::uuid()];
            }
            $role->permissions()->sync($sync);
        }
    }

    /**
     * @param  string[]  $permissions
     * @param  string[]  $all
     * @return string[]
     */
    private function resolvePermissionKeys(array $permissions, array $all): array
    {
        if (! in_array('*', $permissions, true)) {
            return $permissions;
        }

        $excluded = array_map(fn ($p) => ltrim($p, '!'), array_filter($permissions, fn ($p) => str_starts_with($p, '!')));

        return array_values(array_diff($all, $excluded));
    }
}
