<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'tenant_id', 'application_id', 'subscription_id', 'meter_key', 'quantity',
    'usage_timestamp', 'period_start', 'period_end', 'source', 'external_reference',
    'idempotency_key', 'metadata',
])]
class UsageEvent extends Model
{
    use HasUuidPrimaryKey;

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:4',
            'usage_timestamp' => 'datetime',
            'period_start' => 'datetime',
            'period_end' => 'datetime',
            'metadata' => 'array',
            'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $event) {
            $event->created_at ??= now();
        });
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function application(): BelongsTo
    {
        return $this->belongsTo(Application::class);
    }

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }
}
