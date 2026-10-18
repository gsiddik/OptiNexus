<?php

namespace App\Services\Delegation;

use App\Jobs\SendUserInvitation;
use App\Models\Role;
use App\Models\TenantMembership;
use App\Models\User;
use App\Models\UserApplicationAccess;
use App\Models\UserInvitation;
use App\Models\UserRole;
use App\Services\AuditService;
use App\Services\AuthorizationService;
use App\Services\Seats\SeatException;
use App\Services\Seats\SeatOperation;
use App\Services\Seats\SeatService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

/**
 * Onboarding by invitation (owner decision 2026-10-18; contract: GOVERNANCE_CONTRACTS.md section 2b).
 *
 * Creating an invitation grants nothing. Identity, membership, application access and roles are granted when the invitee accepts,
 * inside one transaction under a row lock, after everything that made the invitation legitimate has been verified again: the
 * tenant and application, the subscription, the inviter (still an active Admin Tenant with access), every invited role (still
 * delegable - a role that stopped being assignable fails the acceptance, nothing is silently dropped) and the invitee's identity.
 * An existing identity is never linked by e-mail alone: it must also present its current password.
 */
class InvitationService
{
    public function __construct(
        private readonly DelegationService $delegation,
        private readonly AuthorizationService $authorization,
        private readonly AuditService $audit,
        private readonly SeatService $seats,
    ) {}

    // ---------------------------------------------------------------- product-facing (delegation API)

    /**
     * @param  array{email: string, name: string, role_ids?: array<int, string>, locale?: ?string}  $data
     */
    public function create(DelegationContext $context, array $data, Request $request): UserInvitation
    {
        $email = Str::lower(trim($data['email']));
        $roleIds = array_values(array_unique($data['role_ids'] ?? []));
        $locale = in_array($data['locale'] ?? null, config('invitations.locales'), true) ? $data['locale'] : 'en';

        $roles = $this->delegation->resolveAssignableRoles($context, $roleIds);
        $this->assertCreationAllowed($context);
        $this->assertNotAlreadyMember($context, $email);

        $token = $this->newToken();

        try {
            $invitation = DB::transaction(function () use ($context, $email, $data, $locale, $roleIds, $token): UserInvitation {
                // The seat lock first, before any row lock (one global order for every path that adds a seat).
                $this->seats->lock($context->tenant->id, [$context->application->id]);

                $this->expireStale($context->tenant->id, $context->application->id, $email);

                $open = $this->openInvitationFor($context->tenant->id, $context->application->id, $email, lock: true);
                if ($open) {
                    throw $this->pendingConflict($open);
                }

                // An invitation reserves a seat; with none free it is refused here, not when the invitee tries to accept.
                $this->seats->authorize(SeatOperation::Reserve, $context->tenant, $context->application, $email);

                return UserInvitation::create([
                    'tenant_id' => $context->tenant->id,
                    'application_id' => $context->application->id,
                    'email' => $email,
                    'name' => trim($data['name']),
                    'locale' => $locale,
                    'role_ids' => $roleIds,
                    'token_hash' => UserInvitation::hashToken($token),
                    'status' => UserInvitation::STATUS_PENDING,
                    'invited_by' => $context->actor->id,
                    'expires_at' => now()->addHours(config('invitations.ttl_hours')),
                    'send_count' => 1,
                    'delivery_status' => UserInvitation::DELIVERY_QUEUED,
                ]);
            });
        } catch (UniqueConstraintViolationException) {
            // A concurrent request opened the same invitation first: report it instead of creating a second one.
            $open = $this->openInvitationFor($context->tenant->id, $context->application->id, $email);

            throw $open ? $this->pendingConflict($open) : new DelegationException('INVITATION_PENDING', 'An invitation for this e-mail is already open.', 409);
        }

        RateLimiter::hit($this->creationKey($context), 3600);

        $this->audit->record('invitation.created', $request, actor: $context->actor, tenantId: $context->tenant->id,
            applicationId: $context->application->id, resourceType: 'UserInvitation', resourceId: $invitation->id,
            newValue: ['email' => $email, 'roles' => $roles->pluck('code')->all(), 'expires_at' => $invitation->expires_at->toIso8601String(), 'locale' => $locale],
            metadata: $context->auditMetadata());

        SendUserInvitation::dispatch($invitation->id, $token);

        return $invitation->refresh();
    }

