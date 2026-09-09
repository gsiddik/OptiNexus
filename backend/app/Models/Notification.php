<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'tenant_id', 'application_id', 'notification_rule_id', 'notification_template_id',
    'recipient_user_id', 'channel_target', 'channel', 'subject', 'body', 'status',
    'correlation_id', 'causation_id', 'attempt_count', 'last_error_code', 'last_error_message',
    'next_retry_at', 'sent_at', 'created_by',
])]
class Notification extends Model
{
    use HasUuidPrimaryKey;

    public const STATUS_QUEUED = 'QUEUED';

    public const STATUS_PROCESSING = 'PROCESSING';

    public const STATUS_SENT = 'SENT';

    public const STATUS_FAILED = 'FAILED';

    public const STATUS_CANCELLED = 'CANCELLED';

    public const OPEN_STATUSES = [self::STATUS_QUEUED, self::STATUS_PROCESSING];

    public const TERMINAL_STATUSES = [self::STATUS_SENT, self::STATUS_FAILED, self::STATUS_CANCELLED];

    protected function casts(): array
    {
        return [
            'attempt_count' => 'integer',
            'next_retry_at' => 'datetime',
            'sent_at' => 'datetime',
        ];
    }

    public function rule(): BelongsTo
    {
        return $this->belongsTo(NotificationRule::class, 'notification_rule_id');
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(NotificationTemplate::class, 'notification_template_id');
    }

    public function recipient(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recipient_user_id');
    }

    public function deliveries(): HasMany
    {
        return $this->hasMany(NotificationDelivery::class);
    }

    public function isOpen(): bool
    {
        return in_array($this->status, self::OPEN_STATUSES, true);
    }
}
