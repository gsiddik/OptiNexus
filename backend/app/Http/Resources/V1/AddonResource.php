<?php

namespace App\Http\Resources\V1;

use App\Models\Addon;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Addon */
class AddonResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $this->resource->loadMissing(['product']);

        return [
            'id' => $this->id,
            'product_name' => $this->product?->name,
            'product_id' => $this->product_id,
            'addon_code' => $this->addon_code,
            'name' => $this->name,
            'description' => $this->description,
            'status' => $this->status,
            'metadata' => $this->metadata,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
