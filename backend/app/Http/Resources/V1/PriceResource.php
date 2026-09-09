<?php

namespace App\Http\Resources\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\Price */
class PriceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'plan_id' => $this->plan_id,
            'addon_id' => $this->addon_id,
            'tenant_id' => $this->tenant_id,
            'price_type' => $this->price_type,
            'currency' => $this->currency,
            'unit_amount' => $this->unit_amount,
            'billing_interval' => $this->billing_interval,
            'unit_name' => $this->unit_name,
            'meter_key' => $this->meter_key,
            'minimum_quantity' => $this->minimum_quantity,
            'included_quantity' => $this->included_quantity,
            'tax_code_id' => $this->tax_code_id,
            'status' => $this->status,
            'approval_status' => $this->approval_status,
            'effective_from' => $this->effective_from,
            'effective_until' => $this->effective_until,
            'created_by' => $this->created_by,
            'approved_by' => $this->approved_by,
            'metadata' => $this->metadata,
            'tiers' => PricingTierResource::collection($this->whenLoaded('tiers')),
            'created_at' => $this->created_at,
        ];
    }
}
