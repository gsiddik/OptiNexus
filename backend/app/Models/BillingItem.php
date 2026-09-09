<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'billing_id', 'subscription_item_id', 'charge_type', 'description', 'quantity',
    'unit_amount', 'subtotal', 'discount_amount', 'tax_amount', 'total',
    'pricing_snapshot', 'usage_reference', 'metadata',
])]
class BillingItem extends Model
{
    use HasUuidPrimaryKey;

    public const TYPE_RECURRING = 'RECURRING';

    public const TYPE_USAGE = 'USAGE';

    public const TYPE_OVERAGE = 'OVERAGE';

    public const TYPE_ADDON = 'ADDON';

    public const TYPE_ADJUSTMENT = 'ADJUSTMENT';

    public const TYPE_TAX = 'TAX';

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:4',
            'unit_amount' => 'decimal:2',
            'subtotal' => 'decimal:2',
            'discount_amount' => 'decimal:2',
            'tax_amount' => 'decimal:2',
            'total' => 'decimal:2',
            'pricing_snapshot' => 'array',
            'metadata' => 'array',
        ];
    }

    public function billing(): BelongsTo
    {
        return $this->belongsTo(Billing::class);
    }

    public function subscriptionItem(): BelongsTo
    {
        return $this->belongsTo(SubscriptionItem::class);
    }
}
