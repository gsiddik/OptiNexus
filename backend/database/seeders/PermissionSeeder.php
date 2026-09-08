<?php

namespace Database\Seeders;

use App\Models\Application;
use App\Models\Permission;
use Illuminate\Database\Seeder;

class PermissionSeeder extends Seeder
{
    public const CGO_PERMISSIONS = [
        'cgo.customer.view', 'cgo.customer.create', 'cgo.customer.update',
        'cgo.customer.activate', 'cgo.customer.suspend', 'cgo.customer.terminate', 'cgo.customer.archive',

        'cgo.tenant.view', 'cgo.tenant.create', 'cgo.tenant.update', 'cgo.tenant.provision',
        'cgo.tenant.activate', 'cgo.tenant.suspend', 'cgo.tenant.terminate', 'cgo.tenant.archive',
        'cgo.tenant.application.assign', 'cgo.tenant.admin.assign',

        'cgo.application.view', 'cgo.application.create', 'cgo.application.update',
        'cgo.application.submit', 'cgo.application.approve', 'cgo.application.publish',
        'cgo.application.deprecate', 'cgo.application.retire',

        'cgo.capability.view', 'cgo.capability.create', 'cgo.capability.update', 'cgo.capability.delete',
        'cgo.capability.activate', 'cgo.capability.disable', 'cgo.capability.deprecate',
        'cgo.capability.permission.map',

        'cgo.permission.view', 'cgo.permission.create', 'cgo.permission.update',

        'cgo.role.view', 'cgo.role.create', 'cgo.role.update', 'cgo.role.clone', 'cgo.role.permission.grant',

        'cgo.user.view', 'cgo.user.create', 'cgo.user.update', 'cgo.user.activate',
        'cgo.user.suspend', 'cgo.user.disable', 'cgo.user.tenant.assign',
        'cgo.user.application.assign', 'cgo.user.role.assign',

        'cgo.audit.view',

        'cgo.service_account.manage',
    ];

    public function run(): void
    {
        $cgo = Application::query()->updateOrCreate(
            ['application_code' => 'cgo'],
            [
                'name' => 'CGO Governance Core',
                'description' => 'The Central Governance & Orchestration control plane itself.',
                'owner' => 'Platform Engineering',
                'version' => '1.0.0',
                'status' => Application::STATUS_PUBLISHED,
            ],
        );

        foreach (self::CGO_PERMISSIONS as $key) {
            [, $resource, $action] = array_pad(explode('.', $key), 3, null);

            Permission::query()->updateOrCreate(
                ['permission_key' => $key],
                [
                    'application_id' => $cgo->id,
                    'name' => ucfirst($resource).' '.str_replace('_', ' ', $action),
                    'status' => Permission::STATUS_ACTIVE,
                ],
            );
        }
    }
}
