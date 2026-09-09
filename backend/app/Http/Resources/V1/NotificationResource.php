<?php

namespace App\Http\Resources\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\Notification */
class NotificationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'tenant_id' => $this->tenant_id,
            'application_id' => $this->application_id,
            'notification_rule_id' => $this->notification_rule_id,
            'notification_template_id' => $this->notification_template_id,
            'recipient_user_id' => $this->recipient_user_id,
            'channel' => $this->channel,
            'subject' => $this->subject,
            'body' => $this->body,
            'status' => $this->status,
            'correlation_id' => $this->correlation_id,
            'causation_id' => $this->causation_id,
            'attempt_count' => $this->attempt_count,
            'last_error_code' => $this->last_error_code,
            'last_error_message' => $this->last_error_message,
            'next_retry_at' => $this->next_retry_at,
            'sent_at' => $this->sent_at,
            'deliveries' => NotificationDeliveryResource::collection($this->whenLoaded('deliveries')),
            'created_at' => $this->created_at,
        ];
    }
}
