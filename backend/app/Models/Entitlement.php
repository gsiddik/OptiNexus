<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'tenant_id', 'subscription_id', 'application_id', 'capability_id',
    'entitlement_type', 'entitlement_key', 'value', 'status', 'source_type',
    'source_reference_id', 'effective_from', 'effective_until', 'created_by',
    'reason', 'metadata',
])]
class Entitlement extends Model
{
    use HasUuidPrimaryKey;

    public const TYPE_APPLICATION = 'APPLICATION';

    public const TYPE_CAPABILITY = 'CAPABILITY';

    public const TYPE_LIMIT = 'LIMIT';

    public const TYPES = [self::TYPE_APPLICATION, self::TYPE_CAPABILITY, self::TYPE_LIMIT];

    public const SOURCE_PLAN = 'PLAN';

    public const SOURCE_ADDON = 'ADDON';

    public const SOURCE_MANUAL_OVERRIDE = 'MANUAL_OVERRIDE';

    public const SOURCE_SYSTEM = 'SYSTEM';

    public const SOURCES = [self::SOURCE_PLAN, self::SOURCE_ADDON, self::SOURCE_MANUAL_OVERRIDE, self::SOURCE_SYSTEM];

    public const STATUS_ACTIVE = 'ACTIVE';

    public const STATUS_SUSPENDED = 'SUSPENDED';

    public const STATUS_EXPIRED = 'EXPIRED';

    public const STATUS_REVOKED = 'REVOKED';

    protected function casts(): array
    {
        return [
            'effective_from' => 'datetime',
            'effective_until' => 'datetime',
            'metadata' => 'array',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }

    public function application(): BelongsTo
    {
        return $this->belongsTo(Application::class);
    }

    public function capability(): BelongsTo
    {
        return $this->belongsTo(Capability::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function isActiveNow(): bool
    {
        if ($this->status !== self::STATUS_ACTIVE) {
            return false;
        }

        $now = now();
        if ($this->effective_from && $this->effective_from->gt($now)) {
            return false;
        }

        return ! ($this->effective_until && $this->effective_until->lte($now));
    }

    /** Type-aware decode of the string `value` column. */
    public function decodedValue(): bool|string|float
    {
        if ($this->entitlement_type === self::TYPE_LIMIT) {
            return $this->value === 'unlimited' ? INF : (float) $this->value;
        }

        if (in_array($this->value, ['true', 'false'], true)) {
            return $this->value === 'true';
        }

        return $this->value;
    }
}
