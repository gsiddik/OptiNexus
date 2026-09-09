<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'event_id', 'consumer_type', 'consumer_reference', 'status', 'attempt_count',
    'last_error_code', 'last_error_message', 'next_retry_at', 'delivered_at',
])]
class EventDelivery extends Model
{
    use HasUuidPrimaryKey;

    public const CONSUMER_WORKFLOW = 'WORKFLOW';

    public const CONSUMER_NOTIFICATION = 'NOTIFICATION';

    public const CONSUMER_WEBHOOK = 'WEBHOOK';

    public const CONSUMER_INTEGRATION = 'INTEGRATION';

    public const STATUS_PENDING = 'PENDING';

    public const STATUS_DELIVERED = 'DELIVERED';

    public const STATUS_FAILED = 'FAILED';

    public const STATUS_DISCARDED = 'DISCARDED';

    public const STATUSES = [self::STATUS_PENDING, self::STATUS_DELIVERED, self::STATUS_FAILED, self::STATUS_DISCARDED];

    protected function casts(): array
    {
        return [
            'attempt_count' => 'integer',
            'next_retry_at' => 'datetime',
            'delivered_at' => 'datetime',
        ];
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }
}
