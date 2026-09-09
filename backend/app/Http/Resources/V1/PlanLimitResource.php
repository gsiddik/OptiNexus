<?php

namespace App\Http\Resources\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\PlanLimit */
class PlanLimitResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'plan_id' => $this->plan_id,
            'limit_key' => $this->limit_key,
            'limit_value' => $this->is_unlimited ? null : $this->limit_value,
            'is_unlimited' => $this->is_unlimited,
            'unit' => $this->unit,
        ];
    }
}
