<?php

namespace App\Http\Resources\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Never includes the secret itself (see IntegrationCredential's
 * #[Hidden(['encrypted_secret'])]) - only enough metadata to manage it.
 *
 * @mixin \App\Models\IntegrationCredential
 */
class IntegrationCredentialResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'integration_id' => $this->integration_id,
            'credential_type' => $this->credential_type,
            'reference_label' => $this->reference_label,
            'status' => $this->status,
            'rotated_at' => $this->rotated_at,
            'revoked_at' => $this->revoked_at,
            'created_at' => $this->created_at,
        ];
    }
}
