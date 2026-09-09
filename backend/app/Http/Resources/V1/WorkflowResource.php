<?php

namespace App\Http\Resources\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\Workflow */
class WorkflowResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'workflow_code' => $this->workflow_code,
            'name' => $this->name,
            'description' => $this->description,
            'tenant_id' => $this->tenant_id,
            'application_id' => $this->application_id,
            'status' => $this->status,
            'current_version' => $this->current_version,
            'trigger_type' => $this->trigger_type,
            'trigger_event_key' => $this->trigger_event_key,
            'draft_version' => new WorkflowVersionResource($this->whenLoaded('draftVersion')),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
