<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['integration_id', 'event_key', 'status'])]
class IntegrationSubscription extends Model
{
    use HasUuidPrimaryKey;

    public const STATUS_ACTIVE = 'ACTIVE';

    public const STATUS_INACTIVE = 'INACTIVE';

    public function integration(): BelongsTo
    {
        return $this->belongsTo(Integration::class);
    }
}
