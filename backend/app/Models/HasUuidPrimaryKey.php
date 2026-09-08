<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;

/**
 * Shared concern for governance models keyed by UUID primary keys.
 * UUIDs are used (rather than sequential IDs) so identifiers exposed
 * through public/partner-facing REST APIs cannot be enumerated.
 */
trait HasUuidPrimaryKey
{
    use HasUuids;

    public function uniqueIds(): array
    {
        return ['id'];
    }
}
