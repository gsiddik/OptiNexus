<?php

namespace App\Http\Resources\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\PricingTier */
class PricingTierResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'tier_order' => $this->tier_order,
            'from_quantity' => $this->from_quantity,
            'to_quantity' => $this->to_quantity,
            'unit_amount' => $this->unit_amount,
            'flat_amount' => $this->flat_amount,
        ];
    }
}
