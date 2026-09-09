<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable([
    'integration_code', 'name', 'tenant_id', 'source_application_id', 'target_application_id',
    'integration_type', 'status', 'base_url', 'timeout_seconds', 'retry_policy', 'metadata', 'created_by',
])]
class Integration extends Model
{
    use HasUuidPrimaryKey, SoftDeletes;

    public const TYPE_REST = 'REST';

    public const TYPE_WEBHOOK = 'WEBHOOK';

    public const TYPE_EVENT = 'EVENT';

    public const TYPE_INTERNAL = 'INTERNAL';

    public const TYPES = [self::TYPE_REST, self::TYPE_WEBHOOK, self::TYPE_EVENT, self::TYPE_INTERNAL];

    public const STATUS_DRAFT = 'DRAFT';

    public const STATUS_ACTIVE = 'ACTIVE';

    public const STATUS_INACTIVE = 'INACTIVE';

    public const STATUSES = [self::STATUS_DRAFT, self::STATUS_ACTIVE, self::STATUS_INACTIVE];

    protected function casts(): array
    {
        return [
            'timeout_seconds' => 'integer',
            'retry_policy' => 'array',
            'metadata' => 'array',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function sourceApplication(): BelongsTo
    {
        return $this->belongsTo(Application::class, 'source_application_id');
    }

    public function targetApplication(): BelongsTo
    {
        return $this->belongsTo(Application::class, 'target_application_id');
    }

    public function endpoints(): HasMany
    {
        return $this->hasMany(IntegrationEndpoint::class);
    }

    public function credentials(): HasMany
    {
        return $this->hasMany(IntegrationCredential::class);
    }

    public function activeCredential(): ?IntegrationCredential
    {
        return $this->credentials()->where('status', IntegrationCredential::STATUS_ACTIVE)->first();
    }

    public function logs(): HasMany
    {
        return $this->hasMany(IntegrationLog::class);
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }
}
