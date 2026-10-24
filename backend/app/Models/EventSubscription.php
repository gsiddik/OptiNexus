<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** An application's subscription to event keys, delivered as signed webhooks (docs/integration/INTEGRATION_GUIDE.md §4b). */
#[Fillable(['application_id', 'event_key_pattern', 'tenant_id', 'endpoint_url', 'signing_secret', 'status', 'created_by', 'secret_rotated_at'])]
#[Hidden(['signing_secret'])]
class EventSubscription extends Model
{
    use HasUuidPrimaryKey;

    public const STATUS_ACTIVE = 'ACTIVE';

    public const STATUS_DISABLED = 'DISABLED';

    public const STATUSES = [self::STATUS_ACTIVE, self::STATUS_DISABLED];

    protected function casts(): array
    {
        return ['signing_secret' => 'encrypted', 'secret_rotated_at' => 'datetime'];
    }

    public function application(): BelongsTo
    {
        return $this->belongsTo(Application::class);
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    public function matches(string $eventKey): bool
    {
        $pattern = $this->event_key_pattern;

        return str_ends_with($pattern, '.*') ? str_starts_with($eventKey, substr($pattern, 0, -1)) : $pattern === $eventKey;
    }
}
