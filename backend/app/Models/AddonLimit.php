<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['addon_id', 'limit_key', 'limit_delta', 'unit'])]
class AddonLimit extends Model
{
    use HasUuidPrimaryKey;

    protected function casts(): array
    {
        return ['limit_delta' => 'decimal:4'];
    }

    public function addon(): BelongsTo
    {
        return $this->belongsTo(Addon::class);
    }
}
