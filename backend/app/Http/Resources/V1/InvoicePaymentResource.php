<?php

namespace App\Http\Resources\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\InvoicePayment */
class InvoicePaymentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'invoice_id' => $this->invoice_id,
            'invoice_number' => $this->invoice->invoice_number,
            'tenant_id' => $this->invoice->tenant_id,
            'status' => $this->status,
            'bank_name' => $this->bank_name,
            'account_name' => $this->account_name,
            'note' => $this->note,
            'verification_note' => $this->verification_note,
            'verified_at' => $this->verified_at,
            'proof' => $this->proof ? collect($this->proof)->except('path')->all() : null,
            'amount' => $this->amount,
            'paid_at' => $this->paid_at,
            'method' => $this->method,
            'reference' => $this->reference,
        ];
    }
}
