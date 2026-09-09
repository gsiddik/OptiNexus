<?php

namespace App\Services\Commercial;

use App\Models\Price;
use App\Models\Tenant;
use App\Support\Money;
use Illuminate\Support\Carbon;

/**
 * The single rating engine shared by Price Simulation and the Billing
 * Calculation Engine, so a simulated quote and an actual invoice for the
 * same inputs are always computed identically.
 */
class PriceRatingService
{
    /**
     * Resolve the effective price for a plan/addon at a point in time,
     * preferring a tenant-specific override over the standard price. Only
     * considers rows matching the given component identity (price_type +
     * unit_name + meter_key) - see resolveEffectivePrices() for why.
     */
    public function resolveEffectivePrice(string $ownerColumn, string $ownerId, ?Tenant $tenant, ?Carbon $at = null, ?string $priceType = null, ?string $unitName = null, ?string $meterKey = null): ?Price
    {
        $at ??= now();

        $query = Price::query()
            ->where($ownerColumn, $ownerId)
            ->where('status', Price::STATUS_ACTIVE)
            ->where('approval_status', Price::APPROVAL_APPROVED)
            ->where('effective_from', '<=', $at)
            ->where(fn ($q) => $q->whereNull('effective_until')->orWhere('effective_until', '>', $at));

        if ($priceType !== null) {
            $query->where('price_type', $priceType)
                ->where(fn ($q) => $unitName === null ? $q->whereNull('unit_name') : $q->where('unit_name', $unitName))
                ->where(fn ($q) => $meterKey === null ? $q->whereNull('meter_key') : $q->where('meter_key', $meterKey));
        }

        if ($tenant) {
            $override = (clone $query)->where('tenant_id', $tenant->id)->orderByDesc('effective_from')->first();
            if ($override) {
                return $override;
            }
        }

        return $query->whereNull('tenant_id')->orderByDesc('effective_from')->first();
    }

    /**
     * A plan/addon can carry several concurrent price components (e.g. a
     * flat platform fee AND a per-vehicle fee) - each is versioned
     * independently by (price_type, unit_name, meter_key). This resolves
     * one effective row per distinct component identity, so simulate() and
     * the billing engine charge every applicable component exactly once.
     *
     * @return \Illuminate\Support\Collection<int, Price>
     */
    public function resolveEffectivePrices(string $ownerColumn, string $ownerId, ?Tenant $tenant, ?Carbon $at = null): \Illuminate\Support\Collection
    {
        $identities = Price::query()
            ->where($ownerColumn, $ownerId)
            ->select('price_type', 'unit_name', 'meter_key')
            ->distinct()
            ->get();

        return $identities
            ->map(fn ($identity) => $this->resolveEffectivePrice(
                $ownerColumn, $ownerId, $tenant, $at, $identity->price_type, $identity->unit_name, $identity->meter_key,
            ))
            ->filter()
            ->values();
    }

    /**
     * Rate a price against a quantity. Returns a structured breakdown:
     * ['price_id','price_type','quantity','included_quantity','billable_quantity',
     *  'unit_amount','amount','tier_breakdown'].
     */
    public function rate(Price $price, string $quantity = '1'): array
    {
        return match (true) {
            $price->price_type === Price::TYPE_FLAT => $this->rateFlat($price),
            $price->price_type === Price::TYPE_TIERED => $this->rateTiered($price, $quantity),
            default => $this->rateLinear($price, $quantity),
        };
    }

    private function rateFlat(Price $price): array
    {
        $amount = Money::normalize($price->unit_amount ?? '0.00');

        return [
            'price_id' => $price->id,
            'price_type' => $price->price_type,
            'quantity' => '1.0000',
            'included_quantity' => null,
            'billable_quantity' => '1.0000',
            'unit_amount' => $amount,
            'amount' => $amount,
            'tier_breakdown' => null,
        ];
    }

    private function rateLinear(Price $price, string $quantity): array
    {
        $included = $price->included_quantity !== null ? (string) $price->included_quantity : '0.0000';
        $billable = Money::subtractQuantityFloorZero($quantity, $included);
        $unitAmount = Money::normalize($price->unit_amount ?? '0.00');
        $amount = Money::multiplyByQuantity($unitAmount, $billable);

        return [
            'price_id' => $price->id,
            'price_type' => $price->price_type,
            'quantity' => $quantity,
            'included_quantity' => $price->included_quantity !== null ? (string) $price->included_quantity : null,
            'billable_quantity' => $billable,
            'unit_amount' => $unitAmount,
            'amount' => $amount,
            'tier_breakdown' => null,
        ];
    }

    private function rateTiered(Price $price, string $quantity): array
    {
        $tiers = $price->tiers()->get();
        $breakdown = [];
        $total = Money::zero();

        foreach ($tiers as $tier) {
            $from = (string) $tier->from_quantity;

            if (Money::compareQuantity($quantity, $from) <= 0) {
                break;
            }

            $tierTop = $tier->to_quantity !== null ? Money::minQuantity((string) $tier->to_quantity, $quantity) : $quantity;
            $quantityInTier = Money::subtractQuantityFloorZero($tierTop, $from);

            if (Money::compareQuantity($quantityInTier, '0') <= 0) {
                continue;
            }

            $unitAmount = Money::normalize($tier->unit_amount);
            $flatAmount = $tier->flat_amount !== null ? Money::normalize($tier->flat_amount) : Money::zero();
            $tierAmount = Money::add($flatAmount, Money::multiplyByQuantity($unitAmount, $quantityInTier));

            $total = Money::add($total, $tierAmount);
            $breakdown[] = [
                'tier_order' => $tier->tier_order,
                'from_quantity' => $from,
                'to_quantity' => $tier->to_quantity !== null ? (string) $tier->to_quantity : null,
                'quantity_in_tier' => $quantityInTier,
                'unit_amount' => $unitAmount,
                'flat_amount' => $flatAmount,
                'amount' => $tierAmount,
            ];
        }

        return [
            'price_id' => $price->id,
            'price_type' => $price->price_type,
            'quantity' => $quantity,
            'included_quantity' => null,
            'billable_quantity' => $quantity,
            'unit_amount' => null,
            'amount' => $total,
            'tier_breakdown' => $breakdown,
        ];
    }
}
