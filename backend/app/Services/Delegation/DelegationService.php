<?php

namespace App\Services\Delegation;

use App\Models\Application;
use App\Models\Capability;
use App\Models\Entitlement;
use App\Models\Permission;
use App\Models\Role;
use App\Models\ServiceAccount;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use App\Models\UserApplicationAccess;
use App\Models\UserRole;
use App\Services\AuditService;
use App\Services\AuthorizationService;
use App\Services\Commercial\EntitlementService;
use App\Services\Oidc\SessionRevocationService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Tenant delegation (audit R08/A25): what an Admin Tenant may do to the users and roles of their own tenant, for the product
 * they are using, without entering the ecosystem portal. The product backend calls this with its own service account; every
 * call is re-verified here, so nothing the product sends is trusted without a check:
 *
 *  - the service account is bound to one application (and optionally one tenant) and the application is live;
 *  - the tenant is usable and has the application assigned (the subscription outcome);
 *  - the acting user is an active member with the Admin Tenant flag (which only operators can set) and has access to the
 *    application in that tenant.
 *
 * What may be handed out is the DELEGABLE set: permissions of this application flagged `delegable` by the application's
 * manifest or an operator, whose capability (module/feature) the tenant is entitled to. It is never derived from what the
 * Admin Tenant holds personally. Roles created here are APPLICATION roles bound to this tenant and this application; ecosystem
 * (cgo.*) permissions, other applications' permissions and other tenants can never be reached.
 */
class DelegationService
{
    private const PROTECTED_APPLICATION_CODES = ['cgo'];

    public function __construct(
        private readonly AuthorizationService $authorization,
        private readonly EntitlementService $entitlements,
        private readonly SessionRevocationService $revocation,
        private readonly AuditService $audit,
    ) {}

    public function context(ServiceAccount $serviceAccount, Tenant $tenant, string $actingUserId): DelegationContext
    {
        $application = $serviceAccount->application;

        if (! $application) {
            throw new DelegationException('SERVICE_ACCOUNT_NOT_BOUND', 'This service account is not bound to an application.');
        }

        if (in_array($application->application_code, self::PROTECTED_APPLICATION_CODES, true)) {
            throw new DelegationException('APPLICATION_NOT_DELEGABLE', 'The governance application cannot be managed through delegation.');
        }

        if ($serviceAccount->tenant_id !== null && $serviceAccount->tenant_id !== $tenant->id) {
            throw new DelegationException('TENANT_MISMATCH', 'The service account is bound to another tenant.');
        }

        if (! $this->authorization->tenantIsUsable($tenant)) {
            throw new DelegationException('TENANT_NOT_ACTIVE', 'The tenant is not active.');
        }

        if (! $this->authorization->applicationIsUsable($application)) {
            throw new DelegationException('APPLICATION_NOT_ACTIVE', 'The application is not active.');
        }

        $assigned = $tenant->applications()->where('applications.id', $application->id)->wherePivot('status', 'ACTIVE')->exists();
        if (! $assigned) {
            throw new DelegationException('APPLICATION_NOT_SUBSCRIBED', 'The tenant has no active subscription to this application.');
        }

        // One uniform answer for every way the acting user can be unfit, so the caller learns nothing about other identities.
        $notAllowed = new DelegationException('ACTOR_NOT_TENANT_ADMIN', 'The acting user is not an Admin Tenant of this tenant for this application.');

        $actor = User::query()->find($actingUserId);
        if (! $actor || ! $actor->isActive()) {
            throw $notAllowed;
        }

        $isAdmin = TenantMembership::query()->where('user_id', $actor->id)->where('tenant_id', $tenant->id)
            ->where('status', TenantMembership::STATUS_ACTIVE)->where('is_tenant_admin', true)->exists();
        $hasAccess = UserApplicationAccess::query()->where('user_id', $actor->id)->where('tenant_id', $tenant->id)
            ->where('application_id', $application->id)->where('status', UserApplicationAccess::STATUS_ACTIVE)->exists();

        if (! $isAdmin || ! $hasAccess) {
            throw $notAllowed;
        }

        return new DelegationContext($serviceAccount, $application, $tenant, $actor);
    }

