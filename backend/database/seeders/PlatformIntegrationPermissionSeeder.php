<?php

namespace Database\Seeders;

use App\Models\Application;
use App\Models\Permission;
use Illuminate\Database\Seeder;

class PlatformIntegrationPermissionSeeder extends Seeder
{
    public const PERMISSIONS = [
        'cgo.sso.client.view', 'cgo.sso.client.manage',
        'cgo.gateway.link.view', 'cgo.gateway.link.manage', 'cgo.gateway.log.view',
    ];

    public function run(): void
    {
        $cgo = Application::query()->where('application_code', 'cgo')->firstOrFail();

        foreach (self::PERMISSIONS as $key) {
            [, $resource, $action] = array_pad(explode('.', $key), 3, null);

            Permission::query()->updateOrCreate(
                ['permission_key' => $key],
                [
                    'application_id' => $cgo->id,
                    'name' => ucfirst($resource).' '.str_replace('_', ' ', $action ?? $resource),
                    'status' => Permission::STATUS_ACTIVE,
                ],
            );
        }
    }
}
