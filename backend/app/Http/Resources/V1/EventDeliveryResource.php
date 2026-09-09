<?php

namespace App\Http\Resources\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\EventDelivery */
class EventDeliveryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'event_id' => $this->event_id,
            'consumer_type' => $this->consumer_type,
            'consumer_reference' => $this->consumer_reference,
            'status' => $this->status,
            'attempt_count' => $this->attempt_count,
            'last_error_code' => $this->last_error_code,
            'last_error_message' => $this->last_error_message,
            'next_retry_at' => $this->next_retry_at,
            'delivered_at' => $this->delivered_at,
            'created_at' => $this->created_at,
        ];
    }
}
