<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['integration_id', 'endpoint_key', 'method', 'path', 'headers_template'])]
class IntegrationEndpoint extends Model
{
    use HasUuidPrimaryKey;

    public const METHODS = ['GET', 'POST', 'PUT', 'PATCH', 'DELETE'];

    protected function casts(): array
    {
        return ['headers_template' => 'array'];
    }

    public function integration(): BelongsTo
    {
        return $this->belongsTo(Integration::class);
    }
}