    /** @return array{items: array<int, array<string, mixed>>, pagination: array<string, int>} */
    public function list(DelegationContext $context, ?string $status, ?string $search, int $perPage): array
    {
        $query = $this->scoped($context)->with('inviter')
            ->when($status, function (Builder $q) use ($status): void {
                match ($status) {
                    UserInvitation::STATUS_EXPIRED => $q->where(fn (Builder $q2) => $q2->where('status', UserInvitation::STATUS_EXPIRED)
                        ->orWhere(fn (Builder $q3) => $q3->where('status', UserInvitation::STATUS_PENDING)->where('expires_at', '<=', now()))),
                    UserInvitation::STATUS_PENDING => $q->where('status', UserInvitation::STATUS_PENDING)->where('expires_at', '>', now()),
                    default => $q->where('status', $status),
                };
            })
            ->when($search, fn (Builder $q) => $q->where(fn (Builder $q2) => $q2
                ->where('email', 'ilike', '%'.addcslashes($search, '%_\\').'%')
                ->orWhere('name', 'ilike', '%'.addcslashes($search, '%_\\').'%')))
            ->orderByDesc('created_at');

        $page = $query->paginate(max(1, min(100, $perPage)));

        return [
            'items' => collect($page->items())->map(fn (UserInvitation $i): array => $this->view($i))->all(),
            'pagination' => ['current_page' => $page->currentPage(), 'per_page' => $page->perPage(), 'total' => $page->total(), 'last_page' => $page->lastPage()],
        ];
    }

    /** Replaces the link: the old one stops working at once. Also the recovery for an expired invitation or a failed delivery. */
    public function resend(DelegationContext $context, string $id, Request $request): UserInvitation
    {
        $token = $this->newToken();

        try {
            $invitation = DB::transaction(function () use ($context, $id, $token): UserInvitation {
                $this->seats->lock($context->tenant->id, [$context->application->id]);

                $invitation = $this->lockedScoped($context, $id);

                if (! in_array($invitation->effectiveStatus(), [UserInvitation::STATUS_PENDING, UserInvitation::STATUS_EXPIRED], true)) {
                    throw $this->notPending($invitation);
                }

                if ($invitation->send_count > config('invitations.max_resends')) {
                    throw new DelegationException('INVITATION_RESEND_LIMIT', 'This invitation was already resent the maximum number of times. Revoke it and invite again.', 409);
                }

                $reference = $invitation->delivery_attempted_at ?? $invitation->last_sent_at ?? $invitation->created_at;
                $wait = (int) config('invitations.resend_cooldown_seconds') - (now()->getTimestamp() - $reference->getTimestamp());
                if ($invitation->delivery_status !== UserInvitation::DELIVERY_FAILED && $wait > 0) {
                    throw new DelegationException('INVITATION_RESEND_COOLDOWN', 'Wait a moment before sending this invitation again.', 429, ['retry_after' => $wait]);
                }

                $this->delegation->resolveAssignableRoles($context, $invitation->role_ids ?? []);

                // Resending an open invitation reserves nothing new; re-opening an expired one needs a seat again.
                if ($invitation->effectiveStatus() === UserInvitation::STATUS_EXPIRED) {
                    $this->seats->authorize(SeatOperation::Reserve, $context->tenant, $context->application, $invitation->email);
                }

                $invitation->forceFill([
                    'token_hash' => UserInvitation::hashToken($token),
                    'status' => UserInvitation::STATUS_PENDING,
                    'invited_by' => $context->actor->id,
                    'expires_at' => now()->addHours(config('invitations.ttl_hours')),
                    'send_count' => $invitation->send_count + 1,
                    'delivery_status' => UserInvitation::DELIVERY_QUEUED,
                    'delivery_error_code' => null,
                ])->save();

                return $invitation;
            });
        } catch (UniqueConstraintViolationException) {
            // The expired invitation cannot be re-opened because a newer one for the same e-mail is open: say so instead of failing with a 500.
            throw new DelegationException('INVITATION_PENDING', 'A newer invitation for this e-mail is already open. Use that one, or revoke it first.', 409);
        }

        $this->audit->record('invitation.resent', $request, actor: $context->actor, tenantId: $context->tenant->id,
            applicationId: $context->application->id, resourceType: 'UserInvitation', resourceId: $invitation->id,
            newValue: ['send_count' => $invitation->send_count, 'expires_at' => $invitation->expires_at->toIso8601String()],
            metadata: $context->auditMetadata());

        SendUserInvitation::dispatch($invitation->id, $token);

        return $invitation->refresh();
    }

