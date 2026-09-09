<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable([
    'definition_code', 'name', 'description', 'tenant_id', 'application_id',
    'rule_type', 'status', 'self_approval_allowed', 'expires_after_hours', 'created_by',
])]
class ApprovalDefinition extends Model
{
    use HasUuidPrimaryKey, SoftDeletes;

    public const RULE_SEQUENTIAL = 'SEQUENTIAL';

    public const RULE_ANY_OF = 'ANY_OF';

    public const RULE_ALL_OF = 'ALL_OF';

    public const RULE_TYPES = [self::RULE_SEQUENTIAL, self::RULE_ANY_OF, self::RULE_ALL_OF];

    public const STATUS_DRAFT = 'DRAFT';

    public const STATUS_ACTIVE = 'ACTIVE';

    public const STATUS_INACTIVE = 'INACTIVE';

    public const STATUSES = [self::STATUS_DRAFT, self::STATUS_ACTIVE, self::STATUS_INACTIVE];

    protected function casts(): array
    {
        return ['self_approval_allowed' => 'boolean', 'expires_after_hours' => 'integer'];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function application(): BelongsTo
    {
        return $this->belongsTo(Application::class);
    }

    public function levels(): HasMany
    {
        return $this->hasMany(ApprovalLevel::class)->orderBy('level_order');
    }

    public function requests(): HasMany
    {
        return $this->hasMany(ApprovalRequest::class);
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }
}
