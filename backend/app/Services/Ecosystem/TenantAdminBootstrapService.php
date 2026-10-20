<?php

namespace App\Services\Ecosystem;

use App\Models\Application;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use App\Models\UserApplicationAccess;
use App\Models\UserRole;
use App\Services\AuditService;
use App\Services\AuthorizationService;
use App\Services\Oidc\SessionRevocationService;
use App\Services\PrivilegeEscalationGuard;
use App\Services\Seats\SeatService;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The operator's side of the first Admin Tenant flow (owner decision 2026-10-18, second batch; contract: GOVERNANCE_CONTRACTS.md 2c).
 *
 * It composes what OptiNexus already has - identity, tenant membership with the Admin Tenant flag, application access, roles, audit,
 * the privilege-escalation guard - in one transaction, and adds two things: a manual e-mail verification the operator records after
 * checking the address outside the system, and a temporary password shown once. No e-mail is sent on any path here.
 *
 * Nothing an operator could not already do step by step is allowed: every grant is checked against what THIS operator may grant in
 * THIS tenant. An existing identity is never found by e-mail and adopted; the operator names the account (its id) and repeats
 * its e-mail, and its password, status and history are left untouched.
 */
class TenantAdminBootstrapService
{
    /** The governance application is never handed to a tenant user (same rule as the delegation API). */
    private const PROTECTED_APPLICATION_CODES = ['cgo'];

    public function __construct(
        private readonly AuthorizationService $authorization,
        private readonly PrivilegeEscalationGuard $escalationGuard,
        private readonly AccountActivationService $activation,
        private readonly AuditService $audit,
        private readonly SeatService $seats,
    ) {}

    /**
     * @param  array{name?: ?string, email?: ?string, user_id?: ?string, confirm_email?: ?string, application_ids: array<int, string>, role_ids?: array<int, string>}  $data
     * @return array{outcome: string, user: User, temporary_password: ?string}
     */
    public function provision(Tenant $tenant, array $data, User $actor, Request $request): array
    {
        if (! $this->authorization->tenantIsUsable($tenant)) {
            throw new AdminBootstrapException('TENANT_NOT_ACTIVE', 'The tenant is not active. Activate it before creating its Admin Tenant.', 409);
        }

        $applications = $this->resolveApplications($tenant, $data['application_ids']);
        $roles = $this->resolveRoles($tenant, $applications, $data['role_ids'] ?? []);
        $linking = ! empty($data['user_id']);

        $this->assertOperatorMayGrant($actor, $tenant, $roles, creating: ! $linking);

        try {
            $result = DB::transaction(function () use ($tenant, $data, $applications, $roles, $actor, $linking): array {
                // Seat locks first (all applications, in a fixed order), before anything is created: a refusal leaves nothing behind.
                $this->seats->lock($tenant->id, $applications->pluck('id')->all());

                return $linking
                ? $this->linkExisting($tenant, (string) $data['user_id'], (string) ($data['confirm_email'] ?? ''), $applications, $roles, $actor)
                : $this->createNew($tenant, (string) $data['name'], (string) $data['email'], $applications, $roles, $actor);
            });
        } catch (UniqueConstraintViolationException) {
            // A concurrent request created the same identity first: report it, never create or adopt a second one.
            throw $this->emailConflict(Str::lower(trim((string) ($data['email'] ?? ''))));
        }

        $user = $result['user'];

        $this->audit->record($result['outcome'] === 'created' ? 'tenant_admin.bootstrap_created' : 'tenant_admin.bootstrap_linked', $request,
            actor: $actor, tenantId: $tenant->id, resourceType: 'User', resourceId: $user->id,
            newValue: array_filter([
                'email' => $user->email,
                'applications' => $applications->pluck('application_code')->all(),
                'roles' => $roles->pluck('code')->all(),
                'activation_state' => $user->activationState(),
                'temporary_password_expires_at' => $result['outcome'] === 'created' ? $user->temporary_password_expires_at?->toIso8601String() : null,
            ], fn ($v) => $v !== null));

        return ['outcome' => $result['outcome'], 'user' => $user, 'temporary_password' => $result['plain'] ?? null];
    }

