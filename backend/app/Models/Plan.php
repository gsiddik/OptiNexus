<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable([
    'product_id', 'plan_code', 'name', 'description', 'billing_interval',
    'status', 'trial_days', 'currency', 'metadata',
])]
class Plan extends Model
{
    use HasFactory, HasUuidPrimaryKey, SoftDeletes;

    public const STATUS_DRAFT = 'DRAFT';

    public const STATUS_ACTIVE = 'ACTIVE';

    public const STATUS_INACTIVE = 'INACTIVE';

    public const STATUS_RETIRED = 'RETIRED';

    public const STATUSES = [self::STATUS_DRAFT, self::STATUS_ACTIVE, self::STATUS_INACTIVE, self::STATUS_RETIRED];

    public const INTERVAL_MONTHLY = 'MONTHLY';

    public const INTERVAL_QUARTERLY = 'QUARTERLY';

    public const INTERVAL_SEMI_ANNUAL = 'SEMI_ANNUAL';

    public const INTERVAL_ANNUAL = 'ANNUAL';

    public const INTERVAL_CUSTOM = 'CUSTOM';

    public const INTERVALS = [
        self::INTERVAL_MONTHLY, self::INTERVAL_QUARTERLY, self::INTERVAL_SEMI_ANNUAL,
        self::INTERVAL_ANNUAL, self::INTERVAL_CUSTOM,
    ];

    protected function casts(): array
    {
        return ['metadata' => 'array'];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function capabilities(): BelongsToMany
    {
        return $this->belongsToMany(Capability::class, 'plan_capabilities')->withTimestamps();
    }

    public function limits(): HasMany
    {
        return $this->hasMany(PlanLimit::class);
    }

    public function prices(): HasMany
    {
        return $this->hasMany(Price::class);
    }

    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }
}
