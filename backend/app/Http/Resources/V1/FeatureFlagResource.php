<?php

namespace App\Http\Resources\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\FeatureFlag */
class FeatureFlagResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'flag_key' => $this->flag_key,
            'application_id' => $this->application_id,
            'name' => $this->name,
            'description' => $this->description,
            'flag_type' => $this->flag_type,
            'default_value' => $this->default_value,
            'status' => $this->status,
            'overrides' => FeatureFlagOverrideResource::collection($this->whenLoaded('overrides')),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
