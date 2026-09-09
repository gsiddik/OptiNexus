<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\V1\Concerns\ApiResponses;
use App\Http\Controllers\Controller;
use App\Http\Requests\V1\PricingSimulateRequest;
use App\Models\Addon;
use App\Models\Plan;
use App\Models\Price;
use App\Models\Tenant;
use App\Services\Commercial\PriceRatingService;
use App\Support\Money;
use Illuminate\Http\JsonResponse;

/**
 * Pure, read-only pricing quotation. Never writes billing records - see
 * PriceRatingService for the shared rating logic also used by the real
 * Billing Calculation Engine, so a quote here matches the eventual charge.
 */
class PricingSimulationController extends Controller
{
    use ApiResponses;

    public function __construct(private readonly PriceRatingService $rating) {}

    public function simulate(PricingSimulateRequest $request): JsonResponse
    {
        $plan = Plan::findOrFail($request->input('plan_id'));
        $tenant = $request->input('tenant_id') ? Tenant::find($request->input('tenant_id')) : null;
        $quantities = $request->input('quantities', []);
        $addonIds = $request->input('addon_ids', []);

        $components = [];
        $subtotal = Money::zero();
        $tax = Money::zero();

        foreach ($this->rating->resolveEffectivePrices('plan_id', $plan->id, $tenant) as $price) {
            $this->addComponent($components, $subtotal, $tax, $price, $quantities);
        }

        foreach (Addon::query()->whereIn('id', $addonIds)->get() as $addon) {
            foreach ($this->rating->resolveEffectivePrices('addon_id', $addon->id, $tenant) as $price) {
                $this->addComponent($components, $subtotal, $tax, $price, $quantities, $addon->addon_code);
            }
        }

        $total = Money::add($subtotal, $tax);

        return $this->ok([
            'currency' => $plan->currency,
            'subtotal' => $subtotal,
            'components' => $components,
            'tax' => $tax,
            'total' => $total,
        ]);
    }

    private function addComponent(array &$components, string &$subtotal, string &$tax, Price $price, array $quantities, ?string $codeOverride = null): void
    {
        $quantityKey = $price->meter_key ?? $price->unit_name;
        $quantity = $price->price_type === Price::TYPE_FLAT ? '1' : (string) ($quantities[$quantityKey] ?? 0);

        $rated = $this->rating->rate($price, $quantity);

        $code = $codeOverride ?? ($price->price_type === Price::TYPE_FLAT ? 'platform_fee' : ($quantityKey ? "{$quantityKey}_fee" : strtolower($price->price_type)));

        $component = [
            'code' => $code,
            'price_id' => $price->id,
            'amount' => $rated['amount'],
        ];

        if ($price->price_type !== Price::TYPE_FLAT) {
            $component['quantity'] = $rated['quantity'];
            $component['included_quantity'] = $rated['included_quantity'];
            $component['billable_quantity'] = $rated['billable_quantity'];
            $component['unit_amount'] = $rated['unit_amount'];
            if ($rated['tier_breakdown'] !== null) {
                $component['tier_breakdown'] = $rated['tier_breakdown'];
            }
        }

        $subtotal = Money::add($subtotal, $rated['amount']);

        if ($price->taxCode && $price->taxCode->status === 'ACTIVE') {
            $lineTax = Money::applyPercentage($rated['amount'], (string) $price->taxCode->rate);
            $component['tax'] = $lineTax;
            $tax = Money::add($tax, $lineTax);
        }

        $components[] = $component;
    }
}
