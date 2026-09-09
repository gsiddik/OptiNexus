<?php

namespace App\Http\Resources\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\WorkflowVersion */
class WorkflowVersionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'workflow_id' => $this->workflow_id,
            'version' => $this->version,
            'status' => $this->status,
            'activated_at' => $this->activated_at,
            'retired_at' => $this->retired_at,
            'steps' => WorkflowStepResource::collection($this->whenLoaded('steps')),
            'transitions' => WorkflowTransitionResource::collection($this->whenLoaded('transitions')),
            'created_at' => $this->created_at,
        ];
    }
}
