<?php

namespace App\Http\Resources\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\WorkflowTransition */
class WorkflowTransitionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'from_step_id' => $this->from_step_id,
            'to_step_id' => $this->to_step_id,
            'condition' => $this->condition,
            'sort_order' => $this->sort_order,
        ];
    }
}
