<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable([
    'workflow_code', 'name', 'description', 'tenant_id', 'application_id',
    'status', 'current_version', 'trigger_type', 'trigger_event_key', 'created_by',
])]
class Workflow extends Model
{
    use HasUuidPrimaryKey, SoftDeletes;

    public const STATUS_DRAFT = 'DRAFT';

    public const STATUS_ACTIVE = 'ACTIVE';

    public const STATUS_INACTIVE = 'INACTIVE';

    public const STATUS_DEPRECATED = 'DEPRECATED';

    public const STATUSES = [self::STATUS_DRAFT, self::STATUS_ACTIVE, self::STATUS_INACTIVE, self::STATUS_DEPRECATED];

    public const TRIGGER_EVENT = 'EVENT';

    public const TRIGGER_API = 'API';

    public const TRIGGER_MANUAL = 'MANUAL';

    public const TRIGGER_SCHEDULED = 'SCHEDULED';

    public const TRIGGERS = [self::TRIGGER_EVENT, self::TRIGGER_API, self::TRIGGER_MANUAL, self::TRIGGER_SCHEDULED];

    protected function casts(): array
    {
        return ['current_version' => 'integer'];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function application(): BelongsTo
    {
        return $this->belongsTo(Application::class);
    }

    public function versions(): HasMany
    {
        return $this->hasMany(WorkflowVersion::class);
    }

    public function activeVersionRelation(): HasOne
    {
        return $this->hasOne(WorkflowVersion::class)->where('status', WorkflowVersion::STATUS_ACTIVE);
    }

    /**
     * Plain ordered HasOne rather than latestOfMany('version'): Eloquent's
     * "one of many" relations always additionally MAX() the related
     * model's primary key to build their subquery, and Postgres has no
     * MAX(uuid) aggregate - every model here uses a UUID PK, so ofMany()/
     * latestOfMany()/oldestOfMany() are unusable anywhere in this app.
     * ORDER BY + implicit LIMIT 1 gives the same result for both direct
     * access and eager loading (Eloquent's HasOne::match() takes the
     * first row per parent from the already-ordered result set).
     */
    public function draftVersion(): HasOne
    {
        return $this->hasOne(WorkflowVersion::class)->where('status', WorkflowVersion::STATUS_DRAFT)->orderByDesc('version');
    }

    public function activeVersion(): ?WorkflowVersion
    {
        return $this->versions()->where('status', WorkflowVersion::STATUS_ACTIVE)->first();
    }

    public function instances(): HasMany
    {
        return $this->hasMany(WorkflowInstance::class);
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }
}
