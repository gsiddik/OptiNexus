<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'email', 'reason', 'provider', 'provider_message_id', 'description', 'suppressed_at', 'released_at', 'released_by',
])]
class EmailSuppression extends Model
{
    use HasUuidPrimaryKey;

    public const REASON_HARD_BOUNCE = 'HARD_BOUNCE';

    public const REASON_SPAM_COMPLAINT = 'SPAM_COMPLAINT';

    public const REASON_MANUAL = 'MANUAL_SUPPRESSION';

    protected function casts(): array
    {
        return [
            'suppressed_at' => 'datetime',
            'released_at' => 'datetime',
        ];
    }

    public function releaser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'released_by');
    }

    public function isActive(): bool
    {
        return $this->released_at === null;
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('released_at');
    }

    public static function blocks(string $email): bool
    {
        return static::query()->active()->where('email', mb_strtolower(trim($email)))->exists();
    }
}
