<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An OpenID Connect relying party (an integrated application's login).
 * A null client_secret_hash marks a public client, which must use PKCE.
 */
#[Fillable(['application_id', 'client_id', 'client_secret_hash', 'name', 'redirect_uris', 'post_logout_redirect_uris', 'launch_url', 'backchannel_logout_uri', 'require_pkce', 'status', 'created_by'])]
#[Hidden(['client_secret_hash'])]
class OidcClient extends Model
{
    use HasUuidPrimaryKey;

    public const STATUS_ACTIVE = 'ACTIVE';

    public const STATUS_REVOKED = 'REVOKED';

    protected function casts(): array
    {
        return [
            'redirect_uris' => 'array',
            'post_logout_redirect_uris' => 'array',
            'require_pkce' => 'boolean',
        ];
    }

    public function application(): BelongsTo
    {
        return $this->belongsTo(Application::class);
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    public function isConfidential(): bool
    {
        return $this->client_secret_hash !== null;
    }

    public function allowsRedirectUri(string $uri): bool
    {
        // Exact string match only (RFC 6749 §3.1.2.2 / OAuth 2.1): no prefix
        // or wildcard matching, which is the classic open-redirect hole.
        return in_array($uri, $this->redirect_uris ?? [], true);
    }

    public function allowsPostLogoutRedirectUri(string $uri): bool
    {
        return in_array($uri, $this->post_logout_redirect_uris ?? [], true);
    }

    public function verifySecret(?string $secret): bool
    {
        if (! $this->isConfidential() || $secret === null || $secret === '') {
            return false;
        }

        return hash_equals($this->client_secret_hash, hash('sha256', $secret));
    }
}