    public function revoke(DelegationContext $context, string $id, ?string $reason, Request $request): UserInvitation
    {
        $invitation = DB::transaction(function () use ($context, $id, $reason): UserInvitation {
            $invitation = $this->lockedScoped($context, $id);

            if ($invitation->status === UserInvitation::STATUS_REVOKED) {
                return $invitation;
            }

            if ($invitation->status === UserInvitation::STATUS_ACCEPTED) {
                throw $this->notPending($invitation);
            }

            $invitation->forceFill([
                'status' => UserInvitation::STATUS_REVOKED,
                'revoked_at' => now(),
                'revoked_by' => $context->actor->id,
                'revoke_reason' => $reason !== null ? Str::limit(trim($reason), 255, '') : null,
            ])->save();

            $invitation->wasChanged('status') && $this->audit->record('invitation.revoked', null, actor: $context->actor,
                tenantId: $context->tenant->id, applicationId: $context->application->id, resourceType: 'UserInvitation',
                resourceId: $invitation->id, newValue: ['email' => $invitation->email, 'reason' => $invitation->revoke_reason],
                metadata: $context->auditMetadata());

            return $invitation;
        });

        return $invitation->refresh();
    }

    /** Stamps invitations whose time ran out (the API already reports them as EXPIRED; this keeps the table and the audit trail honest). */
    public function expireStale(?string $tenantId = null, ?string $applicationId = null, ?string $email = null): int
    {
        $stale = UserInvitation::query()->where('status', UserInvitation::STATUS_PENDING)->where('expires_at', '<=', now())
            ->when($tenantId, fn (Builder $q) => $q->where('tenant_id', $tenantId))
            ->when($applicationId, fn (Builder $q) => $q->where('application_id', $applicationId))
            ->when($email, fn (Builder $q) => $q->where('email', $email))
            ->get();

        foreach ($stale as $invitation) {
            $invitation->forceFill(['status' => UserInvitation::STATUS_EXPIRED])->save();
            $this->audit->record('invitation.expired', null, actorIdentity: 'system', tenantId: $invitation->tenant_id,
                applicationId: $invitation->application_id, resourceType: 'UserInvitation', resourceId: $invitation->id,
                newValue: ['email' => $invitation->email]);
        }

        return $stale->count();
    }

    /** @return array<string, mixed> */
    public function view(UserInvitation $invitation): array
    {
        $status = $invitation->effectiveStatus();
        $roles = Role::query()->whereIn('id', $invitation->role_ids ?? [])->orderBy('name')->get(['id', 'code', 'name']);
        $reopenable = in_array($status, [UserInvitation::STATUS_PENDING, UserInvitation::STATUS_EXPIRED], true);

        return [
            'id' => $invitation->id,
            'email' => $invitation->email,
            'name' => $invitation->name,
            'status' => $status,
            'locale' => $invitation->locale,
            'role_ids' => array_values($invitation->role_ids ?? []),
            'roles' => $roles->map(fn (Role $r): array => ['id' => $r->id, 'code' => $r->code, 'name' => $r->name])->all(),
            'invited_by' => $invitation->inviter ? ['id' => $invitation->inviter->id, 'name' => $invitation->inviter->name] : null,
            'created_at' => $invitation->created_at?->toIso8601String(),
            'expires_at' => $invitation->expires_at->toIso8601String(),
            'last_sent_at' => $invitation->last_sent_at?->toIso8601String(),
            'send_count' => $invitation->send_count,
            'delivery' => [
                'status' => $invitation->delivery_status,
                'attempted_at' => $invitation->delivery_attempted_at?->toIso8601String(),
                'error_code' => $invitation->delivery_error_code,
            ],
            'accepted_at' => $invitation->accepted_at?->toIso8601String(),
            'revoked_at' => $invitation->revoked_at?->toIso8601String(),
            'can_resend' => $reopenable && $invitation->send_count <= config('invitations.max_resends'),
            'can_revoke' => $reopenable,
        ];
    }

    // ---------------------------------------------------------------- invitee-facing (Nexus pages)

