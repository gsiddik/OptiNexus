<?php

namespace App\Http\Resources\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\Event */
class EventResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'event_id' => $this->id,
            'event_key' => $this->event_key,
            'event_version' => $this->event_version,
            'occurred_at' => $this->occurred_at,
            'source_application_id' => $this->source_application_id,
            'tenant_id' => $this->tenant_id,
            'correlation_id' => $this->correlation_id,
            'causation_id' => $this->causation_id,
            'data' => $this->data,
            'created_at' => $this->created_at,
        ];
    }
}
