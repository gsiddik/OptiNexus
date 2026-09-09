<?php

namespace App\Http\Resources\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\ApprovalDecision */
class ApprovalDecisionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'decided_by' => $this->decided_by,
            'decision' => $this->decision,
            'comment' => $this->comment,
            'decided_at' => $this->decided_at,
        ];
    }
}