    /** Records that the operator verified the address outside the system. Never sent anywhere, never derived from anything else. */
    public function verifyEmail(Tenant $tenant, User $target, string $email, string $reason, User $actor, Request $request): User
    {
        if ($actor->is($target)) {
            $this->audit->record('user.email_verification_denied', $request, actor: $actor, tenantId: $tenant->id, resourceType: 'User', resourceId: $target->id,
                metadata: ['reason' => 'self_verification']);

            throw new AdminBootstrapException('SELF_VERIFICATION_DENIED', 'Nobody verifies their own e-mail address through the operator API.', 403);
        }

        return DB::transaction(function () use ($tenant, $target, $email, $reason, $actor, $request): User {
            $user = User::query()->lockForUpdate()->find($target->id);
            $this->assertMember($user, $tenant);

            if (! in_array($user->status, [User::STATUS_INVITED, User::STATUS_ACTIVE], true)) {
                throw new AdminBootstrapException('ACCOUNT_NOT_ELIGIBLE', 'A suspended or disabled account cannot be verified. Reactivate it first.', 409);
            }

            if (Str::lower(trim($email)) !== Str::lower($user->email)) {
                throw new AdminBootstrapException('EMAIL_MISMATCH', 'The e-mail address you verified is not the address on the account any more. Check the account again.', 422);
            }

            if ($user->email_verified_at !== null) {
                throw new AdminBootstrapException('EMAIL_ALREADY_VERIFIED', 'This e-mail address is already verified.', 409);
            }

            $user->forceFill([
                'email_verified_at' => now(),
                'email_verification_method' => User::EMAIL_VERIFIED_BY_OPERATOR,
                'email_verified_by' => $actor->id,
            ])->save();

            $this->audit->record('user.email_verified_manually', $request, actor: $actor, tenantId: $tenant->id, resourceType: 'User', resourceId: $user->id,
                newValue: ['verified_email' => $user->email, 'verified_at' => $user->email_verified_at->toIso8601String(), 'operator_id' => $actor->id],
                metadata: ['reason' => trim($reason)]);

            $this->activation->completeIfReady($user, $request, $actor);

            return $user;
        });
    }

    /**
     * A new temporary password for an account whose activation is still open and whose holder has not chosen a password yet. Never
     * for an activated account: replacing a password the person chose would be an account takeover, not an onboarding step.
     *
     * @return array{user: User, temporary_password: string}
     */
    public function reissue(Tenant $tenant, User $target, User $actor, Request $request): array
    {
        return DB::transaction(function () use ($tenant, $target, $actor, $request): array {
            $user = User::query()->lockForUpdate()->find($target->id);
            $this->assertMember($user, $tenant);

            if (! in_array($user->status, [User::STATUS_ACTIVE, User::STATUS_INVITED], true)
                || ! TenantMembership::query()->where('tenant_id', $tenant->id)->where('user_id', $user->id)->where('is_tenant_admin', true)->exists()
                || $this->authorization->hasEcosystemAccess($user)) {
                throw new AdminBootstrapException('IDENTITY_NOT_LINKABLE', 'Only an active or invited tenant administrator can receive a temporary password.', 409);
            }
            if ($user->status === User::STATUS_INVITED && ! $user->password_change_required) {
                throw new AdminBootstrapException('PASSWORD_ALREADY_CHOSEN', 'The holder has chosen a password and is waiting for verification.', 409);
            }
            $user->forceFill(['activation_started_at' => now(), 'activated_at' => null, 'status' => User::STATUS_INVITED])->save();
            app(SessionRevocationService::class)->logoutEverywhere($user, 'temporary_password_reset', $request);

            $plain = $this->activation->issueTemporaryPassword($user);

            $this->audit->record('user.temporary_password_reissued', $request, actor: $actor, tenantId: $tenant->id, resourceType: 'User', resourceId: $user->id,
                newValue: ['expires_at' => $user->temporary_password_expires_at->toIso8601String()]);

            return ['user' => $user, 'temporary_password' => $plain];
        });
    }

