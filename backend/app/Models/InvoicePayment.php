<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['invoice_id', 'amount', 'paid_at', 'method', 'reference', 'recorded_by', 'metadata', 'status', 'bank_name', 'account_name', 'note', 'verification_note', 'verified_at', 'reviewed_by', 'submission_key', 'proof'])]
class InvoicePayment extends Model
{
    use HasUuidPrimaryKey;

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'paid_at' => 'datetime',
            'metadata' => 'array',
            'proof' => 'array',
            'verified_at' => 'datetime',
        ];
    }

    public function getTenantIdAttribute(): ?string
    {
        return $this->invoice?->tenant_id;
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }
}
