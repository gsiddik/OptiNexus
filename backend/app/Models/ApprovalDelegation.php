<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['tenant_id', 'delegator_user_id', 'delegate_user_id', 'approval_definition_id', 'starts_at', 'ends_at', 'status', 'revoked_at'])]
class ApprovalDelegation extends Model
{
    use HasUuidPrimaryKey;

    public $timestamps = false;

    public const STATUS_ACTIVE = 'ACTIVE';

    public const STATUS_REVOKED = 'REVOKED';

    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'created_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $d) {
            $d->created_at ??= now();
        });
    }

    public function delegator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'delegator_user_id');
    }

    public function delegate(): BelongsTo
    {
        return $this->belongsTo(User::class, 'delegate_user_id');
    }

    public function isActiveNow(): bool
    {
        if ($this->status !== self::STATUS_ACTIVE) {
            return false;
        }
        if ($this->starts_at && $this->starts_at->isFuture()) {
            return false;
        }

        return ! ($this->ends_at && $this->ends_at->isPast());
    }
}
