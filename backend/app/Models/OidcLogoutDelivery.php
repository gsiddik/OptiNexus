<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Outbox row for one Back-Channel Logout call to one application.
 */
#[Fillable(['oidc_client_id', 'user_id', 'tenant_id', 'type', 'reason', 'status', 'attempts', 'last_http_status', 'last_error', 'delivered_at'])]
class OidcLogoutDelivery extends Model
{
    use HasUuidPrimaryKey;

    public const TYPE_LOGOUT = 'LOGOUT';

    public const TYPE_ACCESS_REVOKED = 'ACCESS_REVOKED';

    public const STATUS_PENDING = 'PENDING';

    public const STATUS_DELIVERED = 'DELIVERED';

    public const STATUS_FAILED = 'FAILED';

    protected function casts(): array
    {
        return ['delivered_at' => 'datetime'];
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
