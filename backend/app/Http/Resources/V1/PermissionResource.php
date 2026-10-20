<?php

namespace App\Http\Resources\V1;

use App\Models\Permission;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Permission */
class PermissionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $this->resource->loadMissing(['application', 'capability.parent.parent.parent.parent.parent']);
        $module = $this->capability;
        while ($module && $module->type !== 'MODULE' && $module->parent_id) {
            $module = $module->parent;
        }

        return [
            'id' => $this->id,
            'application_id' => $this->application_id,
            'capability_id' => $this->capability_id,
            'permission_key' => $this->permission_key,
            'application_name' => $this->application?->name,
            'module_name' => $module?->name ?? explode('.', $this->permission_key)[1] ?? 'General',
            'name' => $this->name,
            'description' => $this->description,
            'status' => $this->status,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
