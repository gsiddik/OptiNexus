<?php

namespace App\Services\Commercial;

use App\Models\Addon;
use App\Models\Entitlement;
use App\Models\Plan;
use App\Models\Subscription;
use App\Services\AuditService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Keeps materialized subscription access aligned with its purchased catalog configuration. */
class CatalogEntitlementSyncService
{
    public function __construct(private readonly EntitlementService $entitlements, private readonly AuditService $audit) {}

    public function forPlan(Plan $plan, ?Request $request = null): void
    {
        $this->refresh(Subscription::query()->where('plan_id', $plan->id), $request);
    }

    public function forAddon(Addon $addon, ?Request $request = null): void
    {
        $this->refresh(Subscription::query()->whereHas('items', fn ($query) => $query->where('item_type', 'ADDON')->where('addon_id', $addon->id)), $request);
    }

    public function refresh(Builder $subscriptions, ?Request $request = null): int
    {
        return DB::transaction(function () use ($subscriptions, $request): int {
            $count = 0;
            foreach ($subscriptions->whereIn('status', [...Subscription::ACTIVE_LIKE_STATUSES, Subscription::STATUS_SUSPENDED])->orderBy('id')->lockForUpdate()->get() as $subscription) {
                $this->entitlements->generateFromSubscription($subscription);
                if ($subscription->status === Subscription::STATUS_SUSPENDED) {
                    $subscription->entitlements()->whereIn('source_type', [Entitlement::SOURCE_PLAN, Entitlement::SOURCE_ADDON])
                        ->where('status', Entitlement::STATUS_ACTIVE)->update(['status' => Entitlement::STATUS_SUSPENDED]);
                }
                $this->audit->record('subscription.entitlements_refreshed', $request, tenantId: $subscription->tenant_id,
                    customerId: $subscription->customer_id, resourceType: 'Subscription', resourceId: $subscription->id,
                    newValue: ['plan_id' => $subscription->plan_id, 'status' => $subscription->status], source: $request ? 'cgo' : 'cli');
                $count++;
            }

            return $count;
        });
    }
}
