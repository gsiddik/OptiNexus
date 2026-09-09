<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'workflow_id', 'workflow_version_id', 'tenant_id', 'application_id', 'status',
    'trigger_type', 'trigger_event_id', 'trigger_payload', 'current_step_id',
    'correlation_id', 'causation_id', 'idempotency_key', 'started_by', 'started_at', 'completed_at',
])]
class WorkflowInstance extends Model
{
    use HasUuidPrimaryKey;

    public const STATUS_PENDING = 'PENDING';

    public const STATUS_RUNNING = 'RUNNING';

    public const STATUS_WAITING = 'WAITING';

    public const STATUS_WAITING_APPROVAL = 'WAITING_APPROVAL';

    public const STATUS_COMPLETED = 'COMPLETED';

    public const STATUS_FAILED = 'FAILED';

    public const STATUS_CANCELLED = 'CANCELLED';

    public const TERMINAL_STATUSES = [self::STATUS_COMPLETED, self::STATUS_FAILED, self::STATUS_CANCELLED];

    public const OPEN_STATUSES = [self::STATUS_PENDING, self::STATUS_RUNNING, self::STATUS_WAITING, self::STATUS_WAITING_APPROVAL];

    protected function casts(): array
    {
        return [
            'trigger_payload' => 'array',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function workflow(): BelongsTo
    {
        return $this->belongsTo(Workflow::class);
    }

    public function version(): BelongsTo
    {
        return $this->belongsTo(WorkflowVersion::class, 'workflow_version_id');
    }

    public function currentStep(): BelongsTo
    {
        return $this->belongsTo(WorkflowStep::class, 'current_step_id');
    }

    public function steps(): HasMany
    {
        return $this->hasMany(WorkflowInstanceStep::class)->orderBy('created_at');
    }

    public function isOpen(): bool
    {
        return in_array($this->status, self::OPEN_STATUSES, true);
    }
}
