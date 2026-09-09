<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['event_key', 'application_id', 'name', 'description', 'schema_version', 'payload_schema', 'status'])]
class EventCatalogEntry extends Model
{
    use HasUuidPrimaryKey;

    protected $table = 'event_catalog';

    public const STATUS_ACTIVE = 'ACTIVE';

    public const STATUS_DEPRECATED = 'DEPRECATED';

    public const STATUS_DISABLED = 'DISABLED';

    public const STATUSES = [self::STATUS_ACTIVE, self::STATUS_DEPRECATED, self::STATUS_DISABLED];

    protected function casts(): array
    {
        return ['payload_schema' => 'array'];
    }

    public function application(): BelongsTo
    {
        return $this->belongsTo(Application::class);
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }
}
