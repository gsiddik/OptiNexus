<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable([
    'billing_number', 'customer_id', 'tenant_id', 'subscription_id', 'period_start',
    'period_end', 'currency', 'subtotal', 'discount_total', 'tax_total', 'adjustment_total',
    'total', 'status', 'calculated_at', 'finalized_at', 'metadata',
])]
class Billing extends Model
{
    use HasUuidPrimaryKey;

    public const STATUS_DRAFT = 'DRAFT';

    public const STATUS_CALCULATED = 'CALCULATED';

    public const STATUS_REVIEWED = 'REVIEWED';

    public const STATUS_FINALIZED = 'FINALIZED';

    public const STATUS_CANCELLED = 'CANCELLED';

    public const STATUSES = [
        self::STATUS_DRAFT, self::STATUS_CALCULATED, self::STATUS_REVIEWED,
        self::STATUS_FINALIZED, self::STATUS_CANCELLED,
    ];

    protected function casts(): array
    {
        return [
            'period_start' => 'datetime',
            'period_end' => 'datetime',
            'subtotal' => 'decimal:2',
            'discount_total' => 'decimal:2',
            'tax_total' => 'decimal:2',
            'adjustment_total' => 'decimal:2',
            'total' => 'decimal:2',
            'calculated_at' => 'datetime',
            'finalized_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(BillingItem::class);
    }

    public function adjustments(): HasMany
    {
        return $this->hasMany(BillingAdjustment::class);
    }

    public function invoice(): HasOne
    {
        return $this->hasOne(Invoice::class);
    }

    public function isFinalized(): bool
    {
        return $this->status === self::STATUS_FINALIZED;
    }
}