    public function findByToken(string $token): ?UserInvitation
    {
        if (! preg_match('/^[a-f0-9]{64}$/', $token)) {
            return null;
        }

        return UserInvitation::query()->with(['application', 'tenant', 'inviter'])->where('token_hash', UserInvitation::hashToken($token))->first();
    }

    /** The invitation the visitor's session was handed, still bound to the token it was opened with (a resend or revoke cuts it off). */
    public function findForSession(?string $id, ?string $tokenHash): ?UserInvitation
    {
        if (! $id || ! $tokenHash || ! Str::isUuid($id)) {
            return null;
        }

        return UserInvitation::query()->with(['application', 'tenant', 'inviter'])->where('id', $id)->where('token_hash', $tokenHash)->first();
    }

    /** Throws the page state when the invitation cannot be accepted. */
    public function assertOpen(UserInvitation $invitation): void
    {
        match ($invitation->effectiveStatus()) {
            UserInvitation::STATUS_ACCEPTED => throw new InvitationException(InvitationException::USED, 410),
            UserInvitation::STATUS_REVOKED => throw new InvitationException(InvitationException::REVOKED, 410),
            UserInvitation::STATUS_EXPIRED => throw new InvitationException(InvitationException::EXPIRED, 410),
            default => null,
        };
    }

    /** Whether accepting means confirming an existing identity (true) or creating / completing one (false). */
    public function needsExistingPassword(UserInvitation $invitation): bool
    {
        $existing = $this->identityFor($invitation->email);

        return $existing !== null && $existing->status !== User::STATUS_INVITED;
    }

    /**
     * @param  array{name?: ?string, password?: ?string, current_password?: ?string}  $input
     * @return array{user: User, outcome: string, invitation: UserInvitation}
     */
    public function accept(string $invitationId, string $tokenHash, array $input, Request $request): array
    {
        $lockKey = 'invitation:confirm:'.$invitationId;
        if (RateLimiter::tooManyAttempts($lockKey, (int) config('invitations.confirm_max_attempts'))) {
            $this->denied($invitationId, $request, 'locked');

            throw new InvitationException(InvitationException::LOCKED, 429, retryAfter: RateLimiter::availableIn($lockKey));
        }

        try {
            $result = DB::transaction(fn (): array => $this->acceptLocked($invitationId, $tokenHash, $input, $request));
        } catch (InvitationException $e) {
            if ($e->state === InvitationException::WRONG_PASSWORD) {
                RateLimiter::hit($lockKey, (int) config('invitations.confirm_lockout_seconds'));
            }
            $this->denied($invitationId, $request, $e->state, in_array($e->state, [InvitationException::NO_SEAT, InvitationException::SEAT_RETRY], true) ? $e->getMessage() : null);

            throw $e;
        }

        RateLimiter::clear($lockKey);

        return $result;
    }

