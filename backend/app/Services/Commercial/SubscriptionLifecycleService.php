<?php

namespace App\Services\Commercial;

use App\Models\Addon;
use App\Models\Customer;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\SubscriptionItem;
use App\Models\Tenant;
use App\Services\AuditService;
use App\Services\Oidc\SessionRevocationService;
use App\Support\DocumentNumberService;
use App\Support\Money;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

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

        $selected = isset($options['price_items'])
            ? app(SubscriptionPriceSelection::class)->validate($plan, $tenant, $options['price_items']) : null;
        if ($selected !== null && ! empty($options['addon_ids'])) {
            throw ValidationException::withMessages(['addon_ids' => 'Use price_items for all selected add-ons.']);
        }

        $subscription = DB::transaction(function () use ($customer, $tenant, $plan, $options, $selected) {
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
                'grace_period_days' => $options['grace_period_days'] ?? 0,
                'idempotency_key' => $options['idempotency_key'] ?? null,
                'metadata' => [...($options['metadata'] ?? []), 'application_ids' => $options['application_ids'] ?? null],
            ]);

            if ($selected !== null) {
                foreach ($selected as $entry) {
                    $price = $entry['price'];
                    SubscriptionItem::create([
                        'subscription_id' => $subscription->id,
                        'item_type' => $price->plan_id ? SubscriptionItem::TYPE_PLAN : SubscriptionItem::TYPE_ADDON,
                        'plan_id' => $price->plan_id, 'addon_id' => $price->addon_id, 'price_id' => $price->id,
                        'quantity' => $entry['quantity'], 'unit_price' => Money::normalize($price->unit_amount ?? '0.00'),
                        'currency' => $price->currency, 'start_at' => now(),
                        'metadata' => ['selected_pricing' => true, 'price_type' => $price->price_type],
                    ]);
                }
            } else {
                $this->snapshotPlanItem($subscription, $plan);
            }

            foreach ($options['addon_ids'] ?? [] as $addonId) {
                $addon = Addon::query()->find($addonId);
                if ($addon && $addon->isActive()) {
                    $this->snapshotAddonItem($subscription, $addon);
                }
            }

            if (isset($options['application_ids'])) {
                $available = app(SubscriptionApplicationService::class)->applicationIds($subscription->fresh());
                if (array_diff($options['application_ids'], $available)) {
                    throw ValidationException::withMessages(['application_ids' => 'Select applications included in this plan or its add-ons.']);
                }
            }
            app(SubscriptionApplicationService::class)->sync($tenant);

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
                'grace_end_at' => now()->addDays($plan->trial_days + $subscription->grace_period_days),
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
            $periodEnd = $wasSuspended ? $this->periodEnd(now(), $subscription->billing_interval)
                : ($subscription->current_period_end ?? $this->periodEnd(now(), $subscription->billing_interval));

            $subscription->update([
                'status' => Subscription::STATUS_ACTIVE,
                'start_at' => $subscription->start_at ?? now(),
                'current_period_start' => $wasSuspended ? now() : ($subscription->current_period_start ?? now()),
                'current_period_end' => $periodEnd,
                'grace_end_at' => $periodEnd->copy()->addDays($subscription->grace_period_days),
            ]);

            if ($wasSuspended && ! $subscription->entitlements()->where('status', 'EXPIRED')->exists()) {
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

        $selectedItem = $subscription->items()->where('item_type', SubscriptionItem::TYPE_PLAN)->inForce()->with('price')->first();
        $selectedPrice = null;
        if (($selectedItem?->metadata['selected_pricing'] ?? false) === true) {
            $selectedPrice = $this->rating->resolveEffectivePrice('plan_id', $newPlan->id, $subscription->tenant,
                priceType: $selectedItem->metadata['price_type'] ?? null,
                unitName: $selectedItem->price?->unit_name, meterKey: $selectedItem->price?->meter_key);
            if (! $selectedPrice) {
                return 'SELECTED_PRICE_NOT_AVAILABLE';
            }
        }

        DB::transaction(function () use ($subscription, $newPlan, $selectedItem, $selectedPrice) {
            $subscription->items()->where('item_type', SubscriptionItem::TYPE_PLAN)->whereNull('end_at')->update(['end_at' => now()]);

            $subscription->update(['plan_id' => $newPlan->id, 'billing_interval' => $newPlan->billing_interval, 'currency' => $newPlan->currency]);
            if ($selectedPrice) {
                SubscriptionItem::create([
                    'subscription_id' => $subscription->id, 'item_type' => SubscriptionItem::TYPE_PLAN,
                    'plan_id' => $newPlan->id, 'price_id' => $selectedPrice->id,
                    'quantity' => $selectedPrice->price_type === 'FLAT' ? 1 : $selectedItem->quantity,
                    'unit_price' => Money::normalize($selectedPrice->unit_amount ?? '0.00'),
                    'currency' => $newPlan->currency, 'start_at' => now(),
                    'metadata' => ['selected_pricing' => true, 'price_type' => $selectedPrice->price_type],
                ]);
            } else {
                $this->snapshotPlanItem($subscription, $newPlan);
            }

            $this->entitlements->generateFromSubscription($subscription->refresh());
            app(SubscriptionApplicationService::class)->sync($subscription->tenant);
        });

        return null;
    }

    public function renew(Subscription $subscription): ?string
    {
        return DB::transaction(function () use ($subscription) {
            $subscription = Subscription::query()->lockForUpdate()->findOrFail($subscription->id);
            $this->processDue($subscription);
            $subscription->refresh();
            if (! in_array($subscription->status, [Subscription::STATUS_ACTIVE, Subscription::STATUS_PAST_DUE, Subscription::STATUS_GRACE_PERIOD, Subscription::STATUS_EXPIRED], true)) {
                return 'INVALID_SUBSCRIPTION_TRANSITION';
            }
            // An expired period renews from today, never from its elapsed end date.
            $newStart = $subscription->status === Subscription::STATUS_EXPIRED ? now() : ($subscription->current_period_end ?? now());
            $newEnd = $this->periodEnd($newStart, $subscription->billing_interval);
            $subscription->update([
                'status' => Subscription::STATUS_ACTIVE,
                'current_period_start' => $newStart,
                'current_period_end' => $newEnd,
                'grace_end_at' => $newEnd->copy()->addDays($subscription->grace_period_days),
            ]);
            $this->entitlements->generateFromSubscription($subscription);

            return null;
        });
    }

    /** Idempotent deadline processing, serialized against renewal on the same subscription. */
    public function processDue(Subscription $subscription): void
    {
        DB::transaction(function () use ($subscription) {
            $subscription = Subscription::query()->lockForUpdate()->findOrFail($subscription->id);
            if (in_array($subscription->status, Subscription::ACTIVE_LIKE_STATUSES, true)
                && $subscription->current_period_end?->lte(now())) {
                $this->expire($subscription);
                app(AuditService::class)->record('subscription.expired', tenantId: $subscription->tenant_id,
                    resourceType: 'Subscription', resourceId: $subscription->id, source: 'scheduler');
            }
            if ($subscription->status === Subscription::STATUS_EXPIRED && $subscription->grace_end_at?->lte(now())) {
                $this->suspend($subscription);
                app(AuditService::class)->record('subscription.suspended', tenantId: $subscription->tenant_id,
                    resourceType: 'Subscription', resourceId: $subscription->id, source: 'scheduler');
            }
        });
    }

    public function cancel(Subscription $subscription): ?string
    {
        if (! in_array($subscription->status, [...Subscription::ACTIVE_LIKE_STATUSES, Subscription::STATUS_EXPIRED, Subscription::STATUS_SUSPENDED], true)) {
            return 'INVALID_SUBSCRIPTION_TRANSITION';
        }

        DB::transaction(function () use ($subscription) {
            $subscription->update(['status' => Subscription::STATUS_CANCELLED, 'cancelled_at' => now(), 'auto_renew' => false]);
            $this->entitlements->revokeSourcedEntitlements($subscription, 'subscription_cancelled');
            app(SubscriptionApplicationService::class)->sync($subscription->tenant);
            $this->revokeLostAccess($subscription);
        });

        return null;
    }

    public function suspend(Subscription $subscription): ?string
    {
        if (! in_array($subscription->status, [Subscription::STATUS_ACTIVE, Subscription::STATUS_PAST_DUE, Subscription::STATUS_GRACE_PERIOD, Subscription::STATUS_EXPIRED], true)) {
            return 'INVALID_SUBSCRIPTION_TRANSITION';
        }

        DB::transaction(function () use ($subscription) {
            $subscription->update(['status' => Subscription::STATUS_SUSPENDED]);
            $this->entitlements->suspendForSubscription($subscription);
            $this->revokeLostAccess($subscription);
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
            $this->revokeLostAccess($subscription);
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
            app(SubscriptionApplicationService::class)->sync($subscription->tenant);
            $this->revokeLostAccess($subscription);
        });

        return null;
    }

    private function revokeLostAccess(Subscription $subscription): void
    {
        $access = app(SubscriptionAccess::class);
        foreach ($subscription->product->applications as $application) {
            if ($access->required($application) && ! $access->allowed($subscription->tenant, $application)) {
                app(SessionRevocationService::class)
                    ->revokeTenant($subscription->tenant, 'subscription_inactive', $application);
            }
        }
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

    private function periodEnd(\DateTimeInterface $start, string $interval): Carbon
    {
        $start = Carbon::parse($start);

        return match ($interval) {
            'MONTHLY' => $start->copy()->addMonthNoOverflow(),
            'QUARTERLY' => $start->copy()->addMonthsNoOverflow(3),
            'SEMI_ANNUAL' => $start->copy()->addMonthsNoOverflow(6),
            'ANNUAL' => $start->copy()->addYearNoOverflow(),
            default => $start->copy()->addMonthNoOverflow(),
        };
    }
}
