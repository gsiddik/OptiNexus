<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['tax_code', 'label', 'rate', 'status', 'effective_from', 'effective_until', 'metadata'])]
class TaxCode extends Model
{
    use HasUuidPrimaryKey;

    public const STATUS_ACTIVE = 'ACTIVE';

    public const STATUS_INACTIVE = 'INACTIVE';

    protected function casts(): array
    {
        return [
            'rate' => 'decimal:4',
            'effective_from' => 'datetime',
            'effective_until' => 'datetime',
            'metadata' => 'array',
        ];
    }
}
