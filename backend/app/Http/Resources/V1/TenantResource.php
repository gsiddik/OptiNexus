<?php

namespace App\Http\Resources\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\Tenant */
class TenantResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'tenant_code' => $this->tenant_code,
            'customer_id' => $this->customer_id,
            'name' => $this->name,
            'region' => $this->region,
            'timezone' => $this->timezone,
            'currency' => $this->currency,
            'language' => $this->language,
            'status' => $this->status,
            'metadata' => $this->metadata,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
