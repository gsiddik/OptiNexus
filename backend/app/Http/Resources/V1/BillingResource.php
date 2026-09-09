<?php

namespace App\Http\Resources\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\Billing */
class BillingResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'billing_number' => $this->billing_number,
            'customer_id' => $this->customer_id,
            'tenant_id' => $this->tenant_id,
            'subscription_id' => $this->subscription_id,
            'period_start' => $this->period_start,
            'period_end' => $this->period_end,
            'currency' => $this->currency,
            'subtotal' => $this->subtotal,
            'discount_total' => $this->discount_total,
            'tax_total' => $this->tax_total,
            'adjustment_total' => $this->adjustment_total,
            'total' => $this->total,
            'status' => $this->status,
            'calculated_at' => $this->calculated_at,
            'finalized_at' => $this->finalized_at,
            'metadata' => $this->metadata,
            'items' => BillingItemResource::collection($this->whenLoaded('items')),
            'adjustments' => BillingAdjustmentResource::collection($this->whenLoaded('adjustments')),
            'created_at' => $this->created_at,
        ];
    }
}
