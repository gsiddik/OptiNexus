<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['plan_id', 'limit_key', 'limit_value', 'is_unlimited', 'unit'])]
class PlanLimit extends Model
{
    use HasUuidPrimaryKey;

    protected function casts(): array
    {
        return [
            'limit_value' => 'decimal:4',
            'is_unlimited' => 'boolean',
        ];
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }
}
