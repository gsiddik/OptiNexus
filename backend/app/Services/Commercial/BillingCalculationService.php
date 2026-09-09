<?php

namespace App\Services\Commercial;

use App\Models\Billing;
use App\Models\BillingItem;
use App\Models\Price;
use App\Models\Subscription;
use App\Models\SubscriptionItem;
use App\Models\UsageEvent;
use App\Support\DocumentNumberService;
use App\Support\Money;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Deterministic, auditable rating of a subscription's recurring + usage
 * charges for a billing period into Billing/BillingItem records. Shares
 * PriceRatingService with the Price Simulation API so a quote and the
 * eventual charge for identical inputs always agree. Every monetary
 * computation goes through App\Support\Money - no floats.
 */
class BillingCalculationService
{
    public function __construct(
        private readonly PriceRatingService $rating,
        private readonly DocumentNumberService $numbers,
    ) {}

    /**
     * Creates (or recalculates, while not yet finalized) the Billing for a
     * subscription's period. Returns [Billing|null, ?errorCode].
     */
    public function calculate(Subscription $subscription, Carbon $periodStart, Carbon $periodEnd): array
    {
        $existing = Billing::query()
            ->where('subscription_id', $subscription->id)
            ->where('period_start', $periodStart)
            ->where('period_end', $periodEnd)
            ->where('status', '!=', Billing::STATUS_CANCELLED)
            ->first();

        if ($existing && $existing->isFinalized()) {
            return [null, 'BILLING_ALREADY_FINALIZED'];
        }

        $billing = DB::transaction(function () use ($subscription, $periodStart, $periodEnd, $existing) {
            $billing = $existing ?? Billing::create([
                'billing_number' => $this->numbers->nextBillingNumber(),
                'customer_id' => $subscription->customer_id,
                'tenant_id' => $subscription->tenant_id,
                'subscription_id' => $subscription->id,
                'period_start' => $periodStart,
                'period_end' => $periodEnd,
                'currency' => $subscription->currency,
                'status' => Billing::STATUS_DRAFT,
            ]);

            $billing->items()->delete();

            $subtotal = Money::zero();
            $tax = Money::zero();

            $this->chargePlanRecurring($billing, $subscription, $subtotal, $tax);
            $this->chargeAddonRecurring($billing, $subscription, $periodStart, $periodEnd, $subtotal, $tax);
            $this->chargeUsageComponents($billing, $subscription, 'plan_id', $subscription->plan_id, $periodStart, $periodEnd, $subtotal, $tax);

            foreach ($subscription->items()->where('item_type', SubscriptionItem::TYPE_ADDON)->get() as $item) {
                if ($item->addon_id) {
                    $this->chargeUsageComponents($billing, $subscription, 'addon_id', $item->addon_id, $periodStart, $periodEnd, $subtotal, $tax);
                }
            }

            $adjustmentTotal = Money::sum($billing->adjustments()->pluck('amount')->all() ?: ['0.00']);
            $total = Money::add(Money::add($subtotal, $tax), $adjustmentTotal);

            $billing->update([
                'subtotal' => $subtotal,
                'discount_total' => Money::zero(),
                'tax_total' => $tax,
                'adjustment_total' => $adjustmentTotal,
                'total' => $total,
                'status' => Billing::STATUS_CALCULATED,
                'calculated_at' => now(),
            ]);

            return $billing;
        });

        return [$billing->fresh('items'), null];
    }

    private function chargePlanRecurring(Billing $billing, Subscription $subscription, string &$subtotal, string &$tax): void
    {
        $price = $this->rating->resolveEffectivePrice('plan_id', $subscription->plan_id, $subscription->tenant);
        if (! $price || $price->price_type !== Price::TYPE_FLAT) {
            return;
        }

        $rated = $this->rating->rate($price, '1');
        $this->writeItem($billing, BillingItem::TYPE_RECURRING, "Plan: {$subscription->plan->name}", $price, $rated, $subtotal, $tax, null);
    }

