<?php

namespace App\Http\Resources\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\WorkflowInstanceStep */
class WorkflowInstanceStepResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'workflow_step_id' => $this->workflow_step_id,
            'step_key' => $this->step?->step_key,
            'step_type' => $this->step?->step_type,
            'status' => $this->status,
            'attempt_count' => $this->attempt_count,
            'last_error_code' => $this->last_error_code,
            'last_error_message' => $this->last_error_message,
            'next_retry_at' => $this->next_retry_at,
            'output' => $this->output,
            'started_at' => $this->started_at,
            'completed_at' => $this->completed_at,
        ];
    }
}
