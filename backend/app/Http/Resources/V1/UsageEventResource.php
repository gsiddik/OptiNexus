<?php

namespace App\Http\Resources\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\UsageEvent */
class UsageEventResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'tenant_id' => $this->tenant_id,
            'application_id' => $this->application_id,
            'subscription_id' => $this->subscription_id,
            'meter_key' => $this->meter_key,
            'quantity' => $this->quantity,
            'usage_timestamp' => $this->usage_timestamp,
            'period_start' => $this->period_start,
            'period_end' => $this->period_end,
            'source' => $this->source,
            'external_reference' => $this->external_reference,
            'idempotency_key' => $this->idempotency_key,
            'metadata' => $this->metadata,
            'created_at' => $this->created_at,
        ];
    }
}
