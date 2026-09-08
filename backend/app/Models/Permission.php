<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

#[Fillable([
    'application_id', 'capability_id', 'permission_key', 'name', 'description', 'status',
])]
class Permission extends Model
{
    use HasFactory, HasUuidPrimaryKey;

    public const STATUS_ACTIVE = 'ACTIVE';

    public const STATUS_DEPRECATED = 'DEPRECATED';

    public const STATUS_DISABLED = 'DISABLED';

    public const STATUSES = [self::STATUS_ACTIVE, self::STATUS_DEPRECATED, self::STATUS_DISABLED];

    public function application(): BelongsTo
    {
        return $this->belongsTo(Application::class);
    }

    public function capability(): BelongsTo
    {
        return $this->belongsTo(Capability::class);
    }

    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class, 'role_permission')
            ->withPivot(['granted_by'])
            ->using(RolePermissionPivot::class);
    }
}
