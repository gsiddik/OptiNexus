<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'approval_definition_id', 'tenant_id', 'application_id', 'workflow_instance_id', 'requested_by',
    'subject_type', 'subject_id', 'status', 'current_level', 'context', 'correlation_id',
    'expires_at', 'decided_at',
])]
class ApprovalRequest extends Model
{
    use HasUuidPrimaryKey;

    public const STATUS_PENDING = 'PENDING';

    public const STATUS_IN_PROGRESS = 'IN_PROGRESS';

    public const STATUS_APPROVED = 'APPROVED';

    public const STATUS_REJECTED = 'REJECTED';

    public const STATUS_RETURNED = 'RETURNED';

    public const STATUS_CANCELLED = 'CANCELLED';

    public const STATUS_EXPIRED = 'EXPIRED';

    public const OPEN_STATUSES = [self::STATUS_PENDING, self::STATUS_IN_PROGRESS];

    public const TERMINAL_STATUSES = [self::STATUS_APPROVED, self::STATUS_REJECTED, self::STATUS_RETURNED, self::STATUS_CANCELLED, self::STATUS_EXPIRED];

    protected function casts(): array
    {
        return [
            'current_level' => 'integer',
            'context' => 'array',
            'expires_at' => 'datetime',
            'decided_at' => 'datetime',
        ];
    }

    public function definition(): BelongsTo
    {
        return $this->belongsTo(ApprovalDefinition::class, 'approval_definition_id');
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function workflowInstance(): BelongsTo
    {
        return $this->belongsTo(WorkflowInstance::class, 'workflow_instance_id');
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function steps(): HasMany
    {
        return $this->hasMany(ApprovalRequestStep::class)->orderBy('level_order');
    }

    public function isOpen(): bool
    {
        return in_array($this->status, self::OPEN_STATUSES, true);
    }

    public function isExpired(): bool
    {
        return $this->expires_at && $this->expires_at->isPast();
    }
}
