<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable([
    'flag_key', 'application_id', 'name', 'description', 'flag_type',
    'default_value', 'status', 'created_by',
])]
class FeatureFlag extends Model
{
    use HasUuidPrimaryKey, SoftDeletes;

    public const TYPE_BOOLEAN = 'BOOLEAN';

    public const TYPE_STRING = 'STRING';

    public const TYPE_NUMBER = 'NUMBER';

    public const TYPE_JSON = 'JSON';

    public const TYPES = [self::TYPE_BOOLEAN, self::TYPE_STRING, self::TYPE_NUMBER, self::TYPE_JSON];

    public const STATUS_DRAFT = 'DRAFT';

    public const STATUS_ACTIVE = 'ACTIVE';

    public const STATUS_INACTIVE = 'INACTIVE';

    public const STATUSES = [self::STATUS_DRAFT, self::STATUS_ACTIVE, self::STATUS_INACTIVE];

    public const SCOPE_GLOBAL = 'GLOBAL';

    public const SCOPE_APPLICATION = 'APPLICATION';

    public const SCOPE_TENANT = 'TENANT';

    public const SCOPE_USER = 'USER';

    public const SCOPES = [self::SCOPE_GLOBAL, self::SCOPE_APPLICATION, self::SCOPE_TENANT, self::SCOPE_USER];

    protected function casts(): array
    {
        return [
            'default_value' => 'array',
        ];
    }

    public function application(): BelongsTo
    {
        return $this->belongsTo(Application::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function overrides(): HasMany
    {
        return $this->hasMany(FeatureFlagOverride::class);
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }
}
