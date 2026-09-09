<?php

namespace App\Http\Resources\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\IntegrationLog */
class IntegrationLogResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'integration_id' => $this->integration_id,
            'correlation_id' => $this->correlation_id,
            'direction' => $this->direction,
            'request_summary' => $this->request_summary,
            'response_status' => $this->response_status,
            'response_summary' => $this->response_summary,
            'duration_ms' => $this->duration_ms,
            'status' => $this->status,
            'error_code' => $this->error_code,
            'error_message' => $this->error_message,
            'attempt_count' => $this->attempt_count,
            'next_retry_at' => $this->next_retry_at,
            'created_at' => $this->created_at,
        ];
    }
}
