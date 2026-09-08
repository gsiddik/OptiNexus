<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable([
    'tenant_id', 'application_id', 'name', 'code', 'description',
    'role_type', 'is_system', 'status',
])]
class Role extends Model
{
    use HasFactory, HasUuidPrimaryKey, SoftDeletes;

    public const TYPE_SYSTEM = 'SYSTEM';

    public const TYPE_APPLICATION = 'APPLICATION';

    public const TYPE_TENANT = 'TENANT';

    public const TYPES = [self::TYPE_SYSTEM, self::TYPE_APPLICATION, self::TYPE_TENANT];

    public const STATUS_ACTIVE = 'ACTIVE';

    public const STATUS_DISABLED = 'DISABLED';

    protected function casts(): array
    {
        return [
            'is_system' => 'boolean',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function application(): BelongsTo
    {
        return $this->belongsTo(Application::class);
    }

    public function permissions(): BelongsToMany
    {
        return $this->belongsToMany(Permission::class, 'role_permission')
            ->withPivot(['granted_by'])
            ->using(RolePermissionPivot::class);
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'user_roles')
            ->withPivot(['tenant_id', 'granted_by'])
            ->withTimestamps();
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }
}
