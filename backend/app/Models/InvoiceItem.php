<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'invoice_id', 'description', 'quantity', 'unit_amount', 'subtotal',
    'discount_amount', 'tax_amount', 'total', 'source_billing_item_id', 'metadata',
])]
class InvoiceItem extends Model
{
    use HasUuidPrimaryKey;

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:4',
            'unit_amount' => 'decimal:2',
            'subtotal' => 'decimal:2',
            'discount_amount' => 'decimal:2',
            'tax_amount' => 'decimal:2',
            'total' => 'decimal:2',
            'metadata' => 'array',
        ];
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function sourceBillingItem(): BelongsTo
    {
        return $this->belongsTo(BillingItem::class, 'source_billing_item_id');
    }
}
