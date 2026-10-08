<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['code_hash', 'oidc_client_id', 'user_id', 'tenant_id', 'redirect_uri', 'scopes', 'nonce', 'code_challenge', 'code_challenge_method', 'auth_time', 'expires_at', 'consumed_at'])]
class OidcAuthorizationCode extends Model
{
    use HasUuidPrimaryKey;

    protected function casts(): array
    {
        return [
            'scopes' => 'array',
            'auth_time' => 'datetime',
            'expires_at' => 'datetime',
            'consumed_at' => 'datetime',
        ];
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
