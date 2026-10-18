<?php

namespace App\Services\Ecosystem;

use App\Models\User;
use App\Services\AuditService;
use App\Services\Oidc\SessionRevocationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;

/**
 * The account side of the first Admin Tenant flow (owner decision 2026-10-18, second batch; contract: GOVERNANCE_CONTRACTS.md 2c).
 *
 * An operator-created account stays INVITED - which every access check in OptiNexus already refuses - until two things are true:
 * the e-mail address was verified by an operator, and the holder replaced the temporary password. Whichever comes last completes
 * the activation (INVITED -> ACTIVE) inside the transaction that records it, under a row lock. The temporary password is the
 * account's normal hashed password: single use (replacing it consumes it), time-limited, and attempt-limited per account.
 */
class AccountActivationService
{
    /** Letters and digits without look-alikes (no I, O, 0, 1): read out over a phone, typed by hand. 32 symbols = 5 bits each. */
    private const ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

    public function __construct(
        private readonly AuditService $audit,
        private readonly SessionRevocationService $revocation,
    ) {}

    // ---------------------------------------------------------------- temporary password

    /**
     * Gives the account a new temporary password and returns it in display form. The only copy of the plain text is this return
     * value: the database holds a hash, the audit trail records that it happened and when it expires, never the value.
     * The caller holds the row lock and writes the audit entry.
     */
    public function issueTemporaryPassword(User $user): string
    {
        $plain = '';
        $length = (int) config('activation.temporary_password_length');
        for ($i = 0; $i < $length; $i++) {
            $plain .= self::ALPHABET[random_int(0, strlen(self::ALPHABET) - 1)];
        }

        $user->forceFill([
            'password' => $plain,
            'password_change_required' => true,
            'temporary_password_issued_at' => now(),
            'temporary_password_expires_at' => now()->addHours((int) config('activation.temporary_password_ttl_hours')),
        ])->save();

        RateLimiter::clear($this->lockKey($user));

        return trim(chunk_split($plain, 5, '-'), '-');
    }

    /** The form the password is stored in: upper case, without the dashes and spaces of the display form. */
    public function canonical(string $typed): string
    {
        return strtoupper(preg_replace('/[\s-]+/', '', $typed) ?? '');
    }

    public function matchesTemporaryPassword(User $user, string $typed): bool
    {
        return $user->password !== null && $typed !== '' && Hash::check($this->canonical($typed), $user->password);
    }

    // ---------------------------------------------------------------- attempt limit

    public function lockKey(User $user): string
    {
        return 'activation:temporary:'.$user->id;
    }

    /** Seconds the account is locked for, or 0. */
    public function lockedFor(User $user): int
    {
        $key = $this->lockKey($user);

        return RateLimiter::tooManyAttempts($key, (int) config('activation.max_attempts')) ? RateLimiter::availableIn($key) : 0;
    }

    public function recordFailure(User $user): void
    {
        RateLimiter::hit($this->lockKey($user), (int) config('activation.lockout_seconds'));
    }

    public function attemptsLeft(User $user): int
    {
        return max(0, (int) config('activation.max_attempts') - RateLimiter::attempts($this->lockKey($user)));
    }

    // ---------------------------------------------------------------- the password change

    /**
     * Replaces the temporary password with the one the holder chose. Everything is re-checked under the row lock, so two concurrent
     * submissions of the same temporary password cannot both succeed, and a credential that was reissued, expired or used in
     * the meantime fails. `$issuedAt` is the issue time the browser session was started with: a reissue cuts that session off.
     *
     * @return array{user: User, activated: bool}
     */
    public function changePassword(string $userId, int $issuedAt, string $temporaryPassword, string $newPassword, Request $request): array
    {
        $user = User::query()->find($userId);
        if (! $user) {
            throw new ActivationException(ActivationException::INVALID, 400);
        }

        if ($seconds = $this->lockedFor($user)) {
            $this->denied($user, $request, 'locked');

            throw new ActivationException(ActivationException::LOCKED, 429, retryAfter: $seconds);
        }

        try {
            $result = DB::transaction(fn (): array => $this->changeLocked($userId, $issuedAt, $temporaryPassword, $newPassword, $request));
        } catch (ActivationException $e) {
            if ($e->state === ActivationException::WRONG_PASSWORD) {
                $this->recordFailure($user);
            }
            $this->denied($user, $request, $e->state);

            throw $e;
        }

        RateLimiter::clear($this->lockKey($user));

        // The bootstrap session and anything else this account may hold ends here: nothing is carried over into the real session.
        $this->revocation->logoutEverywhere($result['user'], 'password_changed', $request);

        return $result;
    }

