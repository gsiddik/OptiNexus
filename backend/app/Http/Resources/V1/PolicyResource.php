<?php

namespace App\Http\Resources\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\Policy */
class PolicyResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'policy_code' => $this->policy_code,
            'name' => $this->name,
            'description' => $this->description,
            'policy_type' => $this->policy_type,
            'tenant_id' => $this->tenant_id,
            'application_id' => $this->application_id,
            'priority' => $this->priority,
            'effect' => $this->effect,
            'status' => $this->status,
            'condition_definition' => $this->condition_definition,
            'effective_from' => $this->effective_from,
            'effective_until' => $this->effective_until,
            'version' => $this->version,
            'created_by' => $this->created_by,
            'approved_by' => $this->approved_by,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
