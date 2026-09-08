<?php

namespace App\Http\Resources\V1;

use App\Models\Role;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Role */
class RoleResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'tenant_id' => $this->tenant_id,
            'application_id' => $this->application_id,
            'name' => $this->name,
            'code' => $this->code,
            'description' => $this->description,
            'role_type' => $this->role_type,
            'is_system' => $this->is_system,
            'status' => $this->status,
            'permissions_count' => $this->whenCounted('permissions'),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
