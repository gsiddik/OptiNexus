<?php

namespace App\Http\Resources\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\NotificationRule */
class NotificationRuleResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'rule_code' => $this->rule_code,
            'name' => $this->name,
            'tenant_id' => $this->tenant_id,
            'application_id' => $this->application_id,
            'trigger_event_key' => $this->trigger_event_key,
            'condition' => $this->condition,
            'recipient_type' => $this->recipient_type,
            'recipient_reference' => $this->recipient_reference,
            'notification_template_id' => $this->notification_template_id,
            'channel' => $this->channel,
            'status' => $this->status,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
