<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['notification_id', 'attempt_number', 'status', 'error_message'])]
class NotificationDelivery extends Model
{
    use HasUuidPrimaryKey;

    public $timestamps = false;

    public const STATUS_SUCCESS = 'SUCCESS';

    public const STATUS_FAILED = 'FAILED';

    protected static function booted(): void
    {
        static::creating(function (NotificationDelivery $delivery) {
            $delivery->created_at ??= now();
        });
    }

    public function notification(): BelongsTo
    {
        return $this->belongsTo(Notification::class);
    }
}
