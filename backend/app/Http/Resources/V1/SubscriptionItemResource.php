<?php

namespace App\Http\Resources\V1;

use App\Models\SubscriptionItem;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin SubscriptionItem */
class SubscriptionItemResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $this->resource->loadMissing(['plan', 'addon']);

        return [
            'id' => $this->id,
            'plan_name' => $this->plan?->name,
            'addon_name' => $this->addon?->name,
            'item_type' => $this->item_type,
            'plan_id' => $this->plan_id,
            'addon_id' => $this->addon_id,
            'price_id' => $this->price_id,
            'quantity' => $this->quantity,
            'unit_price' => $this->unit_price,
            'currency' => $this->currency,
            'start_at' => $this->start_at,
            'end_at' => $this->end_at,
        ];
    }
}