    /** @return array<int, array<string, mixed>> */
    public function list(Tenant $tenant): array
    {
        $memberships = TenantMembership::query()->where('tenant_id', $tenant->id)->where('is_tenant_admin', true)->with('user')->get()
            ->filter(fn (TenantMembership $m) => $m->user !== null);

        return $memberships->map(fn (TenantMembership $m): array => $this->view($tenant, $m->user, $m))
            ->sortBy(fn (array $row) => $row['user']['created_at'])->values()->all();
    }

    /** @return array<string, mixed> */
    public function view(Tenant $tenant, User $user, ?TenantMembership $membership = null): array
    {
        $membership ??= TenantMembership::query()->where('tenant_id', $tenant->id)->where('user_id', $user->id)->first();

        $applications = Application::query()->whereIn('id', UserApplicationAccess::query()
            ->where('user_id', $user->id)->where('tenant_id', $tenant->id)->where('status', UserApplicationAccess::STATUS_ACTIVE)->select('application_id'))
            ->orderBy('name')->get(['id', 'application_code', 'name']);

        $roles = Role::query()->whereIn('id', UserRole::query()->where('user_id', $user->id)->where('tenant_id', $tenant->id)->select('role_id'))
            ->orderBy('name')->get(['id', 'code', 'name']);

        return [
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'status' => $user->status,
                'created_at' => $user->created_at?->toIso8601String(),
            ],
            'membership' => ['status' => $membership?->status, 'is_tenant_admin' => (bool) $membership?->is_tenant_admin],
            'applications' => $applications->map(fn (Application $a) => ['id' => $a->id, 'code' => $a->application_code, 'name' => $a->name])->all(),
            'roles' => $roles->map(fn (Role $r) => ['id' => $r->id, 'code' => $r->code, 'name' => $r->name])->all(),
            'activation' => $this->activation->view($user),
        ];
    }

    // ---------------------------------------------------------------- creation

    /**
     * @param  EloquentCollection<int, Application>  $applications
     * @param  EloquentCollection<int, Role>  $roles
     * @return array{outcome: string, user: User, plain: string}
     */
    private function createNew(Tenant $tenant, string $name, string $email, EloquentCollection $applications, EloquentCollection $roles, User $actor): array
    {
        $email = Str::lower(trim($email));

        if (User::withTrashed()->whereRaw('lower(email) = ?', [$email])->exists()) {
            throw $this->emailConflict($email);
        }

        $user = User::create(['name' => trim($name), 'email' => $email, 'status' => User::STATUS_INVITED]);
        $user->forceFill(['activation_started_at' => now()])->save();
        $plain = $this->activation->issueTemporaryPassword($user);

        $this->grant($tenant, $user, $applications, $roles, $actor);

        return ['outcome' => 'created', 'user' => $user->refresh(), 'plain' => $plain];
    }

    /**
     * @param  EloquentCollection<int, Application>  $applications
     * @param  EloquentCollection<int, Role>  $roles
     * @return array{outcome: string, user: User}
     */
    private function linkExisting(Tenant $tenant, string $userId, string $confirmEmail, EloquentCollection $applications, EloquentCollection $roles, User $actor): array
    {
        $user = Str::isUuid($userId) ? User::withTrashed()->lockForUpdate()->find($userId) : null;

        if (! $user) {
            throw new AdminBootstrapException('IDENTITY_NOT_FOUND', 'No such account.', 404);
        }

        if ($user->trashed() || $user->status !== User::STATUS_ACTIVE || $this->authorization->hasEcosystemAccess($user)) {
            throw new AdminBootstrapException('IDENTITY_NOT_LINKABLE', 'Only an active tenant account can be linked.', 409);
        }
        $belongsToCustomer = $user->tenantMemberships()->whereHas('tenant', fn ($query) => $query->where('customer_id', $tenant->customer_id))->exists()
            || Str::lower($user->email) === Str::lower((string) $tenant->customer?->email);
        if (! $belongsToCustomer) {
            throw new AdminBootstrapException('IDENTITY_NOT_LINKABLE', 'Select an account belonging to this customer.', 422);
        }
        $email = Str::lower(trim($confirmEmail));
        if ($email !== Str::lower($user->email)) {
            if (! $this->authorization->userHasPermission($actor, 'cgo.user.update')) {
                throw new AdminBootstrapException('UNAUTHORIZED', 'Changing the account email requires user update permission.', 403);
            }
            if (User::withTrashed()->whereKeyNot($user->id)->whereRaw('lower(email) = ?', [$email])->exists()) {
                throw $this->emailConflict($email);
            }
            $oldEmail = $user->email;
            $user->update(['email' => $email]);
            $this->audit->record('user.email_updated', request(), actor: $actor, resourceType: 'User', resourceId: $user->id,
                oldValue: ['email' => $oldEmail], newValue: ['email' => $email], tenantId: $tenant->id);
        }

        $membership = TenantMembership::query()->where('user_id', $user->id)->where('tenant_id', $tenant->id)->first();
        if ($membership && $membership->status !== TenantMembership::STATUS_ACTIVE) {
            throw new AdminBootstrapException('MEMBERSHIP_NOT_ACTIVE', 'The membership of this account in the tenant was suspended. Resolve that first.', 409);
        }

        $this->grant($tenant, $user, $applications, $roles, $actor);

        return ['outcome' => 'linked', 'user' => $user->refresh()];
    }

    /**
     * @param  EloquentCollection<int, Application>  $applications
     * @param  EloquentCollection<int, Role>  $roles
     */
    private function grant(Tenant $tenant, User $user, EloquentCollection $applications, EloquentCollection $roles, User $actor): void
    {
        $membership = TenantMembership::query()->firstOrCreate(
            ['user_id' => $user->id, 'tenant_id' => $tenant->id],
            ['status' => TenantMembership::STATUS_ACTIVE, 'is_tenant_admin' => true],
        );
        if (! $membership->is_tenant_admin) {
            $membership->update(['is_tenant_admin' => true]);
        }

        foreach ($applications as $application) {
            $this->seats->grantAccess($tenant, $application, $user);
        }

        foreach ($roles as $role) {
            UserRole::query()->firstOrCreate(
                ['user_id' => $user->id, 'role_id' => $role->id, 'tenant_id' => $tenant->id],
                ['granted_by' => $actor->id],
            );
        }
    }

    // ---------------------------------------------------------------- checks

    /**
     * What the Admin Tenant is entitled to: only applications the tenant is subscribed to (assigned and active), never the governance
     * application itself.
     *
     * @param  array<int, string>  $ids
     * @return EloquentCollection<int, Application>
     */
    private function resolveApplications(Tenant $tenant, array $ids): EloquentCollection
    {
        $ids = array_values(array_unique($ids));
        $applications = Application::query()->whereIn('id', array_filter($ids, fn (string $id) => Str::isUuid($id)))->get();

        $refused = [];
        foreach ($ids as $id) {
            $application = $applications->firstWhere('id', $id);
            $subscribed = $application
                && ! in_array($application->application_code, self::PROTECTED_APPLICATION_CODES, true)
                && $this->authorization->applicationIsUsable($application)
                && $tenant->applications()->where('applications.id', $application->id)->wherePivot('status', 'ACTIVE')->exists();

            if (! $subscribed) {
                $refused[] = $id;
            }
        }

        if ($refused !== [] || $ids === []) {
            throw new AdminBootstrapException('APPLICATION_NOT_SUBSCRIBED', 'Each application must exist, be active, and be assigned to this tenant by its subscription.', 422, ['application_ids' => $refused]);
        }

        return $applications;
    }

    /**
     * @param  EloquentCollection<int, Application>  $applications
     * @param  array<int, string>  $ids
     * @return EloquentCollection<int, Role>
     */
    private function resolveRoles(Tenant $tenant, EloquentCollection $applications, array $ids): EloquentCollection
    {
        $ids = array_values(array_unique($ids));
        if ($ids === []) {
            return new EloquentCollection;
        }

        $roles = Role::query()->whereIn('id', array_filter($ids, fn (string $id) => Str::isUuid($id)))->where('status', Role::STATUS_ACTIVE)->get();
        $applicationIds = $applications->pluck('id')->all();

        $refused = array_values(array_filter($ids, function (string $id) use ($roles, $tenant, $applicationIds): bool {
            $role = $roles->firstWhere('id', $id);

            return ! $role || match ($role->role_type) {
                Role::TYPE_TENANT => $role->tenant_id !== $tenant->id,
                Role::TYPE_APPLICATION => ! in_array($role->application_id, $applicationIds, true)
                    || ($role->tenant_id !== null && $role->tenant_id !== $tenant->id),
                default => true,
            };
        }));

        if ($refused !== []) {
            throw new AdminBootstrapException('ROLE_NOT_ASSIGNABLE', 'A role is unknown, inactive, belongs to another tenant or application, or is a platform role.', 422, ['role_ids' => $refused]);
        }

        return $roles;
    }

    /**
     * The escalation guard of this flow: the operator can only hand over what they could grant one step at a time, in this tenant.
     *
     * @param  EloquentCollection<int, Role>  $roles
     */
    private function assertOperatorMayGrant(User $actor, Tenant $tenant, EloquentCollection $roles, bool $creating): void
    {
        $needed = ['cgo.tenant.admin.assign', 'cgo.user.tenant.assign', 'cgo.user.application.assign'];
        if ($creating) {
            $needed[] = 'cgo.user.create';
        }
        if ($roles->isNotEmpty()) {
            $needed[] = 'cgo.user.role.assign';
        }

        $missing = array_values(array_filter($needed, fn (string $key) => ! $this->authorization->userHasPermission($actor, $key, $tenant->id)));
        $excess = $roles->filter(fn (Role $role) => ! $this->escalationGuard->canGrantRole($actor, $role, $tenant->id))->pluck('id')->all();

        if ($missing !== [] || $excess !== []) {
            throw new AdminBootstrapException('PRIVILEGE_ESCALATION_DENIED', 'You cannot grant access or roles beyond your own permissions.', 403, array_filter(['missing_permissions' => $missing, 'role_ids' => $excess]));
        }
    }

    private function assertMember(?User $user, Tenant $tenant): void
    {
        $member = $user && TenantMembership::query()->where('user_id', $user->id)->where('tenant_id', $tenant->id)->exists();

        if (! $member) {
            throw new AdminBootstrapException('USER_NOT_IN_TENANT', 'The account does not belong to this tenant.', 404);
        }
    }

    private function emailConflict(string $email): AdminBootstrapException
    {
        $existing = $email !== '' ? User::withTrashed()->whereRaw('lower(email) = ?', [$email])->first() : null;

        return new AdminBootstrapException('IDENTITY_CONFLICT', 'An account with this e-mail already exists. It is never adopted by e-mail alone: to make it an Admin Tenant, choose that account explicitly.', 409,
            $existing ? ['existing_user_id' => $existing->id, 'status' => $existing->status, 'deleted' => $existing->trashed()] : null);
    }
}
