<?php

namespace App\Http\Resources\V1;

use App\Models\EmailSuppression;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin EmailSuppression */
class EmailSuppressionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'email' => $this->email,
            'reason' => $this->reason,
            'provider' => $this->provider,
            'description' => $this->description,
            'active' => $this->isActive(),
            'suppressed_at' => $this->suppressed_at,
            'released_at' => $this->released_at,
            'released_by' => $this->whenLoaded('releaser', fn () => $this->releaser ? ['id' => $this->releaser->id, 'name' => $this->releaser->name] : null),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
