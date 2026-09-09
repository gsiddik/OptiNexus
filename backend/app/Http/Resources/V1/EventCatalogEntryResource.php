<?php

namespace App\Http\Resources\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\EventCatalogEntry */
class EventCatalogEntryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'event_key' => $this->event_key,
            'application_id' => $this->application_id,
            'name' => $this->name,
            'description' => $this->description,
            'schema_version' => $this->schema_version,
            'payload_schema' => $this->payload_schema,
            'status' => $this->status,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