    /** @return array{user: User, outcome: string, invitation: UserInvitation} */
    private function acceptLocked(string $invitationId, string $tokenHash, array $input, Request $request): array
    {
        // The seat lock before the invitation row lock, like every other path (create and direct add take it first too).
        $pair = UserInvitation::query()->select(['id', 'tenant_id', 'application_id'])->find($invitationId);

        if ($pair) {
            try {
                $this->seats->lock($pair->tenant_id, [$pair->application_id]);
            } catch (SeatException $e) {
                throw $this->seatState($e);
            }
        }

        $invitation = UserInvitation::query()->with(['application', 'tenant'])->lockForUpdate()->find($invitationId);

        if (! $invitation || ! hash_equals($invitation->token_hash, $tokenHash)) {
            throw new InvitationException(InvitationException::INVALID, 404);
        }

        $this->assertOpen($invitation);

        // Everything that made the invitation legitimate is checked again as it stands now.
        try {
            $context = $this->delegation->contextForInviter($invitation->application, $invitation->tenant, (string) $invitation->invited_by);
        } catch (DelegationException) {
            throw new InvitationException(InvitationException::UNAVAILABLE, 409);
        }

        try {
            $roles = $this->delegation->resolveAssignableRoles($context, $invitation->role_ids ?? []);
        } catch (DelegationException) {
            throw new InvitationException(InvitationException::ROLES_CHANGED, 409);
        }

        $existing = $this->identityFor($invitation->email, lock: true);
        $outcome = 'created';

        if ($existing) {
            $outcome = $this->confirmExisting($context, $existing, $input);
            $user = $existing;
        } else {
            $user = $this->createIdentity($invitation, $input);
        }

        $becameVerified = $user->email_verified_at === null;
        if ($becameVerified) {
            $user->forceFill(['email_verified_at' => now(), 'email_verification_method' => User::EMAIL_VERIFIED_BY_INVITATION])->save();
        }

        // Reserved becomes used here, in the same transaction: the invitation turns ACCEPTED below, so the seat is never counted twice.
        // Everything made so far (identity, verification) is rolled back with a refusal.
        try {
            $this->delegation->ensureMembershipAndAccess($context, $user);
        } catch (SeatException $e) {
            throw $this->seatState($e);
        }
        foreach ($roles as $role) {
            UserRole::query()->firstOrCreate(
                ['user_id' => $user->id, 'role_id' => $role->id, 'tenant_id' => $invitation->tenant_id],
                ['granted_by' => $context->actor->id],
            );
        }

        $invitation->forceFill([
            'status' => UserInvitation::STATUS_ACCEPTED,
            'accepted_at' => now(),
            'accepted_user_id' => $user->id,
        ])->save();

        $this->audit->record('invitation.accepted', $request, actor: $user, tenantId: $invitation->tenant_id,
            applicationId: $invitation->application_id, resourceType: 'UserInvitation', resourceId: $invitation->id,
            newValue: ['email' => $invitation->email, 'outcome' => $outcome, 'roles' => $roles->pluck('code')->all(), 'invited_by' => $context->actor->id],
            source: 'invitation');

        if ($becameVerified) {
            $this->audit->record('invitation.email_verified', $request, actor: $user, tenantId: $invitation->tenant_id,
                applicationId: $invitation->application_id, resourceType: 'User', resourceId: $user->id,
                newValue: ['method' => 'invitation_token'], source: 'invitation');
        }

        return ['user' => $user, 'outcome' => $outcome, 'invitation' => $invitation];
    }

    /**
     * An identity that already exists is never linked by e-mail alone. It must be linkable (not an operator, not deleted, disabled or
     * suspended, membership not suspended) and prove itself with its current password; a shell left by the deprecated direct path
     * (INVITED, no sign-in yet) can only be completed by the tenant that created it.
     */
    private function confirmExisting(DelegationContext $context, User $existing, array $input): string
    {
        $membership = TenantMembership::query()->where('user_id', $existing->id)->where('tenant_id', $context->tenant->id)->first();

        // An account an operator created through the first Admin Tenant flow finishes through that flow (temporary password + operator
        // verification), never through an invitation: the invitation would otherwise replace its password and skip the gate.
        $linkable = ! $existing->trashed()
            && ! $existing->isInManagedActivation()
            && ($existing->isActive() || ($existing->status === User::STATUS_INVITED && $membership !== null))
            && ! $this->authorization->hasEcosystemAccess($existing)
            && ($membership === null || $membership->status === TenantMembership::STATUS_ACTIVE);

        if (! $linkable) {
            throw new InvitationException(InvitationException::NOT_LINKABLE, 409);
        }

        if ($existing->status === User::STATUS_INVITED) {
            $existing->forceFill([
                'name' => $input['name'] ?? $existing->name,
                'password' => $input['password'],
                'status' => User::STATUS_ACTIVE,
            ])->save();

            return 'completed';
        }

        $current = (string) ($input['current_password'] ?? '');
        if ($current === '' || ! $existing->password || ! Hash::check($current, $existing->password)) {
            throw new InvitationException(InvitationException::WRONG_PASSWORD, 422);
        }

        return 'linked';
    }

    private function createIdentity(UserInvitation $invitation, array $input): User
    {
        try {
            return DB::transaction(fn () => User::create([
                'name' => trim((string) ($input['name'] ?? $invitation->name)) ?: $invitation->name,
                'email' => $invitation->email,
                'password' => $input['password'],
                'status' => User::STATUS_ACTIVE,
            ]));
        } catch (UniqueConstraintViolationException) {
            // The same person was just created by another invitation: they must confirm with that account's password instead.
            throw new InvitationException(InvitationException::CONFLICT, 409);
        }
    }

    private function identityFor(string $email, bool $lock = false): ?User
    {
        $query = User::withTrashed()->whereRaw('lower(email) = ?', [Str::lower($email)]);

        return ($lock ? $query->lockForUpdate() : $query)->first();
    }