    private function chargeAddonRecurring(Billing $billing, Subscription $subscription, Carbon $periodStart, Carbon $periodEnd, string &$subtotal, string &$tax): void
    {
        $items = $subscription->items()->where('item_type', SubscriptionItem::TYPE_ADDON)
            ->where('start_at', '<=', $periodEnd)
            ->where(fn ($q) => $q->whereNull('end_at')->orWhere('end_at', '>=', $periodStart))
            ->with('addon')
            ->get();

        foreach ($items as $item) {
            $price = $this->rating->resolveEffectivePrice('addon_id', $item->addon_id, $subscription->tenant);
            if (! $price || $price->price_type !== Price::TYPE_FLAT) {
                continue;
            }

            $rated = $this->rating->rate($price, (string) $item->quantity);
            $rated['amount'] = Money::multiplyByQuantity($rated['amount'], (string) $item->quantity);
            $rated['billable_quantity'] = (string) $item->quantity;
            $rated['unit_amount'] = $price->unit_amount;
            $this->writeItem($billing, BillingItem::TYPE_ADDON, "Add-on: {$item->addon?->name}", $price, $rated, $subtotal, $tax, $item->id);
        }
    }

    private function chargeUsageComponents(Billing $billing, Subscription $subscription, string $ownerColumn, string $ownerId, Carbon $periodStart, Carbon $periodEnd, string &$subtotal, string &$tax): void
    {
        foreach ($this->rating->resolveEffectivePrices($ownerColumn, $ownerId, $subscription->tenant) as $price) {
            if ($price->price_type === Price::TYPE_FLAT) {
                continue;
            }

            $meterKey = $price->meter_key ?? $price->unit_name;
            $quantity = $meterKey
                ? (string) UsageEvent::query()
                    ->where('subscription_id', $subscription->id)
                    ->where('meter_key', $meterKey)
                    ->whereBetween('usage_timestamp', [$periodStart, $periodEnd])
                    ->sum('quantity')
                : '0';

            if ($price->minimum_quantity !== null) {
                $quantity = Money::compareQuantity($quantity, (string) $price->minimum_quantity) < 0 ? (string) $price->minimum_quantity : $quantity;
            }

            if (Money::compareQuantity($quantity, '0') <= 0) {
                continue;
            }

            $rated = $this->rating->rate($price, $quantity);

            if (Money::compare($rated['amount'], Money::zero()) <= 0) {
                continue;
            }

            $chargeType = $price->included_quantity !== null && Money::compare((string) $price->included_quantity, '0') > 0
                ? BillingItem::TYPE_OVERAGE
                : BillingItem::TYPE_USAGE;

            $description = ($meterKey ?? strtolower($price->price_type)).' usage';
            $this->writeItem($billing, $chargeType, ucfirst($description), $price, $rated, $subtotal, $tax, null);
        }
    }

    private function writeItem(Billing $billing, string $chargeType, string $description, Price $price, array $rated, string &$subtotal, string &$tax, ?string $subscriptionItemId): void
    {
        $lineTax = Money::zero();
        if ($price->taxCode && $price->taxCode->status === 'ACTIVE') {
            $lineTax = Money::applyPercentage($rated['amount'], (string) $price->taxCode->rate);
        }

        BillingItem::create([
            'billing_id' => $billing->id,
            'subscription_item_id' => $subscriptionItemId,
            'charge_type' => $chargeType,
            'description' => $description,
            'quantity' => $rated['billable_quantity'] ?? '1',
            'unit_amount' => $rated['unit_amount'] ?? $rated['amount'],
            'subtotal' => $rated['amount'],
            'discount_amount' => Money::zero(),
            'tax_amount' => $lineTax,
            'total' => Money::add($rated['amount'], $lineTax),
            'pricing_snapshot' => [
                'price_id' => $price->id,
                'price_type' => $price->price_type,
                'unit_amount' => $price->unit_amount,
                'included_quantity' => $price->included_quantity,
                'tax_code' => $price->taxCode?->tax_code,
                'tax_rate' => $price->taxCode?->rate,
                'tier_breakdown' => $rated['tier_breakdown'] ?? null,
                'rated_at' => now()->toIso8601String(),
            ],
        ]);

        $subtotal = Money::add($subtotal, $rated['amount']);
        $tax = Money::add($tax, $lineTax);
    }
}
