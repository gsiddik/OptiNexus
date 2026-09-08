<?php

namespace App\Http\Resources\V1;

use App\Models\Application;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Application */
class ApplicationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'application_code' => $this->application_code,
            'name' => $this->name,
            'description' => $this->description,
            'owner' => $this->owner,
            'version' => $this->version,
            'status' => $this->status,
            'frontend_url' => $this->frontend_url,
            'backend_url' => $this->backend_url,
            'metadata' => $this->metadata,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
