<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['price_id', 'tier_order', 'from_quantity', 'to_quantity', 'unit_amount', 'flat_amount'])]
class PricingTier extends Model
{
    use HasUuidPrimaryKey;

    protected function casts(): array
    {
        return [
            'from_quantity' => 'decimal:4',
            'to_quantity' => 'decimal:4',
            'unit_amount' => 'decimal:2',
            'flat_amount' => 'decimal:2',
        ];
    }

    public function price(): BelongsTo
    {
        return $this->belongsTo(Price::class);
    }
}
