<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

#[Fillable([
    'plan_id', 'addon_id', 'tenant_id', 'price_type', 'currency', 'unit_amount',
    'billing_interval', 'unit_name', 'meter_key', 'minimum_quantity', 'included_quantity',
    'tax_code_id', 'status', 'approval_status', 'effective_from', 'effective_until',
    'created_by', 'approved_by', 'metadata',
])]
class Price extends Model
{
    use HasUuidPrimaryKey;

    public const TYPE_FLAT = 'FLAT';

    public const TYPE_PER_USER = 'PER_USER';

    public const TYPE_PER_DEVICE = 'PER_DEVICE';

    public const TYPE_PER_VEHICLE = 'PER_VEHICLE';

    public const TYPE_PER_TRANSACTION = 'PER_TRANSACTION';

    public const TYPE_PER_API_CALL = 'PER_API_CALL';

    public const TYPE_USAGE = 'USAGE';

    public const TYPE_TIERED = 'TIERED';

    public const TYPES = [
        self::TYPE_FLAT, self::TYPE_PER_USER, self::TYPE_PER_DEVICE, self::TYPE_PER_VEHICLE,
        self::TYPE_PER_TRANSACTION, self::TYPE_PER_API_CALL, self::TYPE_USAGE, self::TYPE_TIERED,
    ];

    /** Per-unit price types that scale linearly with a quantity. */
    public const PER_UNIT_TYPES = [
        self::TYPE_PER_USER, self::TYPE_PER_DEVICE, self::TYPE_PER_VEHICLE,
        self::TYPE_PER_TRANSACTION, self::TYPE_PER_API_CALL, self::TYPE_USAGE,
    ];

    public const STATUS_ACTIVE = 'ACTIVE';

    public const STATUS_RETIRED = 'RETIRED';

    public const APPROVAL_DRAFT = 'DRAFT';

    public const APPROVAL_PENDING = 'PENDING_APPROVAL';

    public const APPROVAL_APPROVED = 'APPROVED';

    public const APPROVAL_REJECTED = 'REJECTED';

    protected function casts(): array
    {
        return [
            'unit_amount' => 'decimal:2',
            'minimum_quantity' => 'decimal:4',
            'included_quantity' => 'decimal:4',
            'effective_from' => 'datetime',
            'effective_until' => 'datetime',
            'metadata' => 'array',
        ];
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    public function addon(): BelongsTo
    {
        return $this->belongsTo(Addon::class);
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function taxCode(): BelongsTo
    {
        return $this->belongsTo(TaxCode::class);
    }

    public function tiers(): HasMany
    {
        return $this->hasMany(PricingTier::class)->orderBy('tier_order');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function isEffectiveAt(?Carbon $at = null): bool
    {
        $at ??= now();

        if ($this->status !== self::STATUS_ACTIVE || $this->approval_status !== self::APPROVAL_APPROVED) {
            return false;
        }

        if ($this->effective_from && $this->effective_from->gt($at)) {
            return false;
        }

        return ! ($this->effective_until && $this->effective_until->lte($at));
    }
}
