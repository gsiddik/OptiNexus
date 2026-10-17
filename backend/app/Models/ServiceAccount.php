<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Laravel\Passport\Client;

#[Fillable(['application_id', 'tenant_id', 'name', 'oauth_client_id', 'status', 'allowed_scopes', 'created_by'])]
class ServiceAccount extends Model
{
    use HasUuidPrimaryKey;

    public const STATUS_ACTIVE = 'ACTIVE';

    public const STATUS_REVOKED = 'REVOKED';

    /** What a product's own account may do by default; gateway (telematics) scopes are never part of it and must be named. */
    public const PRODUCT_SCOPES = [
        'authorization.check', 'governance.read', 'audit.write', 'introspect', 'entitlement.check',
        'commercial.read', 'usage.write', 'event.write', 'tenant.delegate', 'access.evaluate',
    ];

    public function application(): BelongsTo
    {
        return $this->belongsTo(Application::class);
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function oauthClient(): BelongsTo
    {
        return $this->belongsTo(Client::class, 'oauth_client_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class, 'service_account_roles')
            ->withPivot(['tenant_id'])
            ->withTimestamps();
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['allowed_scopes' => 'array'];
    }

    /** No allow-list (NULL) keeps accounts registered before the list existed working; a list is exact. */
    public function allowsScope(string $scope): bool
    {
        return $this->allowed_scopes === null || in_array($scope, $this->allowed_scopes, true);
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }
}