    /**
     * The explicit delegable set for this tenant and application.
     *
     * @return Collection<int, Permission>
     */
    public function delegablePermissions(DelegationContext $context): Collection
    {
        $entitled = $this->entitledCapabilityCodes($context);

        return Permission::query()
            ->where('application_id', $context->application->id)
            ->where('delegable', true)
            ->where('status', Permission::STATUS_ACTIVE)
            ->where('permission_key', 'not like', 'cgo.%')
            ->with('capability')
            ->orderBy('permission_key')
            ->get()
            ->filter(function (Permission $permission) use ($entitled): bool {
                $capability = $permission->capability;

                return $capability === null
                    || ($capability->status === Capability::STATUS_ACTIVE && in_array($capability->code, $entitled, true));
            })
            ->values();
    }

    /** @return array<int, string> codes of the capabilities (modules/features) the tenant is entitled to for this application */
    private function entitledCapabilityCodes(DelegationContext $context): array
    {
        return $this->entitlements->effectiveEntitlements($context->tenant, $context->application->application_code)
            ->filter(fn (array $row): bool => $row['entitlement_type'] === Entitlement::TYPE_CAPABILITY && $row['value'] === true)
            ->pluck('entitlement_key')
            ->all();
    }

    /** @return array<string, mixed> */
    public function catalog(DelegationContext $context): array
    {
        return [
            'tenant_id' => $context->tenant->id,
            'application_code' => $context->application->application_code,
            'delegable_permissions' => $this->delegablePermissions($context)->map(fn (Permission $p): array => [
                'permission' => $p->permission_key,
                'name' => $p->name,
                'description' => $p->description,
                'capability' => $p->capability?->code,
            ])->all(),
            'roles' => $this->manageableRoles($context)->map(fn (Role $role): array => $this->roleView($role))->all(),
        ];
    }

    /**
     * Roles of this tenant and application that can be assigned from the product: the tenant's own delegation roles and the
     * application's role templates (APPLICATION roles without a tenant), as long as every permission they carry is delegable.
     *
     * @return Collection<int, Role>
     */
    public function manageableRoles(DelegationContext $context): Collection
    {
        $delegable = $this->delegablePermissions($context)->pluck('permission_key')->all();

        return Role::query()
            ->where('role_type', Role::TYPE_APPLICATION)
            ->where('application_id', $context->application->id)
            ->where('status', Role::STATUS_ACTIVE)
            ->where(fn (Builder $q) => $q->where('tenant_id', $context->tenant->id)->orWhereNull('tenant_id'))
            ->with('permissions')
            ->orderBy('name')
            ->get()
            ->filter(fn (Role $role): bool => $role->permissions->pluck('permission_key')->diff($delegable)->isEmpty())
            ->values();
    }

    /** @return array<string, mixed> */
    public function roleView(Role $role): array
    {
        return [
            'id' => $role->id,
            'code' => $role->code,
            'name' => $role->name,
            'description' => $role->description,
            'template' => $role->tenant_id === null,
            'editable' => $role->origin === Role::ORIGIN_DELEGATION && $role->tenant_id !== null,
            'permissions' => $role->permissions->pluck('permission_key')->sort()->values()->all(),
        ];
    }

    // ---------------------------------------------------------------- users

    public function listUsers(DelegationContext $context, ?string $search, int $perPage): array
    {
        $query = User::query()
            ->whereIn('id', UserApplicationAccess::query()
                ->where('tenant_id', $context->tenant->id)->where('application_id', $context->application->id)->select('user_id'))
            ->when($search, fn (Builder $q) => $q->where(fn (Builder $q2) => $q2
                ->where('name', 'ilike', '%'.addcslashes($search, '%_\\').'%')
                ->orWhere('email', 'ilike', '%'.addcslashes($search, '%_\\').'%')))
            ->orderBy('name');

        $page = $query->paginate(max(1, min(100, $perPage)));

        return [
            'items' => collect($page->items())->map(fn (User $user): array => $this->userView($context, $user))->all(),
            'pagination' => ['current_page' => $page->currentPage(), 'per_page' => $page->perPage(), 'total' => $page->total(), 'last_page' => $page->lastPage()],
        ];
    }

