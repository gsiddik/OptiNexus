<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'invoice_number', 'billing_id', 'customer_id', 'tenant_id', 'subscription_id',
    'issue_date', 'due_date', 'currency', 'subtotal', 'discount_total', 'tax_total',
    'adjustment_total', 'total', 'balance_due', 'status', 'issued_at', 'paid_at',
    'cancelled_at', 'metadata',
])]
class Invoice extends Model
{
    use HasUuidPrimaryKey;

    public const STATUS_DRAFT = 'DRAFT';

    public const STATUS_ISSUED = 'ISSUED';

    public const STATUS_PARTIALLY_PAID = 'PARTIALLY_PAID';

    public const STATUS_PAID = 'PAID';

    public const STATUS_OVERDUE = 'OVERDUE';

    public const STATUS_VOID = 'VOID';

    public const STATUS_CANCELLED = 'CANCELLED';

    public const STATUSES = [
        self::STATUS_DRAFT, self::STATUS_ISSUED, self::STATUS_PARTIALLY_PAID,
        self::STATUS_PAID, self::STATUS_OVERDUE, self::STATUS_VOID, self::STATUS_CANCELLED,
    ];

    /** Statuses in which financial totals are locked (issued financial snapshot). */
    public const LOCKED_STATUSES = [
        self::STATUS_ISSUED, self::STATUS_PARTIALLY_PAID, self::STATUS_PAID,
        self::STATUS_OVERDUE, self::STATUS_VOID, self::STATUS_CANCELLED,
    ];

    protected function casts(): array
    {
        return [
            'issue_date' => 'date',
            'due_date' => 'date',
            'subtotal' => 'decimal:2',
            'discount_total' => 'decimal:2',
            'tax_total' => 'decimal:2',
            'adjustment_total' => 'decimal:2',
            'total' => 'decimal:2',
            'balance_due' => 'decimal:2',
            'issued_at' => 'datetime',
            'paid_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    public function billing(): BelongsTo
    {
        return $this->belongsTo(Billing::class);
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
        return $this->hasMany(InvoiceItem::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(InvoicePayment::class);
    }

    public function isLocked(): bool
    {
        return in_array($this->status, self::LOCKED_STATUSES, true);
    }
}
