<?php

namespace App\Http\Resources\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\Entitlement */
class EntitlementResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'tenant_id' => $this->tenant_id,
            'subscription_id' => $this->subscription_id,
            'application_id' => $this->application_id,
            'capability_id' => $this->capability_id,
            'entitlement_type' => $this->entitlement_type,
            'entitlement_key' => $this->entitlement_key,
            'value' => $this->value === 'unlimited' ? 'unlimited' : $this->decodedValue(),
            'status' => $this->status,
            'source_type' => $this->source_type,
            'source_reference_id' => $this->source_reference_id,
            'effective_from' => $this->effective_from,
            'effective_until' => $this->effective_until,
            'reason' => $this->reason,
            'created_by' => $this->created_by,
            'created_at' => $this->created_at,
        ];
    }
}
