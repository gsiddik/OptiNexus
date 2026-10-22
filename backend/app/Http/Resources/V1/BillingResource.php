<?php

namespace App\Http\Resources\V1;

use App\Models\Billing;
use App\Models\Invoice;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Billing */
class BillingResource extends JsonResource
{
    private function invoicesBlockingGeneration(): bool
    {
        return Invoice::where('billing_id', $this->id)->whereNotIn('status', ['CANCELLED', 'VOID'])->exists();
    }

    public function toArray(Request $request): array
    {
        $this->resource->loadMissing(['tenant', 'subscription', 'customer', 'invoice']);

        return [
            'id' => $this->id,
            'invoice' => $this->invoice ? ['id' => $this->invoice->id, 'invoice_number' => $this->invoice->invoice_number, 'status' => $this->invoice->status] : null,
            'can_generate_invoice' => $this->status === Billing::STATUS_FINALIZED && ! $this->invoicesBlockingGeneration(),
            'customer_name' => $this->customer?->business_name ?? $this->customer?->legal_name,
            'tenant_name' => $this->tenant?->name,
            'subscription_number' => $this->subscription?->subscription_number,
            'billing_number' => $this->billing_number,
            'customer_id' => $this->customer_id,
            'tenant_id' => $this->tenant_id,
            'subscription_id' => $this->subscription_id,
            'period_start' => $this->period_start,
            'period_end' => $this->period_end,
            'currency' => $this->currency,
            'subtotal' => $this->subtotal,
            'discount_total' => $this->discount_total,
            'tax_total' => $this->tax_total,
            'adjustment_total' => $this->adjustment_total,
            'total' => $this->total,
            'status' => $this->status,
            'calculated_at' => $this->calculated_at,
            'finalized_at' => $this->finalized_at,
            'metadata' => $this->metadata,
            'items' => BillingItemResource::collection($this->whenLoaded('items')),
            'adjustments' => BillingAdjustmentResource::collection($this->whenLoaded('adjustments')),
            'created_at' => $this->created_at,
        ];
    }
}
