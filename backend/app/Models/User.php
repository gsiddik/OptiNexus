<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Str;
use Laravel\Sanctum\HasApiTokens;

#[Fillable(['name', 'email', 'password', 'status'])]
#[Hidden(['password', 'remember_token', 'mfa_secret'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, HasUuidPrimaryKey, Notifiable, SoftDeletes;

    public const STATUS_INVITED = 'INVITED';

    public const STATUS_ACTIVE = 'ACTIVE';

    public const STATUS_SUSPENDED = 'SUSPENDED';

    public const STATUS_DISABLED = 'DISABLED';

    /** Where a verified e-mail address came from (email_verification_method). */
    public const EMAIL_VERIFIED_BY_INVITATION = 'INVITATION';

    public const EMAIL_VERIFIED_BY_OPERATOR = 'OPERATOR_MANUAL';

    /** Progress of an account an operator created through the first Admin Tenant flow (activationState()). */
    public const ACTIVATION_PASSWORD_CHANGE_REQUIRED = 'PASSWORD_CHANGE_REQUIRED';

    public const ACTIVATION_CREDENTIAL_EXPIRED = 'CREDENTIAL_EXPIRED';

    public const ACTIVATION_EMAIL_VERIFICATION_PENDING = 'EMAIL_VERIFICATION_PENDING';

    public const ACTIVATION_ACTIVATED = 'ACTIVATED';

    protected static function booted(): void
    {
        // A verified address belongs to that address: when the e-mail really changes, the verification is dropped and has to be
        // recorded again for the new one. Covers every path that changes the address, not only one controller.
        static::updating(function (User $user): void {
            if ($user->isDirty('email') && ! $user->isDirty('email_verified_at')
                && Str::lower((string) $user->getOriginal('email')) !== Str::lower((string) $user->email)) {
                $user->email_verified_at = null;
                $user->email_verification_method = null;
                $user->email_verified_by = null;
            }
        });
    }

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'activation_started_at' => 'datetime',
            'activated_at' => 'datetime',
            'password_change_required' => 'boolean',
            'temporary_password_issued_at' => 'datetime',
            'temporary_password_expires_at' => 'datetime',
            'password_changed_at' => 'datetime',
            'password' => 'hashed',
            'mfa_enabled' => 'boolean',
            'metadata' => 'array',
            'last_login_at' => 'datetime',
        ];
    }

    public function tenantMemberships(): HasMany
    {
        return $this->hasMany(TenantMembership::class);
    }

    public function tenants(): BelongsToMany
    {
        return $this->belongsToMany(Tenant::class, 'tenant_memberships')
            ->withPivot(['status', 'is_tenant_admin'])
            ->withTimestamps();
    }

    public function applicationAccess(): HasMany
    {
        return $this->hasMany(UserApplicationAccess::class);
    }

    public function userRoles(): HasMany
    {
        return $this->hasMany(UserRole::class);
    }

    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class, 'user_roles')
            ->withPivot(['tenant_id', 'granted_by'])
            ->withTimestamps();
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    /** An operator created this account through the first Admin Tenant flow and its activation is not finished yet. */
    public function isInManagedActivation(): bool
    {
        return $this->activation_started_at !== null && $this->activated_at === null;
    }

    public function temporaryPasswordExpired(): bool
    {
        return $this->password_change_required && $this->temporary_password_expires_at !== null && $this->temporary_password_expires_at->isPast();
    }

    /** The temporary password can still be used to start the password change. */
    public function hasUsableTemporaryPassword(): bool
    {
        return $this->password_change_required && $this->temporary_password_expires_at !== null && $this->temporary_password_expires_at->isFuture();
    }

    /** Null for every account that never went through the flow. */
    public function activationState(): ?string
    {
        if ($this->activation_started_at === null) {
            return null;
        }

        return match (true) {
            $this->activated_at !== null => self::ACTIVATION_ACTIVATED,
            $this->temporaryPasswordExpired() => self::ACTIVATION_CREDENTIAL_EXPIRED,
            $this->password_change_required => self::ACTIVATION_PASSWORD_CHANGE_REQUIRED,
            default => self::ACTIVATION_EMAIL_VERIFICATION_PENDING,
        };
    }
}
