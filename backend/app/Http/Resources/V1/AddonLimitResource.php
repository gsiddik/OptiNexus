<?php

namespace App\Http\Resources\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\AddonLimit */
class AddonLimitResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'addon_id' => $this->addon_id,
            'limit_key' => $this->limit_key,
            'limit_delta' => $this->limit_delta,
            'unit' => $this->unit,
        ];
    }
}