    /** @return array<string, mixed> */
    public function userView(DelegationContext $context, User $user): array
    {
        $membership = TenantMembership::query()->where('user_id', $user->id)->where('tenant_id', $context->tenant->id)->first();
        $access = UserApplicationAccess::query()->where('user_id', $user->id)->where('tenant_id', $context->tenant->id)
            ->where('application_id', $context->application->id)->first();

        $roles = Role::query()
            ->whereIn('id', UserRole::query()->where('user_id', $user->id)->where('tenant_id', $context->tenant->id)->select('role_id'))
            ->where('role_type', Role::TYPE_APPLICATION)->where('application_id', $context->application->id)
            ->with('permissions')->orderBy('name')->get();

        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'status' => $user->status,
            'membership_status' => $membership?->status,
            'is_tenant_admin' => (bool) $membership?->is_tenant_admin,
            'application_access' => $access?->status,
            'roles' => $roles->map(fn (Role $role): array => $this->roleView($role))->all(),
        ];
    }

    /**
     * Creates the central identity, or links an existing one, to this tenant and this application. Idempotent: the same email
     * never yields a second identity, membership or access row.
     *
     * @param  array{email: string, name: string, initial_password?: ?string, role_ids?: array<int, string>}  $data
     * @return array{outcome: string, user: User}
     */
    public function addUser(DelegationContext $context, array $data, Request $request): array
    {
        $email = Str::lower(trim($data['email']));
        $roleIds = array_values(array_unique($data['role_ids'] ?? []));
        $roles = $this->resolveAssignableRoles($context, $roleIds);

        $result = DB::transaction(function () use ($context, $data, $email) {
            $existing = User::withTrashed()->whereRaw('lower(email) = ?', [$email])->first();

            if ($existing) {
                return $this->linkExisting($context, $existing);
            }

            try {
                $user = User::create([
                    'name' => $data['name'],
                    'email' => $email,
                    'password' => $data['initial_password'] ?? null,
                    'status' => ! empty($data['initial_password']) ? User::STATUS_ACTIVE : User::STATUS_INVITED,
                ]);
            } catch (UniqueConstraintViolationException) {
                // A concurrent request created the same identity first: join it instead of creating a duplicate.
                $existing = User::withTrashed()->whereRaw('lower(email) = ?', [$email])->firstOrFail();

                return $this->linkExisting($context, $existing);
            }

            $this->ensureMembershipAndAccess($context, $user);

            return ['outcome' => 'created', 'user' => $user];
        });

        $user = $result['user'];

        if ($roles->isNotEmpty()) {
            $this->replaceRoles($context, $user, $roles, $request, audit: false);
        }

        $this->audit->record(
            'delegation.user_'.$result['outcome'],
            $request,
            actor: $context->actor,
            tenantId: $context->tenant->id,
            applicationId: $context->application->id,
            resourceType: 'User',
            resourceId: $user->id,
            newValue: ['email' => $user->email, 'roles' => $roles->pluck('code')->all()],
            metadata: $context->auditMetadata(),
        );

        return $result;
    }

    /** @return array{outcome: string, user: User} */
    private function linkExisting(DelegationContext $context, User $existing): array
    {
        $membership = TenantMembership::query()->where('user_id', $existing->id)->where('tenant_id', $context->tenant->id)->first();

        // An invited identity (no sign-in yet) is only ever re-used by the tenant that invited it; any other state must be active.
        $usable = $existing->isActive() || ($existing->status === User::STATUS_INVITED && $membership !== null);

        if ($existing->trashed() || ! $usable || $this->authorization->hasEcosystemAccess($existing)) {
            throw new DelegationException('IDENTITY_NOT_LINKABLE', 'This email belongs to an identity that cannot be added from a product.', 409);
        }

        if ($membership && $membership->status !== TenantMembership::STATUS_ACTIVE) {
            throw new DelegationException('MEMBERSHIP_NOT_ACTIVE', 'The tenant membership of this user was suspended by an operator.', 409);
        }

        $access = UserApplicationAccess::query()->where('user_id', $existing->id)->where('tenant_id', $context->tenant->id)
            ->where('application_id', $context->application->id)->first();

        $already = $membership !== null && $access?->status === UserApplicationAccess::STATUS_ACTIVE;

        $this->ensureMembershipAndAccess($context, $existing);

        return ['outcome' => $already ? 'existing' : 'linked', 'user' => $existing];
    }

    private function ensureMembershipAndAccess(DelegationContext $context, User $user): void
    {
        TenantMembership::query()->firstOrCreate(
            ['user_id' => $user->id, 'tenant_id' => $context->tenant->id],
            ['status' => TenantMembership::STATUS_ACTIVE, 'is_tenant_admin' => false],
        );

        $access = UserApplicationAccess::query()->firstOrCreate(
            ['user_id' => $user->id, 'tenant_id' => $context->tenant->id, 'application_id' => $context->application->id],
            ['status' => UserApplicationAccess::STATUS_ACTIVE],
        );

        if ($access->status !== UserApplicationAccess::STATUS_ACTIVE) {
            $access->update(['status' => UserApplicationAccess::STATUS_ACTIVE]);
        }
    }

    /** Takes this application away from a user of the tenant: sessions end, the application roles go, the identity stays. */
    public function revokeUser(DelegationContext $context, User $user, Request $request): void
    {
        $this->assertManageableTarget($context, $user);

        if ($user->id === $context->actor->id) {
            throw new DelegationException('SELF_REVOCATION_DENIED', 'You cannot remove your own access.', 409);
        }

        // Before the rows go: they decide which application has to be told.
        $this->revocation->revokeAccess($user, 'application_access_revoked', $context->tenant, $context->application, $request);

        DB::transaction(function () use ($context, $user): void {
            UserApplicationAccess::query()->where('user_id', $user->id)->where('tenant_id', $context->tenant->id)
                ->where('application_id', $context->application->id)->delete();

            UserRole::query()->where('user_id', $user->id)->where('tenant_id', $context->tenant->id)
                ->whereIn('role_id', Role::query()->where('role_type', Role::TYPE_APPLICATION)
                    ->where('application_id', $context->application->id)->select('id'))->delete();
        });

        $this->audit->record('delegation.user_access_revoked', $request, actor: $context->actor, tenantId: $context->tenant->id,
            applicationId: $context->application->id, resourceType: 'User', resourceId: $user->id, metadata: $context->auditMetadata());
    }

    /**
     * Replaces the application roles of a user in this tenant with exactly the given ones. Roles an operator assigned that the
     * delegation cannot manage (not delegable) are left alone.
     *
     * @param  array<int, string>  $roleIds
     */
    public function setUserRoles(DelegationContext $context, User $user, array $roleIds, Request $request): void
    {
        $this->assertManageableTarget($context, $user);

        $roles = $this->resolveAssignableRoles($context, array_values(array_unique($roleIds)));
        $this->replaceRoles($context, $user, $roles, $request, audit: true);
    }

    /** @param  Collection<int, Role>  $desired */
    private function replaceRoles(DelegationContext $context, User $user, Collection $desired, Request $request, bool $audit): void
    {
        $manageableIds = $this->manageableRoles($context)->pluck('id');

        DB::transaction(function () use ($context, $user, $desired, $manageableIds): void {
            UserRole::query()->where('user_id', $user->id)->where('tenant_id', $context->tenant->id)
                ->whereIn('role_id', $manageableIds)->whereNotIn('role_id', $desired->pluck('id'))->delete();

            foreach ($desired as $role) {
                UserRole::query()->firstOrCreate(
                    ['user_id' => $user->id, 'role_id' => $role->id, 'tenant_id' => $context->tenant->id],
                    ['granted_by' => $context->actor->id],
                );
            }
        });

        if ($audit) {
            $this->audit->record('delegation.user_roles_set', $request, actor: $context->actor, tenantId: $context->tenant->id,
                applicationId: $context->application->id, resourceType: 'User', resourceId: $user->id,
                newValue: ['roles' => $desired->pluck('code')->all()], metadata: $context->auditMetadata());
        }
    }

    /**
     * @param  array<int, string>  $roleIds
     * @return Collection<int, Role>
     */
    private function resolveAssignableRoles(DelegationContext $context, array $roleIds): Collection
    {
        if ($roleIds === []) {
            return collect();
        }

        $manageable = $this->manageableRoles($context)->keyBy('id');
        $refused = array_values(array_filter($roleIds, fn (string $id): bool => ! $manageable->has($id)));

        if ($refused !== []) {
            throw new DelegationException('ROLE_NOT_ASSIGNABLE', 'A role is unknown, belongs to another tenant or application, or carries permissions that are not delegable.', 422, ['role_ids' => $refused]);
        }

        return collect($roleIds)->map(fn (string $id): Role => $manageable->get($id));
    }

    /** A user whose access the delegation may change: a member of this tenant with access to this application, never an Admin Tenant. */
    private function assertManageableTarget(DelegationContext $context, User $user): void
    {
        $membership = TenantMembership::query()->where('user_id', $user->id)->where('tenant_id', $context->tenant->id)->first();
        $access = UserApplicationAccess::query()->where('user_id', $user->id)->where('tenant_id', $context->tenant->id)
            ->where('application_id', $context->application->id)->exists();

        if (! $membership || ! $access) {
            throw new DelegationException('USER_NOT_IN_TENANT', 'The user does not belong to this tenant and application.', 404);
        }

        if ($membership->is_tenant_admin) {
            throw new DelegationException('TENANT_ADMIN_PROTECTED', 'An Admin Tenant can only be changed from the ecosystem portal.', 409);
        }

        if ($this->authorization->hasEcosystemAccess($user)) {
            throw new DelegationException('IDENTITY_NOT_LINKABLE', 'This identity cannot be changed from a product.', 409);
        }
    }

    // ---------------------------------------------------------------- roles

    /**
     * @param  array{name: string, description?: ?string, permissions: array<int, string>}  $data
     */
    public function createRole(DelegationContext $context, array $data, Request $request): Role
    {
        $permissionIds = $this->resolveDelegablePermissionIds($context, $data['permissions']);

        $role = DB::transaction(function () use ($context, $data, $permissionIds): Role {
            $role = Role::create([
                'tenant_id' => $context->tenant->id,
                'application_id' => $context->application->id,
                'name' => $data['name'],
                'code' => $this->uniqueRoleCode($context->tenant, $data['name']),
                'description' => $data['description'] ?? null,
                'role_type' => Role::TYPE_APPLICATION,
                'is_system' => false,
                'origin' => Role::ORIGIN_DELEGATION,
                'created_by' => $context->actor->id,
                'status' => Role::STATUS_ACTIVE,
            ]);

            $this->syncPermissions($role, $permissionIds, $context->actor);

            return $role;
        });

        $this->audit->record('delegation.role_created', $request, actor: $context->actor, tenantId: $context->tenant->id,
            applicationId: $context->application->id, resourceType: 'Role', resourceId: $role->id,
            newValue: ['code' => $role->code, 'permissions' => $data['permissions']], metadata: $context->auditMetadata());

        return $role->load('permissions');
    }

    /**
     * @param  array{name?: string, description?: ?string, permissions?: array<int, string>}  $data
     */
    public function updateRole(DelegationContext $context, Role $role, array $data, Request $request): Role
    {
        $this->assertEditableRole($context, $role);

        $permissionIds = array_key_exists('permissions', $data) ? $this->resolveDelegablePermissionIds($context, $data['permissions']) : null;
        $old = ['name' => $role->name, 'permissions' => $role->permissions()->pluck('permission_key')->sort()->values()->all()];

        DB::transaction(function () use ($context, $role, $data, $permissionIds): void {
            $role->update(array_filter(['name' => $data['name'] ?? null, 'description' => $data['description'] ?? null], fn ($v) => $v !== null));

            if ($permissionIds !== null) {
                $this->syncPermissions($role, $permissionIds, $context->actor);
            }
        });

        $role->load('permissions');

        $this->audit->record('delegation.role_updated', $request, actor: $context->actor, tenantId: $context->tenant->id,
            applicationId: $context->application->id, resourceType: 'Role', resourceId: $role->id, oldValue: $old,
            newValue: ['name' => $role->name, 'permissions' => $role->permissions->pluck('permission_key')->sort()->values()->all()],
            metadata: $context->auditMetadata());

        return $role;
    }

    public function deleteRole(DelegationContext $context, Role $role, Request $request): void
    {
        $this->assertEditableRole($context, $role);

        if (UserRole::query()->where('role_id', $role->id)->exists()) {
            throw new DelegationException('ROLE_IN_USE', 'The role is still assigned to users.', 409);
        }

        $role->delete();

        $this->audit->record('delegation.role_deleted', $request, actor: $context->actor, tenantId: $context->tenant->id,
            applicationId: $context->application->id, resourceType: 'Role', resourceId: $role->id,
            oldValue: ['code' => $role->code], metadata: $context->auditMetadata());
    }

    /** Only roles created through delegation, for this very tenant and application, can be changed from a product. */
    public function assertEditableRole(DelegationContext $context, Role $role): void
    {
        $editable = $role->origin === Role::ORIGIN_DELEGATION
            && $role->tenant_id === $context->tenant->id
            && $role->application_id === $context->application->id
            && $role->role_type === Role::TYPE_APPLICATION;

        if (! $editable) {
            throw new DelegationException('ROLE_NOT_EDITABLE', 'This role cannot be changed from a product.', 404);
        }
    }

    /**
     * @param  array<int, string>  $keys
     * @return array<int, string> permission ids
     */
    private function resolveDelegablePermissionIds(DelegationContext $context, array $keys): array
    {
        $keys = array_values(array_unique($keys));
        $delegable = $this->delegablePermissions($context)->keyBy('permission_key');
        $refused = array_values(array_filter($keys, fn (string $key): bool => ! $delegable->has($key)));

        if ($refused !== []) {
            throw new DelegationException('PERMISSION_NOT_DELEGABLE', 'A permission is unknown, outside this application, not delegable or not covered by the tenant entitlements.', 422, ['permissions' => $refused]);
        }

        return array_map(fn (string $key): string => $delegable->get($key)->id, $keys);
    }

    /** @param  array<int, string>  $permissionIds */
    private function syncPermissions(Role $role, array $permissionIds, User $actor): void
    {
        $sync = [];
        foreach ($permissionIds as $id) {
            $sync[$id] = ['id' => (string) Str::uuid(), 'granted_by' => $actor->id];
        }
        $role->permissions()->sync($sync);
    }

    private function uniqueRoleCode(Tenant $tenant, string $name): string
    {
        $base = Str::upper(trim((string) preg_replace('/[^A-Za-z0-9]+/', '_', $name), '_')) ?: 'ROLE';
        $base = Str::limit($base, 40, '');

        do {
            $code = $base.'_'.Str::upper(Str::random(4));
        } while (Role::withTrashed()->where('tenant_id', $tenant->id)->where('code', $code)->exists());

        return $code;
    }

    public function findRole(DelegationContext $context, string $roleId): Role
    {
        $role = Role::query()->with('permissions')->where('id', $roleId)->first();

        if (! $role) {
            throw new DelegationException('ROLE_NOT_EDITABLE', 'This role cannot be changed from a product.', 404);
        }

        return $role;
    }

    public function findTarget(DelegationContext $context, string $userId): User
    {
        $user = User::query()->find($userId);

        if (! $user) {
            throw new DelegationException('USER_NOT_IN_TENANT', 'The user does not belong to this tenant and application.', 404);
        }

        return $user;
    }
}