    /** @return array{user: User, activated: bool} */
    private function changeLocked(string $userId, int $issuedAt, string $temporaryPassword, string $newPassword, Request $request): array
    {
        $user = User::query()->lockForUpdate()->find($userId);

        if (! $user || $user->activation_started_at === null) {
            throw new ActivationException(ActivationException::INVALID, 409);
        }

        // Consumed before: a concurrent submission that lost the race sees the account already changed (and possibly already active).
        if (! $user->password_change_required) {
            throw new ActivationException(ActivationException::USED, 410);
        }

        if ($user->status !== User::STATUS_INVITED || ! $user->isInManagedActivation()) {
            throw new ActivationException(ActivationException::INVALID, 409);
        }

        if ($user->temporaryPasswordExpired()) {
            throw new ActivationException(ActivationException::EXPIRED, 410);
        }

        if ($user->temporary_password_issued_at?->timestamp !== $issuedAt) {
            throw new ActivationException(ActivationException::INVALID, 409);
        }

        if (! $this->matchesTemporaryPassword($user, $temporaryPassword)) {
            throw new ActivationException(ActivationException::WRONG_PASSWORD, 422);
        }

        if (hash_equals($this->canonical($temporaryPassword), $this->canonical($newPassword))) {
            throw new ActivationException(ActivationException::SAME_PASSWORD, 422);
        }

        $user->forceFill([
            'password' => $newPassword,
            'password_change_required' => false,
            'temporary_password_expires_at' => null,
            'password_changed_at' => now(),
        ])->save();

        $this->audit->record('activation.password_changed', $request, actor: $user, resourceType: 'User', resourceId: $user->id,
            newValue: ['temporary_password_consumed' => true], source: 'activation');

        $activated = $this->completeIfReady($user, $request, $user);

        return ['user' => $user, 'activated' => $activated];
    }

    /**
     * Finishes the activation when both conditions hold. The caller holds the row lock on `$user` and runs inside a transaction.
     *
     * @param  User  $actor  who made the last missing step true (the holder for the password change, the operator for the e-mail)
     */
    public function completeIfReady(User $user, Request $request, User $actor): bool
    {
        if ($user->status !== User::STATUS_INVITED || ! $user->isInManagedActivation()
            || $user->password_change_required || $user->email_verified_at === null) {
            return false;
        }

        $user->forceFill(['status' => User::STATUS_ACTIVE, 'activated_at' => now()])->save();

        $this->audit->record('activation.completed', $request, actor: $actor, resourceType: 'User', resourceId: $user->id,
            newValue: ['email' => $user->email, 'email_verification_method' => $user->email_verification_method], source: 'activation');

        return true;
    }

    public function denied(User $user, Request $request, string $reason): void
    {
        $this->audit->record('activation.denied', $request, actorIdentity: $user->email, resourceType: 'User', resourceId: $user->id,
            metadata: ['reason' => $reason], source: 'activation');
    }

    // ---------------------------------------------------------------- what an operator sees

    /**
     * Where the account stands and what is still needed. Describes state only: no secret, no hash, and never the temporary
     * password (it is shown once, when issued).
     *
     * @return array<string, mixed>
     */
    public function view(User $user): array
    {
        $verifier = $user->email_verified_by ? User::query()->withTrashed()->find($user->email_verified_by) : null;
        $state = $user->activationState();

        $next = [];
        if ($user->email_verified_at === null) {
            $next[] = 'verify_email';
        }
        if ($state === User::ACTIVATION_CREDENTIAL_EXPIRED) {
            $next[] = 'reissue_credential';
        } elseif ($state === User::ACTIVATION_PASSWORD_CHANGE_REQUIRED) {
            $next[] = 'hand_over_credential';
        }

        return [
            'managed' => $user->activation_started_at !== null,
            'state' => $state,
            'created_at' => $user->created_at?->toIso8601String(),
            'email' => [
                'verified' => $user->email_verified_at !== null,
                'verified_at' => $user->email_verified_at?->toIso8601String(),
                'method' => $user->email_verification_method,
                'verified_by' => $verifier ? ['id' => $verifier->id, 'name' => $verifier->name] : null,
            ],
            'password' => [
                'change_required' => (bool) $user->password_change_required,
                'issued_at' => $user->temporary_password_issued_at?->toIso8601String(),
                'expires_at' => $user->temporary_password_expires_at?->toIso8601String(),
                'expired' => $user->temporaryPasswordExpired(),
                'locked_seconds' => $user->password_change_required ? $this->lockedFor($user) : 0,
                'changed_at' => $user->password_changed_at?->toIso8601String(),
            ],
            'activated_at' => $user->activated_at?->toIso8601String(),
            'next_steps' => $next,
        ];
    }
}
