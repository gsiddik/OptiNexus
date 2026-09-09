<?php

namespace Database\Seeders;

use App\Models\Application;
use App\Models\Permission;
use Illuminate\Database\Seeder;

class OrchestrationPermissionSeeder extends Seeder
{
    public const ORCHESTRATION_PERMISSIONS = [
        'cgo.policy.view', 'cgo.policy.create', 'cgo.policy.update', 'cgo.policy.activate',
        'cgo.policy.deactivate', 'cgo.policy.deprecate', 'cgo.policy.simulate',

        'cgo.workflow.view', 'cgo.workflow.create', 'cgo.workflow.update', 'cgo.workflow.activate',
        'cgo.workflow.execute', 'cgo.workflow.retry', 'cgo.workflow.cancel',

        'cgo.approval.definition.view', 'cgo.approval.definition.manage', 'cgo.approval.request.view',
        'cgo.approval.approve', 'cgo.approval.reject', 'cgo.approval.return', 'cgo.approval.delegate',

        'cgo.integration.view', 'cgo.integration.create', 'cgo.integration.update', 'cgo.integration.activate',
        'cgo.integration.test', 'cgo.integration.credential.rotate',

        'cgo.event.catalog.view', 'cgo.event.catalog.manage', 'cgo.event.delivery.view',
        'cgo.event.delivery.retry', 'cgo.event.delivery.discard',

        'cgo.featureflag.view', 'cgo.featureflag.create', 'cgo.featureflag.update', 'cgo.featureflag.override',

        'cgo.notification.template.manage', 'cgo.notification.rule.manage', 'cgo.notification.send',
        'cgo.notification.delivery.view', 'cgo.notification.delivery.retry',
    ];

    public function run(): void
    {
        $cgo = Application::query()->where('application_code', 'cgo')->firstOrFail();

        foreach (self::ORCHESTRATION_PERMISSIONS as $key) {
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
