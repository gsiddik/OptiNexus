<?php

namespace App\Http\Resources\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\ApprovalRequest */
class ApprovalRequestResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'approval_definition_id' => $this->approval_definition_id,
            'tenant_id' => $this->tenant_id,
            'application_id' => $this->application_id,
            'workflow_instance_id' => $this->workflow_instance_id,
            'requested_by' => $this->requested_by,
            'subject_type' => $this->subject_type,
            'subject_id' => $this->subject_id,
            'status' => $this->status,
            'current_level' => $this->current_level,
            'context' => $this->context,
            'correlation_id' => $this->correlation_id,
            'expires_at' => $this->expires_at,
            'decided_at' => $this->decided_at,
            'steps' => ApprovalRequestStepResource::collection($this->whenLoaded('steps')),
            'created_at' => $this->created_at,
        ];
    }
}
