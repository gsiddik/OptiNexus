<?php

namespace App\Http\Resources\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\WorkflowStep */
class WorkflowStepResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'step_key' => $this->step_key,
            'step_type' => $this->step_type,
            'name' => $this->name,
            'config' => $this->config,
            'sort_order' => $this->sort_order,
        ];
    }
}
