<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'integration_id', 'workflow_instance_step_id', 'correlation_id', 'direction', 'request_summary',
    'response_status', 'response_summary', 'duration_ms', 'status', 'error_code', 'error_message',
    'attempt_count', 'next_retry_at',
])]
class IntegrationLog extends Model
{
    use HasUuidPrimaryKey;

    public $timestamps = false;

    public const DIRECTION_OUTBOUND = 'OUTBOUND';

    public const DIRECTION_INBOUND = 'INBOUND';

    public const STATUS_SUCCESS = 'SUCCESS';

    public const STATUS_FAILED = 'FAILED';

    public const STATUS_TIMEOUT = 'TIMEOUT';

    public const STATUS_PENDING = 'PENDING';

    protected function casts(): array
    {
        return [
            'request_summary' => 'array',
            'response_summary' => 'array',
            'response_status' => 'integer',
            'duration_ms' => 'integer',
            'attempt_count' => 'integer',
            'next_retry_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $log) {
            $log->created_at ??= now();
        });
    }

    public function integration(): BelongsTo
    {
        return $this->belongsTo(Integration::class);
    }

    public function instanceStep(): BelongsTo
    {
        return $this->belongsTo(WorkflowInstanceStep::class, 'workflow_instance_step_id');
    }
}
