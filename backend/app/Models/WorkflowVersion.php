<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['workflow_id', 'version', 'status', 'created_by', 'activated_at', 'retired_at'])]
class WorkflowVersion extends Model
{
    use HasUuidPrimaryKey;

    public const STATUS_DRAFT = 'DRAFT';

    public const STATUS_ACTIVE = 'ACTIVE';

    public const STATUS_RETIRED = 'RETIRED';

    public const STATUSES = [self::STATUS_DRAFT, self::STATUS_ACTIVE, self::STATUS_RETIRED];

    protected function casts(): array
    {
        return [
            'version' => 'integer',
            'activated_at' => 'datetime',
            'retired_at' => 'datetime',
        ];
    }

    public function workflow(): BelongsTo
    {
        return $this->belongsTo(Workflow::class);
    }

    public function steps(): HasMany
    {
        return $this->hasMany(WorkflowStep::class)->orderBy('sort_order');
    }

    public function transitions(): HasMany
    {
        return $this->hasMany(WorkflowTransition::class);
    }

    public function startStep(): ?WorkflowStep
    {
        return $this->steps()->where('step_type', WorkflowStep::TYPE_START)->first();
    }

    public function isDraft(): bool
    {
        return $this->status === self::STATUS_DRAFT;
    }
}
