<?php

namespace App\Http\Resources\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\ApprovalDelegation */
class ApprovalDelegationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'tenant_id' => $this->tenant_id,
            'delegator_user_id' => $this->delegator_user_id,
            'delegate_user_id' => $this->delegate_user_id,
            'approval_definition_id' => $this->approval_definition_id,
            'starts_at' => $this->starts_at,
            'ends_at' => $this->ends_at,
            'status' => $this->status,
            'created_at' => $this->created_at,
        ];
    }
}
