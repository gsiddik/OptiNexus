<?php

namespace App\Http\Resources\V1;

use App\Models\Customer;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Customer */
class CustomerResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'customer_code' => $this->customer_code,
            'legal_name' => $this->legal_name,
            'business_name' => $this->business_name,
            'tax_id' => $this->tax_id,
            'industry' => $this->industry,
            'email' => $this->email,
            'phone' => $this->phone,
            'billing_address' => $this->billing_address,
            'status' => $this->status,
            'metadata' => $this->metadata,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
