<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\Pivot;

/**
 * role_permission is an append-only grant log: it records created_at but
 * has no updated_at column, and uses a UUID primary key rather than the
 * pivot default of no key.
 */
class RolePermissionPivot extends Pivot
{
    use HasUuidPrimaryKey;

    public $incrementing = false;

    public $timestamps = false;

    protected $table = 'role_permission';

    protected static function booted(): void
    {
        static::creating(function (self $pivot) {
            $pivot->created_at ??= now();
        });
    }
}
