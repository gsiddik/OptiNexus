<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'workflow_instance_id', 'workflow_step_id', 'status', 'attempt_count',
    'last_error_code', 'last_error_message', 'next_retry_at', 'input', 'output',
    'started_at', 'completed_at',
])]
class WorkflowInstanceStep extends Model
{
    use HasUuidPrimaryKey;

    public const STATUS_PENDING = 'PENDING';

    public const STATUS_RUNNING = 'RUNNING';

    public const STATUS_WAITING = 'WAITING';

    public const STATUS_COMPLETED = 'COMPLETED';

    public const STATUS_FAILED = 'FAILED';

    public const STATUS_SKIPPED = 'SKIPPED';

    public const STATUS_CANCELLED = 'CANCELLED';

    protected function casts(): array
    {
        return [
            'attempt_count' => 'integer',
            'input' => 'array',
            'output' => 'array',
            'next_retry_at' => 'datetime',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function instance(): BelongsTo
    {
        return $this->belongsTo(WorkflowInstance::class, 'workflow_instance_id');
    }

    public function step(): BelongsTo
    {
        return $this->belongsTo(WorkflowStep::class, 'workflow_step_id');
    }
}
