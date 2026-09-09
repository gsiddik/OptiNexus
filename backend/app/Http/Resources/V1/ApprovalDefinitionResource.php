<?php

namespace App\Http\Resources\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\ApprovalDefinition */
class ApprovalDefinitionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'definition_code' => $this->definition_code,
            'name' => $this->name,
            'description' => $this->description,
            'tenant_id' => $this->tenant_id,
            'application_id' => $this->application_id,
            'rule_type' => $this->rule_type,
            'status' => $this->status,
            'self_approval_allowed' => $this->self_approval_allowed,
            'expires_after_hours' => $this->expires_after_hours,
            'levels' => ApprovalLevelResource::collection($this->whenLoaded('levels')),
            'created_at' => $this->created_at,
        ];
    }
}
