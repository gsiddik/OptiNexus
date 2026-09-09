<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\Pivot;

/**
 * role_permission is an append-only grant log: it records created_at but
 * has no updated_at column, and uses a UUID primary key rather than the
 * pivot default of no key.
 *
 * getUpdatedAtColumn() is overridden to return null (rather than relying
 * on $timestamps = false or the UPDATED_AT constant) because AsPivot's
 * own getUpdatedAtColumn()/getCreatedAtColumn() delegate to the pivot's
 * *parent* model (Role) whenever pivotParent is set - which it always is
 * once fetched through the relation - so it would otherwise report
 * "updated_at" simply because Role itself has that column, regardless of
 * what this pivot table actually has.
 */
class RolePermissionPivot extends Pivot
{
    use HasUuidPrimaryKey;

    public $incrementing = false;

    public $timestamps = false;

    protected $table = 'role_permission';

    public function getUpdatedAtColumn(): ?string
    {
        return null;
    }

    protected static function booted(): void
    {
        static::creating(function (self $pivot) {
            $pivot->created_at ??= now();
        });
    }
}