    private function denied(string $invitationId, Request $request, string $reason, ?string $code = null): void
    {
        $invitation = UserInvitation::query()->find($invitationId);

        $this->audit->record('invitation.accept_denied', $request, actorIdentity: 'invitee', tenantId: $invitation?->tenant_id,
            applicationId: $invitation?->application_id, resourceType: 'UserInvitation', resourceId: $invitation?->id,
            metadata: array_filter(['reason' => $reason, 'seat_code' => $code]), source: 'invitation');
    }

    /** What the invitee is told when no seat can be given: the page state, never the numbers of the tenant. */
    private function seatState(SeatException $e): InvitationException
    {
        return new InvitationException(
            $e->retryable() ? InvitationException::SEAT_RETRY : InvitationException::NO_SEAT,
            $e->retryable() ? 503 : 409,
            $e->errorCode,
            $e->retryAfter,
        );
    }

    /**
     * For the page the invitee opens: tells at once, without locking, that the seat is gone (limit lowered, entitlement lost) so a
     * password is not typed for nothing. accept() decides again under the lock.
     *
     * @throws InvitationException
     */
    public function assertSeatAvailable(UserInvitation $invitation): void
    {
        try {
            $this->seats->authorize(SeatOperation::Take, $invitation->tenant, $invitation->application, $invitation->email, $this->identityFor($invitation->email)?->id);
        } catch (SeatException $e) {
            throw $this->seatState($e);
        }
    }

    // ---------------------------------------------------------------- helpers

    private function newToken(): string
    {
        return bin2hex(random_bytes(32));
    }

    /** @return Builder<UserInvitation> */
    private function scoped(DelegationContext $context): Builder
    {
        return UserInvitation::query()->where('tenant_id', $context->tenant->id)->where('application_id', $context->application->id);
    }

    private function lockedScoped(DelegationContext $context, string $id): UserInvitation
    {
        $invitation = Str::isUuid($id) ? $this->scoped($context)->lockForUpdate()->find($id) : null;

        return $invitation ?? throw new DelegationException('INVITATION_NOT_FOUND', 'The invitation could not be found.', 404);
    }

    private function openInvitationFor(string $tenantId, string $applicationId, string $email, bool $lock = false): ?UserInvitation
    {
        $query = UserInvitation::query()->where('tenant_id', $tenantId)->where('application_id', $applicationId)
            ->where('email', $email)->where('status', UserInvitation::STATUS_PENDING);

        return ($lock ? $query->lockForUpdate() : $query)->first();
    }

    private function pendingConflict(UserInvitation $open): DelegationException
    {
        return new DelegationException('INVITATION_PENDING', 'An invitation for this e-mail is already open. Resend it instead of inviting again.', 409, ['invitation_id' => $open->id]);
    }

    private function notPending(UserInvitation $invitation): DelegationException
    {
        return new DelegationException('INVITATION_NOT_PENDING', 'This invitation can no longer be changed.', 409, ['status' => $invitation->effectiveStatus()]);
    }

    private function creationKey(DelegationContext $context): string
    {
        return 'invitation:create:'.$context->tenant->id;
    }

    private function assertCreationAllowed(DelegationContext $context): void
    {
        $key = $this->creationKey($context);

        if (RateLimiter::tooManyAttempts($key, (int) config('invitations.create_per_tenant_per_hour'))) {
            throw new DelegationException('INVITATION_RATE_LIMITED', 'Too many invitations were created for this tenant. Try again later.', 429, ['retry_after' => RateLimiter::availableIn($key)]);
        }
    }

    private function assertNotAlreadyMember(DelegationContext $context, string $email): void
    {
        $user = User::query()->whereRaw('lower(email) = ?', [$email])->first();

        if (! $user) {
            return;
        }

        $member = TenantMembership::query()->where('user_id', $user->id)->where('tenant_id', $context->tenant->id)->exists()
            && UserApplicationAccess::query()->where('user_id', $user->id)->where('tenant_id', $context->tenant->id)
                ->where('application_id', $context->application->id)->where('status', UserApplicationAccess::STATUS_ACTIVE)->exists();

        if ($member) {
            throw new DelegationException('ALREADY_MEMBER', 'This person already has access to this application in this tenant.', 409);
        }
    }
}
