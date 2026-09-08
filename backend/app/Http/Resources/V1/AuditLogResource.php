<?php

namespace App\Http\Resources\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\AuditLog */
class AuditLogResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'actor_user_id' => $this->actor_user_id,
            'actor_identity' => $this->actor_identity,
            'tenant_id' => $this->tenant_id,
            'customer_id' => $this->customer_id,
            'application_id' => $this->application_id,
            'action' => $this->action,
            'resource_type' => $this->resource_type,
            'resource_id' => $this->resource_id,
            'old_value' => $this->old_value,
            'new_value' => $this->new_value,
            'ip_address' => $this->ip_address,
            'request_id' => $this->request_id,
            'correlation_id' => $this->correlation_id,
            'source' => $this->source,
            'metadata' => $this->metadata,
            'created_at' => $this->created_at,
        ];
    }
}
