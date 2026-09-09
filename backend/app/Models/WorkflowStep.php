<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['workflow_version_id', 'step_key', 'step_type', 'name', 'config', 'sort_order'])]
class WorkflowStep extends Model
{
    use HasUuidPrimaryKey;

    public const TYPE_START = 'START';

    public const TYPE_CONDITION = 'CONDITION';

    public const TYPE_TASK = 'TASK';

    public const TYPE_APPROVAL = 'APPROVAL';

    public const TYPE_INTEGRATION = 'INTEGRATION';

    public const TYPE_INTERNAL_ACTION = 'INTERNAL_ACTION';

    public const TYPE_WAIT = 'WAIT';

    public const TYPE_END = 'END';

    public const TYPES = [
        self::TYPE_START, self::TYPE_CONDITION, self::TYPE_TASK, self::TYPE_APPROVAL,
        self::TYPE_INTEGRATION, self::TYPE_INTERNAL_ACTION, self::TYPE_WAIT, self::TYPE_END,
    ];

    protected function casts(): array
    {
        return ['config' => 'array', 'sort_order' => 'integer'];
    }

    public function version(): BelongsTo
    {
        return $this->belongsTo(WorkflowVersion::class, 'workflow_version_id');
    }

    public function outgoingTransitions(): HasMany
    {
        return $this->hasMany(WorkflowTransition::class, 'from_step_id')->orderBy('sort_order');
    }
}
