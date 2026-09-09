<?php

namespace App\Http\Resources\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\WorkflowInstance */
class WorkflowInstanceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'workflow_id' => $this->workflow_id,
            'workflow_version_id' => $this->workflow_version_id,
            'tenant_id' => $this->tenant_id,
            'application_id' => $this->application_id,
            'status' => $this->status,
            'trigger_type' => $this->trigger_type,
            'trigger_event_id' => $this->trigger_event_id,
            'trigger_payload' => $this->trigger_payload,
            'current_step_id' => $this->current_step_id,
            'correlation_id' => $this->correlation_id,
            'causation_id' => $this->causation_id,
            'idempotency_key' => $this->idempotency_key,
            'started_at' => $this->started_at,
            'completed_at' => $this->completed_at,
            'steps' => WorkflowInstanceStepResource::collection($this->whenLoaded('steps')),
            'created_at' => $this->created_at,
        ];
    }
}
