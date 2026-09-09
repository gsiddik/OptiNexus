<?php

namespace App\Services\Commercial;

use App\Models\Addon;
use App\Models\Customer;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\SubscriptionItem;
use App\Models\Tenant;
use App\Support\DocumentNumberService;
use App\Support\Money;
use Illuminate\Support\Facades\DB;

/**
 * Guards every Subscription status transition explicitly (no arbitrary
 * PATCH status=X) and keeps Entitlements transactionally in sync with
 * commercial state, per the "subscription activation + entitlement
 * generation" transactional-integrity requirement.
 */
class SubscriptionLifecycleService
{
    public function __construct(
        private readonly EntitlementService $entitlements,
        private readonly PriceRatingService $rating,
        private readonly DocumentNumberService $numbers,
    ) {}

    /**
     * @return array{0: ?Subscription, 1: ?string} [subscription, errorMessage]
     */
    public function create(Customer $customer, Tenant $tenant, Plan $plan, array $options = []): array
    {
        if ($tenant->customer_id !== $customer->id) {
            return [null, 'TENANT_CUSTOMER_MISMATCH'];
        }

        if (! $plan->isActive()) {
            return [null, 'PLAN_NOT_ACTIVE'];
        }

        if (! $plan->product->isActive()) {
            return [null, 'PRODUCT_NOT_ACTIVE'];
        }

        $subscription = DB::transaction(function () use ($customer, $tenant, $plan, $options) {
            $subscription = Subscription::create([
                'subscription_number' => $this->numbers->nextSubscriptionNumber(),
                'customer_id' => $customer->id,
                'tenant_id' => $tenant->id,
                'product_id' => $plan->product_id,
                'plan_id' => $plan->id,
                'status' => Subscription::STATUS_DRAFT,
                'currency' => $plan->currency,
                'billing_interval' => $plan->billing_interval,
                'auto_renew' => $options['auto_renew'] ?? true,
                'idempotency_key' => $options['idempotency_key'] ?? null,
                'metadata' => $options['metadata'] ?? null,
            ]);

            $this->snapshotPlanItem($subscription, $plan);

            foreach ($options['addon_ids'] ?? [] as $addonId) {
                $addon = Addon::query()->find($addonId);
                if ($addon && $addon->isActive()) {
                    $this->snapshotAddonItem($subscription, $addon);
                }
            }

            return $subscription;
        });

        return [$subscription, null];
    }

    public function startTrial(Subscription $subscription): ?string
    {
        if ($subscription->status !== Subscription::STATUS_DRAFT) {
            return 'INVALID_SUBSCRIPTION_TRANSITION';
        }

        $plan = $subscription->plan;
        if (! $plan->trial_days) {
            return 'PLAN_HAS_NO_TRIAL';
        }

        DB::transaction(function () use ($subscription, $plan) {
            $subscription->update([
                'status' => Subscription::STATUS_TRIAL,
                'start_at' => $subscription->start_at ?? now(),
                'trial_end_at' => now()->addDays($plan->trial_days),
                'current_period_start' => now(),
                'current_period_end' => now()->addDays($plan->trial_days),
            ]);

            $this->entitlements->generateFromSubscription($subscription);
        });

        return null;
    }

    public function activate(Subscription $subscription): ?string
    {
        if (! in_array($subscription->status, [Subscription::STATUS_DRAFT, Subscription::STATUS_TRIAL, Subscription::STATUS_SUSPENDED], true)) {
            return 'INVALID_SUBSCRIPTION_TRANSITION';
        }

        DB::transaction(function () use ($subscription) {
            $wasSuspended = $subscription->status === Subscription::STATUS_SUSPENDED;
            $periodEnd = $this->periodEnd(now(), $subscription->billing_interval);

            $subscription->update([
                'status' => Subscription::STATUS_ACTIVE,
                'start_at' => $subscription->start_at ?? now(),
                'current_period_start' => $subscription->current_period_start ?? now(),
                'current_period_end' => $subscription->current_period_end ?? $periodEnd,
                'grace_end_at' => null,
            ]);

            if ($wasSuspended) {
                $this->entitlements->restoreForSubscription($subscription);
            } else {
                $this->entitlements->generateFromSubscription($subscription);
            }
        });

        return null;
    }

    /**
     * Shared implementation for both upgrade and downgrade: swaps the
     * subscription's plan, closes the old PLAN subscription item, snapshots
     * a new one, and regenerates entitlements - all transactionally.
     */
    public function changePlan(Subscription $subscription, Plan $newPlan): ?string
    {
        if (! in_array($subscription->status, [Subscription::STATUS_TRIAL, Subscription::STATUS_ACTIVE], true)) {
            return 'INVALID_SUBSCRIPTION_TRANSITION';
        }

        if (! $newPlan->isActive()) {
            return 'PLAN_NOT_ACTIVE';
        }

        if ($newPlan->product_id !== $subscription->product_id) {
            return 'INVALID_SUBSCRIPTION_TRANSITION';
        }

        DB::transaction(function () use ($subscription, $newPlan) {
            $subscription->items()->where('item_type', SubscriptionItem::TYPE_PLAN)->whereNull('end_at')->update(['end_at' => now()]);

            $subscription->update(['plan_id' => $newPlan->id, 'billing_interval' => $newPlan->billing_interval, 'currency' => $newPlan->currency]);
            $this->snapshotPlanItem($subscription, $newPlan);

            $this->entitlements->generateFromSubscription($subscription->refresh());
        });

        return null;
    }

