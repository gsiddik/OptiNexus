<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'subscription_id', 'item_type', 'plan_id', 'addon_id', 'price_id',
    'quantity', 'unit_price', 'currency', 'start_at', 'end_at', 'metadata',
])]
class SubscriptionItem extends Model
{
    use HasUuidPrimaryKey;

    public const TYPE_PLAN = 'PLAN';

    public const TYPE_ADDON = 'ADDON';

    public const TYPES = [self::TYPE_PLAN, self::TYPE_ADDON];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:4',
            'unit_price' => 'decimal:2',
            'start_at' => 'datetime',
            'end_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    public function addon(): BelongsTo
    {
        return $this->belongsTo(Addon::class);
    }

    public function price(): BelongsTo
    {
        return $this->belongsTo(Price::class);
    }
}
