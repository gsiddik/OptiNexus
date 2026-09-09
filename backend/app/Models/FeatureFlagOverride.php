<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'feature_flag_id', 'scope_type', 'scope_id', 'value',
    'effective_from', 'effective_until', 'created_by',
])]
class FeatureFlagOverride extends Model
{
    use HasUuidPrimaryKey;

    protected function casts(): array
    {
        return [
            'value' => 'array',
            'effective_from' => 'datetime',
            'effective_until' => 'datetime',
        ];
    }

    public function featureFlag(): BelongsTo
    {
        return $this->belongsTo(FeatureFlag::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function isEffectiveAt(?\DateTimeInterface $at = null): bool
    {
        $at ??= now();

        if ($this->effective_from && $this->effective_from->gt($at)) {
            return false;
        }

        return ! ($this->effective_until && $this->effective_until->lte($at));
    }
}
