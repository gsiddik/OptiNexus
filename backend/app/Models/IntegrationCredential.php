<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * encrypted_secret is stored via Laravel's `encrypted` cast (AES-256-CBC,
 * APP_KEY) and marked #[Hidden] so it can never leak through
 * toArray()/toJson() even if a future change forgets to exclude it
 * explicitly in a Resource - defense in depth on top of "the API never
 * returns it after creation".
 */
#[Fillable(['integration_id', 'credential_type', 'encrypted_secret', 'reference_label', 'status', 'created_by', 'rotated_at', 'revoked_at'])]
#[Hidden(['encrypted_secret'])]
class IntegrationCredential extends Model
{
    use HasUuidPrimaryKey;

    public const TYPE_API_KEY = 'API_KEY';

    public const TYPE_BEARER = 'BEARER';

    public const TYPE_BASIC = 'BASIC';

    public const TYPE_OAUTH2 = 'OAUTH2';

    public const TYPES = [self::TYPE_API_KEY, self::TYPE_BEARER, self::TYPE_BASIC, self::TYPE_OAUTH2];

    public const STATUS_ACTIVE = 'ACTIVE';

    public const STATUS_ROTATED = 'ROTATED';

    public const STATUS_REVOKED = 'REVOKED';

    protected function casts(): array
    {
        return [
            'encrypted_secret' => 'encrypted',
            'rotated_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    public function integration(): BelongsTo
    {
        return $this->belongsTo(Integration::class);
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }
}
