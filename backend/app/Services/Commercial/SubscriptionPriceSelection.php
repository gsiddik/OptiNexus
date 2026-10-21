<?php

namespace App\Services\Commercial;

use App\Models\Plan;
use App\Models\Price;
use App\Models\Tenant;
use Illuminate\Validation\ValidationException;

/** Validates purchased quantities against the effective approved catalog, never client amounts. */
class SubscriptionPriceSelection
{
    public const TYPES = ['FLAT', 'PER_USER', 'PER_DEVICE', 'PER_VEHICLE', 'PER_TRANSACTION', 'PER_API_CALL'];

    public function __construct(private readonly PriceRatingService $rating) {}

    public function options(Tenant $tenant)
    {
        return Price::query()->with(['plan.product', 'addon.product'])
            ->whereIn('price_type', self::TYPES)->where('status', Price::STATUS_ACTIVE)
            ->where('approval_status', Price::APPROVAL_APPROVED)
            ->where(fn ($query) => $query->whereNull('tenant_id')->orWhere('tenant_id', $tenant->id))
            ->get()->filter(fn (Price $price) => $this->isSelectable($price, $tenant))->values();
    }

    public function validate(Plan $plan, Tenant $tenant, array $items): array
    {
        $result = [];
        $base = 0;
        $seen = [];
        foreach ($items as $index => $item) {
            $price = Price::query()->with(['plan.product', 'addon.product'])->find($item['price_id']);
            if (! $price || ! $this->isSelectable($price, $tenant)) {
                throw ValidationException::withMessages(["price_items.$index.price_id" => 'Select a current approved price for this tenant.']);
            }
            if ($price->currency !== $plan->currency || $price->billing_interval !== $plan->billing_interval ||
                ($price->plan_id && $price->plan_id !== $plan->id) ||
                ($price->addon?->product_id && $price->addon->product_id !== $plan->product_id)) {
                throw ValidationException::withMessages(["price_items.$index.price_id" => 'The selected prices must share one base plan, product, currency and billing interval.']);
            }
            if (isset($seen[$price->id])) {
                throw ValidationException::withMessages(["price_items.$index.price_id" => 'This price is already selected.']);
            }
            $ownerKey = $price->plan_id ? 'plan' : 'addon:'.$price->addon_id;
            if (isset($seen[$ownerKey])) {
                throw ValidationException::withMessages(["price_items.$index.price_id" => 'Select one price type per plan or add-on.']);
            }
            $seen[$ownerKey] = true;
            $seen[$price->id] = true;
            $base += $price->plan_id ? 1 : 0;
            $quantity = $price->price_type === 'FLAT' ? 1 : ($item['quantity'] ?? null);
            if (! is_numeric($quantity) || (float) $quantity < 1 || (float) $quantity > 100000000 || floor((float) $quantity) !== (float) $quantity) {
                throw ValidationException::withMessages(["price_items.$index.quantity" => 'Enter a whole quantity between 1 and 100000000.']);
            }
            $result[] = ['price' => $price, 'quantity' => (int) $quantity];
        }
        if ($base !== 1) {
            throw ValidationException::withMessages(['price_items' => 'Select exactly one base plan price; add-on prices are optional additional items.']);
        }

        return $result;
    }

    private function isSelectable(Price $price, Tenant $tenant): bool
    {
        $owner = $price->plan ?? $price->addon;
        if (! in_array($price->price_type, self::TYPES, true) || ! $price->isEffectiveAt() || ! $owner?->isActive() ||
            ($owner->product_id && ! $owner->product?->isActive()) ||
            ($price->tenant_id && $price->tenant_id !== $tenant->id)) {
            return false;
        }

        return $this->rating->resolveEffectivePrice($price->plan_id ? 'plan_id' : 'addon_id',
            $price->plan_id ?? $price->addon_id, $tenant, priceType: $price->price_type,
            unitName: $price->unit_name, meterKey: $price->meter_key)?->id === $price->id;
    }
}
