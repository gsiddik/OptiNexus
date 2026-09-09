<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

/** Immutable: a decision, once recorded, is never updated or deleted. */
#[Fillable(['approval_request_step_id', 'approval_request_id', 'decided_by', 'decision', 'comment', 'decided_at'])]
class ApprovalDecision extends Model
{
    use HasUuidPrimaryKey;

    public $timestamps = false;

    public const DECISION_APPROVE = 'APPROVE';

    public const DECISION_REJECT = 'REJECT';

    public const DECISION_RETURN = 'RETURN';

    public const DECISIONS = [self::DECISION_APPROVE, self::DECISION_REJECT, self::DECISION_RETURN];

    protected function casts(): array
    {
        return ['decided_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::creating(function (self $decision) {
            $decision->decided_at ??= now();
        });

        static::updating(function () {
            throw new RuntimeException('Approval decisions are immutable and cannot be updated.');
        });

        static::deleting(function () {
            throw new RuntimeException('Approval decisions are immutable and cannot be deleted.');
        });
    }

    public function step(): BelongsTo
    {
        return $this->belongsTo(ApprovalRequestStep::class, 'approval_request_step_id');
    }

    public function decidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }
}
