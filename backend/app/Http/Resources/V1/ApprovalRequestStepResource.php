<?php

namespace App\Http\Resources\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\ApprovalRequestStep */
class ApprovalRequestStepResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'level_order' => $this->level_order,
            'resolved_approver_user_id' => $this->resolved_approver_user_id,
            'status' => $this->status,
            'decision' => new ApprovalDecisionResource($this->whenLoaded('decision')),
        ];
    }
}
