<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable([
    'application_code', 'name', 'description', 'owner', 'version', 'status',
    'frontend_url', 'backend_url', 'metadata',
])]
class Application extends Model
{
    use HasFactory, HasUuidPrimaryKey, SoftDeletes;

    public const STATUS_DRAFT = 'DRAFT';

    public const STATUS_REVIEW = 'REVIEW';

    public const STATUS_APPROVED = 'APPROVED';

    public const STATUS_PUBLISHED = 'PUBLISHED';

    public const STATUS_DEPRECATED = 'DEPRECATED';

    public const STATUS_RETIRED = 'RETIRED';

    public const STATUSES = [
        self::STATUS_DRAFT, self::STATUS_REVIEW, self::STATUS_APPROVED,
        self::STATUS_PUBLISHED, self::STATUS_DEPRECATED, self::STATUS_RETIRED,
    ];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
        ];
    }

    public function capabilities(): HasMany
    {
        return $this->hasMany(Capability::class);
    }

    public function permissions(): HasMany
    {
        return $this->hasMany(Permission::class);
    }

    public function tenants(): BelongsToMany
    {
        return $this->belongsToMany(Tenant::class, 'tenant_applications')
            ->withPivot(['status'])
            ->withTimestamps();
    }

    public function roles(): HasMany
    {
        return $this->hasMany(Role::class);
    }

    public function products(): BelongsToMany
    {
        return $this->belongsToMany(Product::class, 'product_applications')->withTimestamps();
    }

    public function isPublished(): bool
    {
        return $this->status === self::STATUS_PUBLISHED;
    }
}