    public function renew(Subscription $subscription): ?string
    {
        if (! in_array($subscription->status, [Subscription::STATUS_ACTIVE, Subscription::STATUS_PAST_DUE, Subscription::STATUS_GRACE_PERIOD], true)) {
            return 'INVALID_SUBSCRIPTION_TRANSITION';
        }

        $newStart = $subscription->current_period_end ?? now();

        $subscription->update([
            'status' => Subscription::STATUS_ACTIVE,
            'current_period_start' => $newStart,
            'current_period_end' => $this->periodEnd($newStart, $subscription->billing_interval),
            'grace_end_at' => null,
        ]);

        return null;
    }

    public function cancel(Subscription $subscription): ?string
    {
        if (! $subscription->isActiveLike()) {
            return 'INVALID_SUBSCRIPTION_TRANSITION';
        }

        DB::transaction(function () use ($subscription) {
            $subscription->update(['status' => Subscription::STATUS_CANCELLED, 'cancelled_at' => now(), 'auto_renew' => false]);
            $this->entitlements->revokeSourcedEntitlements($subscription, 'subscription_cancelled');
        });

        return null;
    }

    public function suspend(Subscription $subscription): ?string
    {
        if (! in_array($subscription->status, [Subscription::STATUS_ACTIVE, Subscription::STATUS_PAST_DUE, Subscription::STATUS_GRACE_PERIOD], true)) {
            return 'INVALID_SUBSCRIPTION_TRANSITION';
        }

        DB::transaction(function () use ($subscription) {
            $subscription->update(['status' => Subscription::STATUS_SUSPENDED]);
            $this->entitlements->suspendForSubscription($subscription);
        });

        return null;
    }

    public function reactivate(Subscription $subscription): ?string
    {
        if ($subscription->status !== Subscription::STATUS_SUSPENDED) {
            return 'INVALID_SUBSCRIPTION_TRANSITION';
        }

        return $this->activate($subscription);
    }

    public function expire(Subscription $subscription): ?string
    {
        if (! in_array($subscription->status, [Subscription::STATUS_TRIAL, Subscription::STATUS_ACTIVE, Subscription::STATUS_PAST_DUE, Subscription::STATUS_GRACE_PERIOD], true)) {
            return 'INVALID_SUBSCRIPTION_TRANSITION';
        }

        DB::transaction(function () use ($subscription) {
            $subscription->update(['status' => Subscription::STATUS_EXPIRED]);
            $this->entitlements->expireForSubscription($subscription);
        });

        return null;
    }

    public function terminate(Subscription $subscription): ?string
    {
        if (in_array($subscription->status, [Subscription::STATUS_TERMINATED, Subscription::STATUS_CANCELLED], true)) {
            return 'INVALID_SUBSCRIPTION_TRANSITION';
        }

        DB::transaction(function () use ($subscription) {
            $subscription->update(['status' => Subscription::STATUS_TERMINATED, 'auto_renew' => false]);
            $this->entitlements->revokeSourcedEntitlements($subscription, 'subscription_terminated');
        });

        return null;
    }

    private function snapshotPlanItem(Subscription $subscription, Plan $plan): void
    {
        $price = $this->rating->resolveEffectivePrice('plan_id', $plan->id, $subscription->tenant);

        SubscriptionItem::create([
            'subscription_id' => $subscription->id,
            'item_type' => SubscriptionItem::TYPE_PLAN,
            'plan_id' => $plan->id,
            'price_id' => $price?->id,
            'quantity' => 1,
            'unit_price' => $price && $price->price_type === 'FLAT' ? Money::normalize($price->unit_amount ?? '0.00') : null,
            'currency' => $plan->currency,
            'start_at' => now(),
        ]);
    }

    private function snapshotAddonItem(Subscription $subscription, Addon $addon, float $quantity = 1): void
    {
        $price = $this->rating->resolveEffectivePrice('addon_id', $addon->id, $subscription->tenant);

        SubscriptionItem::create([
            'subscription_id' => $subscription->id,
            'item_type' => SubscriptionItem::TYPE_ADDON,
            'addon_id' => $addon->id,
            'price_id' => $price?->id,
            'quantity' => $quantity,
            'unit_price' => $price && $price->price_type === 'FLAT' ? Money::normalize($price->unit_amount ?? '0.00') : null,
            'currency' => $subscription->currency,
            'start_at' => now(),
        ]);
    }

    private function periodEnd(\DateTimeInterface $start, string $interval): \Illuminate\Support\Carbon
    {
        $start = \Illuminate\Support\Carbon::parse($start);

        return match ($interval) {
            'MONTHLY' => $start->copy()->addMonthNoOverflow(),
            'QUARTERLY' => $start->copy()->addMonthsNoOverflow(3),
            'SEMI_ANNUAL' => $start->copy()->addMonthsNoOverflow(6),
            'ANNUAL' => $start->copy()->addYearNoOverflow(),
            default => $start->copy()->addMonthNoOverflow(),
        };
    }
}
