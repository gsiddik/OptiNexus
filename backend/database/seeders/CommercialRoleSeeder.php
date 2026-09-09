<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class CommercialRoleSeeder extends Seeder
{
    /**
     * @var array<string, array{name: string, description: string, permissions: array<int, string>}>
     */
    public const ROLES = [
        'PRODUCT_MANAGER' => [
            'name' => 'Product Manager',
            'description' => 'Manages the commercial product, plan, and add-on catalog.',
            'permissions' => [
                'cgo.product.view', 'cgo.product.create', 'cgo.product.update', 'cgo.product.activate',
                'cgo.product.deactivate', 'cgo.product.retire', 'cgo.product.application.assign',
                'cgo.product.application.revoke', 'cgo.product.capability.assign', 'cgo.product.capability.revoke',
                'cgo.plan.view', 'cgo.plan.create', 'cgo.plan.update', 'cgo.plan.clone', 'cgo.plan.activate',
                'cgo.plan.deactivate', 'cgo.plan.retire', 'cgo.plan.capability.assign', 'cgo.plan.capability.revoke',
                'cgo.plan.limit.configure',
                'cgo.addon.view', 'cgo.addon.create', 'cgo.addon.update', 'cgo.addon.activate', 'cgo.addon.deactivate',
                'cgo.addon.retire', 'cgo.addon.capability.assign', 'cgo.addon.capability.revoke', 'cgo.addon.limit.configure',
            ],
        ],
        'COMMERCIAL_ADMIN' => [
            'name' => 'Commercial Administrator',
            'description' => 'Manages pricing, including tenant-specific overrides, and views the full commercial catalog.',
            'permissions' => [
                'cgo.product.view', 'cgo.plan.view', 'cgo.addon.view',
                'cgo.pricing.view', 'cgo.pricing.create', 'cgo.pricing.update', 'cgo.pricing.override',
                'cgo.pricing.activate', 'cgo.pricing.retire', 'cgo.pricing.simulate',
                'cgo.entitlement.view', 'cgo.entitlement.override', 'cgo.entitlement.effective.view',
            ],
        ],
        'SUBSCRIPTION_ADMIN' => [
            'name' => 'Subscription Administrator',
            'description' => 'Manages the subscription lifecycle and tenant entitlements.',
            'permissions' => [
                'cgo.subscription.view', 'cgo.subscription.create', 'cgo.subscription.update',
                'cgo.subscription.activate', 'cgo.subscription.upgrade', 'cgo.subscription.downgrade',
                'cgo.subscription.renew', 'cgo.subscription.cancel', 'cgo.subscription.suspend',
                'cgo.subscription.reactivate', 'cgo.subscription.terminate',
                'cgo.entitlement.view', 'cgo.entitlement.suspend', 'cgo.entitlement.restore', 'cgo.entitlement.effective.view',
                'cgo.usage.view',
            ],
        ],
        // Segregation of duties: BILLING_ADMIN can prepare and adjust
        // billing/invoices but cannot finalize a billing or void an
        // invoice - that requires BILLING_APPROVER.
        'BILLING_ADMIN' => [
            'name' => 'Billing Administrator',
            'description' => 'Prepares and adjusts billing runs and invoices. Cannot finalize billing or void invoices.',
            'permissions' => [
                'cgo.billing.view', 'cgo.billing.create', 'cgo.billing.update', 'cgo.billing.review', 'cgo.billing.adjust',
                'cgo.invoice.view', 'cgo.invoice.create', 'cgo.invoice.payment.record',
                'cgo.usage.view',
            ],
        ],
        'BILLING_APPROVER' => [
            'name' => 'Billing Approver',
            'description' => 'Finalizes billing and voids/cancels invoices - the checker in a maker-checker split from BILLING_ADMIN.',
            'permissions' => [
                'cgo.billing.view', 'cgo.billing.finalize', 'cgo.billing.cancel',
                'cgo.invoice.view', 'cgo.invoice.issue', 'cgo.invoice.void', 'cgo.invoice.cancel',
            ],
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

            $permissionIds = Permission::query()->whereIn('permission_key', $definition['permissions'])->pluck('id');

            $sync = [];
            foreach ($permissionIds as $id) {
                $sync[$id] = ['id' => (string) Str::uuid()];
            }
            $role->permissions()->sync($sync);
        }
    }
}
