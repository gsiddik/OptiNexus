<?php

namespace App\Http\Resources\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\Subscription */
class SubscriptionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'subscription_number' => $this->subscription_number,
            'customer_id' => $this->customer_id,
            'tenant_id' => $this->tenant_id,
            'product_id' => $this->product_id,
            'plan_id' => $this->plan_id,
            'status' => $this->status,
            'start_at' => $this->start_at,
            'current_period_start' => $this->current_period_start,
            'current_period_end' => $this->current_period_end,
            'trial_end_at' => $this->trial_end_at,
            'grace_end_at' => $this->grace_end_at,
            'cancel_at' => $this->cancel_at,
            'cancelled_at' => $this->cancelled_at,
            'currency' => $this->currency,
            'billing_interval' => $this->billing_interval,
            'auto_renew' => $this->auto_renew,
            'metadata' => $this->metadata,
            'items' => SubscriptionItemResource::collection($this->whenLoaded('items')),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
