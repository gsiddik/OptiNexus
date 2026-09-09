<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['workflow_version_id', 'from_step_id', 'to_step_id', 'condition', 'sort_order'])]
class WorkflowTransition extends Model
{
    use HasUuidPrimaryKey;

    protected function casts(): array
    {
        return ['condition' => 'array', 'sort_order' => 'integer'];
    }

    public function version(): BelongsTo
    {
        return $this->belongsTo(WorkflowVersion::class, 'workflow_version_id');
    }

    public function fromStep(): BelongsTo
    {
        return $this->belongsTo(WorkflowStep::class, 'from_step_id');
    }

    public function toStep(): BelongsTo
    {
        return $this->belongsTo(WorkflowStep::class, 'to_step_id');
    }
}
