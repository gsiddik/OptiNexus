<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

#[Fillable([
    'actor_user_id', 'actor_identity', 'tenant_id', 'customer_id', 'application_id',
    'action', 'resource_type', 'resource_id', 'old_value', 'new_value',
    'ip_address', 'user_agent', 'request_id', 'correlation_id', 'causation_id', 'source', 'metadata',
])]
class AuditLog extends Model
{
    use HasUuidPrimaryKey;

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'old_value' => 'array',
            'new_value' => 'array',
            'metadata' => 'array',
            'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (AuditLog $log) {
            if (! $log->created_at) {
                $log->created_at = now();
            }
        });

        // Audit records are immutable: block any update/delete performed
        // through normal Eloquent flows (defense in depth on top of the
        // route layer, which never exposes PUT/DELETE for audit logs).
        static::updating(function () {
            throw new RuntimeException('Audit log entries are immutable and cannot be updated.');
        });

        static::deleting(function () {
            throw new RuntimeException('Audit log entries are immutable and cannot be deleted.');
        });
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }
}
