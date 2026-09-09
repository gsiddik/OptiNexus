<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable([
    'rule_code', 'name', 'tenant_id', 'application_id', 'trigger_event_key', 'condition',
    'recipient_type', 'recipient_reference', 'notification_template_id', 'channel', 'status', 'created_by',
])]
class NotificationRule extends Model
{
    use HasUuidPrimaryKey, SoftDeletes;

    public const RECIPIENT_SPECIFIC_USER = 'SPECIFIC_USER';

    public const RECIPIENT_ROLE_MEMBERS = 'ROLE_MEMBERS';

    public const RECIPIENT_TENANT_ADMINS = 'TENANT_ADMINS';

    public const RECIPIENT_CUSTOMER_ADMINS = 'CUSTOMER_ADMINS';

    public const RECIPIENT_APPLICATION_ADMINS = 'APPLICATION_ADMINS';

    public const RECIPIENT_ACTOR = 'ACTOR';

    public const RECIPIENT_RESOURCE_OWNER = 'RESOURCE_OWNER';

    public const RECIPIENT_EVENT_CONTEXT = 'EVENT_CONTEXT';

    public const RECIPIENT_TYPES = [
        self::RECIPIENT_SPECIFIC_USER, self::RECIPIENT_ROLE_MEMBERS, self::RECIPIENT_TENANT_ADMINS,
        self::RECIPIENT_CUSTOMER_ADMINS, self::RECIPIENT_APPLICATION_ADMINS, self::RECIPIENT_ACTOR,
        self::RECIPIENT_RESOURCE_OWNER, self::RECIPIENT_EVENT_CONTEXT,
    ];

    public const STATUS_ACTIVE = 'ACTIVE';

    public const STATUS_INACTIVE = 'INACTIVE';

    public const STATUSES = [self::STATUS_ACTIVE, self::STATUS_INACTIVE];

    protected function casts(): array
    {
        return [
            'condition' => 'array',
        ];
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(NotificationTemplate::class, 'notification_template_id');
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function application(): BelongsTo
    {
        return $this->belongsTo(Application::class);
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }
}
