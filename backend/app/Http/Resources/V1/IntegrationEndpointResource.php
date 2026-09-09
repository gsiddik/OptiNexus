<?php

namespace App\Http\Resources\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\IntegrationEndpoint */
class IntegrationEndpointResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'endpoint_key' => $this->endpoint_key,
            'method' => $this->method,
            'path' => $this->path,
        ];
    }
}
