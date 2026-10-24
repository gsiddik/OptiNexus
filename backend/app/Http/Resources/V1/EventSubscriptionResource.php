<?php

namespace App\Http\Resources\V1;

use App\Models\EventSubscription;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin EventSubscription The signing secret is never part of it (shown once, at creation or rotation). */
class EventSubscriptionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'application_id' => $this->application_id,
            'application_code' => $this->application?->application_code,
            'event_key_pattern' => $this->event_key_pattern,
            'tenant_id' => $this->tenant_id,
            'endpoint_url' => $this->endpoint_url,
            'status' => $this->status,
            'secret_rotated_at' => $this->secret_rotated_at,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
