<?php

namespace App\Http\Resources\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\FeatureFlagOverride */
class FeatureFlagOverrideResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'feature_flag_id' => $this->feature_flag_id,
            'scope_type' => $this->scope_type,
            'scope_id' => $this->scope_id,
            'value' => $this->value,
            'effective_from' => $this->effective_from,
            'effective_until' => $this->effective_until,
            'created_at' => $this->created_at,
        ];
    }
}
