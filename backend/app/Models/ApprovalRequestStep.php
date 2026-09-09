<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable(['approval_request_id', 'approval_level_id', 'level_order', 'resolved_approver_user_id', 'status'])]
class ApprovalRequestStep extends Model
{
    use HasUuidPrimaryKey;

    public const STATUS_PENDING = 'PENDING';

    public const STATUS_APPROVED = 'APPROVED';

    public const STATUS_REJECTED = 'REJECTED';

    public const STATUS_RETURNED = 'RETURNED';

    public const STATUS_SKIPPED = 'SKIPPED';

    protected function casts(): array
    {
        return ['level_order' => 'integer'];
    }

    public function request(): BelongsTo
    {
        return $this->belongsTo(ApprovalRequest::class, 'approval_request_id');
    }

    public function level(): BelongsTo
    {
        return $this->belongsTo(ApprovalLevel::class, 'approval_level_id');
    }

    public function resolvedApprover(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_approver_user_id');
    }

    public function decision(): HasOne
    {
        return $this->hasOne(ApprovalDecision::class);
    }
}
