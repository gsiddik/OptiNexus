<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'tenant_id', 'application_id', 'email', 'name', 'locale', 'role_ids', 'token_hash', 'status', 'invited_by', 'expires_at',
    'last_sent_at', 'send_count', 'delivery_status', 'delivery_error_code', 'delivery_attempted_at', 'accepted_at',
    'accepted_user_id', 'revoked_at', 'revoked_by', 'revoke_reason',
])]
#[Hidden(['token_hash'])]
class UserInvitation extends Model
{
    use HasUuidPrimaryKey;

    public const STATUS_PENDING = 'PENDING';

    public const STATUS_ACCEPTED = 'ACCEPTED';

    public const STATUS_REVOKED = 'REVOKED';

    public const STATUS_EXPIRED = 'EXPIRED';

    public const STATUSES = [self::STATUS_PENDING, self::STATUS_ACCEPTED, self::STATUS_REVOKED, self::STATUS_EXPIRED];

    public const DELIVERY_QUEUED = 'QUEUED';

    public const DELIVERY_SENT = 'SENT';

    public const DELIVERY_FAILED = 'FAILED';

    protected function casts(): array
    {
        return [
            'role_ids' => 'array',
            'expires_at' => 'datetime',
            'last_sent_at' => 'datetime',
            'delivery_attempted_at' => 'datetime',
            'accepted_at' => 'datetime',
            'revoked_at' => 'datetime',
            'send_count' => 'integer',
        ];
    }

    /** SHA-256 of the raw token: the only form in which a token is ever stored or compared. */
    public static function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function application(): BelongsTo
    {
        return $this->belongsTo(Application::class);
    }

    public function inviter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invited_by');
    }

    public function isOpen(): bool
    {
        return $this->status === self::STATUS_PENDING && $this->expires_at->isFuture();
    }

    /** The state callers and pages see: an unaccepted invitation past its expiry is EXPIRED even before the sweeper stamps it. */
    public function effectiveStatus(): string
    {
        return $this->status === self::STATUS_PENDING && $this->expires_at->isPast() ? self::STATUS_EXPIRED : $this->status;
    }
}
