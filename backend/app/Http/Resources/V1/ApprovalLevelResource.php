<?php

namespace App\Http\Resources\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\ApprovalLevel */
class ApprovalLevelResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'level_order' => $this->level_order,
            'name' => $this->name,
            'approver_type' => $this->approver_type,
            'approver_reference' => $this->approver_reference,
            'condition' => $this->condition,
        ];
    }
}
