<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['billing_id', 'adjustment_type', 'reason', 'amount', 'status', 'created_by', 'approved_by'])]
class BillingAdjustment extends Model
{
    use HasUuidPrimaryKey;

    public const TYPE_DISCOUNT = 'DISCOUNT';

    public const TYPE_CREDIT = 'CREDIT';

    public const TYPE_DEBIT = 'DEBIT';

    public const TYPE_CORRECTION = 'CORRECTION';

    public const TYPE_ROUNDING = 'ROUNDING';

    public const TYPES = [self::TYPE_DISCOUNT, self::TYPE_CREDIT, self::TYPE_DEBIT, self::TYPE_CORRECTION, self::TYPE_ROUNDING];

    public const STATUS_APPLIED = 'APPLIED';

    protected function casts(): array
    {
        return ['amount' => 'decimal:2'];
    }

    public function billing(): BelongsTo
    {
        return $this->belongsTo(Billing::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }
}
