<?php

namespace App\Http\Resources\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\Integration */
class IntegrationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'integration_code' => $this->integration_code,
            'name' => $this->name,
            'tenant_id' => $this->tenant_id,
            'source_application_id' => $this->source_application_id,
            'target_application_id' => $this->target_application_id,
            'integration_type' => $this->integration_type,
            'status' => $this->status,
            'base_url' => $this->base_url,
            'timeout_seconds' => $this->timeout_seconds,
            'retry_policy' => $this->retry_policy,
            'metadata' => $this->metadata,
            'endpoints' => IntegrationEndpointResource::collection($this->whenLoaded('endpoints')),
            'has_active_credential' => (bool) $this->whenLoaded('credentials', fn () => $this->credentials->contains(fn ($c) => $c->status === 'ACTIVE'), false),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
