<?php

namespace App\Http\Resources\V1;

use App\Models\Plan;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Plan */
class PlanResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $this->resource->loadMissing(['product']);

        return [
            'id' => $this->id,
            'product_name' => $this->product?->name,
            'product_id' => $this->product_id,
            'plan_code' => $this->plan_code,
            'name' => $this->name,
            'description' => $this->description,
            'billing_interval' => $this->billing_interval,
            'status' => $this->status,
            'trial_days' => $this->trial_days,
            'currency' => $this->currency,
            'metadata' => $this->metadata,
            'capabilities_count' => $this->whenCounted('capabilities'),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
