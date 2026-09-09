<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Immutable ingested/produced fact record - see events table comment. */
#[Fillable([
    'id', 'event_key', 'event_version', 'occurred_at', 'source_application_id',
    'producer_service_account_id', 'tenant_id', 'correlation_id', 'causation_id', 'data',
])]
class Event extends Model
{
    use HasUuidPrimaryKey;

    public $incrementing = false;

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'data' => 'array',
            'occurred_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $event) {
            $event->created_at ??= now();
        });
    }

    public function sourceApplication(): BelongsTo
    {
        return $this->belongsTo(Application::class, 'source_application_id');
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function deliveries(): HasMany
    {
        return $this->hasMany(EventDelivery::class);
    }
}
