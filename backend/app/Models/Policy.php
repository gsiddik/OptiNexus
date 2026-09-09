<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable([
    'policy_code', 'name', 'description', 'policy_type', 'tenant_id', 'application_id',
    'priority', 'effect', 'status', 'condition_definition', 'effective_from', 'effective_until',
    'version', 'created_by', 'approved_by',
])]
class Policy extends Model
{
    use HasUuidPrimaryKey, SoftDeletes;

    public const TYPE_AUTHORIZATION = 'AUTHORIZATION';

    public const TYPE_WORKFLOW_ROUTING = 'WORKFLOW_ROUTING';

    public const TYPE_APPROVAL = 'APPROVAL';

    public const TYPE_FEATURE = 'FEATURE';

    public const TYPE_INTEGRATION = 'INTEGRATION';

    public const TYPE_COMMERCIAL = 'COMMERCIAL';

    public const TYPE_SECURITY = 'SECURITY';

    public const TYPES = [
        self::TYPE_AUTHORIZATION, self::TYPE_WORKFLOW_ROUTING, self::TYPE_APPROVAL,
        self::TYPE_FEATURE, self::TYPE_INTEGRATION, self::TYPE_COMMERCIAL, self::TYPE_SECURITY,
    ];

    public const EFFECT_ALLOW = 'ALLOW';

    public const EFFECT_DENY = 'DENY';

    public const EFFECT_MATCH = 'MATCH';

    public const EFFECTS = [self::EFFECT_ALLOW, self::EFFECT_DENY, self::EFFECT_MATCH];

    public const STATUS_DRAFT = 'DRAFT';

    public const STATUS_ACTIVE = 'ACTIVE';

    public const STATUS_INACTIVE = 'INACTIVE';

    public const STATUS_DEPRECATED = 'DEPRECATED';

    public const STATUSES = [self::STATUS_DRAFT, self::STATUS_ACTIVE, self::STATUS_INACTIVE, self::STATUS_DEPRECATED];

    protected function casts(): array
    {
        return [
            'condition_definition' => 'array',
            'priority' => 'integer',
            'version' => 'integer',
            'effective_from' => 'datetime',
            'effective_until' => 'datetime',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function application(): BelongsTo
    {
        return $this->belongsTo(Application::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    public function isEffectiveAt(?\DateTimeInterface $at = null): bool
    {
        $at ??= now();

        if (! $this->isActive()) {
            return false;
        }

        if ($this->effective_from && $this->effective_from->gt($at)) {
            return false;
        }

        return ! ($this->effective_until && $this->effective_until->lte($at));
    }
}
