<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable([
    'template_code', 'name', 'channel', 'subject_template', 'body_template',
    'language', 'version', 'status', 'created_by',
])]
class NotificationTemplate extends Model
{
    use HasUuidPrimaryKey, SoftDeletes;

    public const CHANNEL_EMAIL = 'EMAIL';

    public const CHANNEL_IN_APP = 'IN_APP';

    public const CHANNEL_WEBHOOK = 'WEBHOOK';

    public const CHANNELS = [self::CHANNEL_EMAIL, self::CHANNEL_IN_APP, self::CHANNEL_WEBHOOK];

    public const STATUS_DRAFT = 'DRAFT';

    public const STATUS_ACTIVE = 'ACTIVE';

    public const STATUS_INACTIVE = 'INACTIVE';

    public const STATUSES = [self::STATUS_DRAFT, self::STATUS_ACTIVE, self::STATUS_INACTIVE];

    protected function casts(): array
    {
        return [
            'version' => 'integer',
        ];
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }
}
