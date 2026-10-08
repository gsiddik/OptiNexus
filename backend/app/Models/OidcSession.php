<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One sign-in of a user to an application for a tenant. Its id is the `sid`
 * claim of the id_token. It stays active until the user logs out, access is
 * revoked, or it times out.
 */
#[Fillable(['user_id', 'tenant_id', 'oidc_client_id', 'expires_at', 'ended_at', 'end_reason'])]
class OidcSession extends Model
{
    use HasUuidPrimaryKey;

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'ended_at' => 'datetime',
        ];
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('ended_at')->where('expires_at', '>', now());
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(OidcClient::class, 'oidc_client_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
