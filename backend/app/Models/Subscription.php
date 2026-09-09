<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable([
    'subscription_number', 'customer_id', 'tenant_id', 'product_id', 'plan_id', 'status',
    'start_at', 'current_period_start', 'current_period_end', 'trial_end_at', 'grace_end_at',
    'cancel_at', 'cancelled_at', 'currency', 'billing_interval', 'auto_renew',
    'idempotency_key', 'metadata',
])]
class Subscription extends Model
{
    use HasFactory, HasUuidPrimaryKey, SoftDeletes;

    public const STATUS_DRAFT = 'DRAFT';

    public const STATUS_PENDING = 'PENDING';

    public const STATUS_TRIAL = 'TRIAL';

    public const STATUS_ACTIVE = 'ACTIVE';

    public const STATUS_PAST_DUE = 'PAST_DUE';

    public const STATUS_GRACE_PERIOD = 'GRACE_PERIOD';

    public const STATUS_SUSPENDED = 'SUSPENDED';

    public const STATUS_EXPIRED = 'EXPIRED';

    public const STATUS_CANCELLED = 'CANCELLED';

    public const STATUS_TERMINATED = 'TERMINATED';

    /** Statuses in which the subscription is considered commercially "live". */
    public const ACTIVE_LIKE_STATUSES = [
        self::STATUS_TRIAL, self::STATUS_ACTIVE, self::STATUS_PAST_DUE, self::STATUS_GRACE_PERIOD,
    ];

    protected function casts(): array
    {
        return [
            'start_at' => 'datetime',
            'current_period_start' => 'datetime',
            'current_period_end' => 'datetime',
            'trial_end_at' => 'datetime',
            'grace_end_at' => 'datetime',
            'cancel_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'auto_renew' => 'boolean',
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

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(SubscriptionItem::class);
    }

    public function entitlements(): HasMany
    {
        return $this->hasMany(Entitlement::class);
    }

    public function billings(): HasMany
    {
        return $this->hasMany(Billing::class);
    }

    public function isActiveLike(): bool
    {
        return in_array($this->status, self::ACTIVE_LIKE_STATUSES, true);
    }
}
