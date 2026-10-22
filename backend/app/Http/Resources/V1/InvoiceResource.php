<?php

namespace App\Http\Resources\V1;

use App\Models\Invoice;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Invoice */
class InvoiceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $this->resource->loadMissing(['tenant', 'customer', 'billing', 'subscription']);

        return [
            'id' => $this->id,
            'billing_number' => $this->billing?->billing_number,
            'customer_name' => $this->customer?->business_name ?: $this->customer?->legal_name,
            'subscription_number' => $this->subscription?->subscription_number,
            'tenant_name' => $this->tenant?->name,
            'invoice_number' => $this->invoice_number,
            'billing_id' => $this->billing_id,
            'customer_id' => $this->customer_id,
            'tenant_id' => $this->tenant_id,
            'subscription_id' => $this->subscription_id,
            'issue_date' => ($this->issue_date ?? $this->issued_at)?->toDateString(),
            'due_date' => $this->due_date,
            'currency' => $this->currency,
            'subtotal' => $this->subtotal,
            'discount_total' => $this->discount_total,
            'tax_total' => $this->tax_total,
            'adjustment_total' => $this->adjustment_total,
            'total' => $this->total,
            'balance_due' => $this->balance_due,
            'status' => $this->status,
            'issued_at' => $this->issued_at,
            'paid_at' => $this->paid_at,
            'cancelled_at' => $this->cancelled_at,
            'metadata' => $this->metadata,
            'items' => InvoiceItemResource::collection($this->whenLoaded('items')),
            'payments' => InvoicePaymentResource::collection($this->whenLoaded('payments')),
            'created_at' => $this->created_at,
        ];
    }
}
