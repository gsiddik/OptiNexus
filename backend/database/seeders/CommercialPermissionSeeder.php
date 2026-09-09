<?php

namespace Database\Seeders;

use App\Models\Application;
use App\Models\Permission;
use Illuminate\Database\Seeder;

class CommercialPermissionSeeder extends Seeder
{
    public const COMMERCIAL_PERMISSIONS = [
        'cgo.product.view', 'cgo.product.create', 'cgo.product.update', 'cgo.product.activate',
        'cgo.product.deactivate', 'cgo.product.retire', 'cgo.product.application.assign',
        'cgo.product.application.revoke', 'cgo.product.capability.assign', 'cgo.product.capability.revoke',

        'cgo.plan.view', 'cgo.plan.create', 'cgo.plan.update', 'cgo.plan.clone', 'cgo.plan.activate',
        'cgo.plan.deactivate', 'cgo.plan.retire', 'cgo.plan.capability.assign', 'cgo.plan.capability.revoke',
        'cgo.plan.limit.configure',

        'cgo.addon.view', 'cgo.addon.create', 'cgo.addon.update', 'cgo.addon.activate', 'cgo.addon.deactivate',
        'cgo.addon.retire', 'cgo.addon.capability.assign', 'cgo.addon.capability.revoke', 'cgo.addon.limit.configure',

        'cgo.subscription.view', 'cgo.subscription.create', 'cgo.subscription.update', 'cgo.subscription.activate',
        'cgo.subscription.upgrade', 'cgo.subscription.downgrade', 'cgo.subscription.renew', 'cgo.subscription.cancel',
        'cgo.subscription.suspend', 'cgo.subscription.reactivate', 'cgo.subscription.terminate',

        'cgo.entitlement.view', 'cgo.entitlement.grant', 'cgo.entitlement.revoke', 'cgo.entitlement.override',
        'cgo.entitlement.suspend', 'cgo.entitlement.restore', 'cgo.entitlement.effective.view',

        'cgo.pricing.view', 'cgo.pricing.create', 'cgo.pricing.update', 'cgo.pricing.override',
        'cgo.pricing.activate', 'cgo.pricing.retire', 'cgo.pricing.simulate',

        'cgo.usage.view', 'cgo.usage.write',

        'cgo.billing.view', 'cgo.billing.create', 'cgo.billing.update', 'cgo.billing.review',
        'cgo.billing.finalize', 'cgo.billing.cancel', 'cgo.billing.adjust',

        'cgo.invoice.view', 'cgo.invoice.create', 'cgo.invoice.issue', 'cgo.invoice.payment.record',
        'cgo.invoice.void', 'cgo.invoice.cancel',
    ];

    public function run(): void
    {
        $cgo = Application::query()->where('application_code', 'cgo')->firstOrFail();

        foreach (self::COMMERCIAL_PERMISSIONS as $key) {
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
