<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\V1\Concerns\ApiResponses;
use App\Http\Controllers\Api\V1\Concerns\HandlesLifecycleTransitions;
use App\Http\Controllers\Controller;
use App\Http\Requests\V1\StoreUserRequest;
use App\Http\Requests\V1\UpdateUserRequest;
use App\Http\Resources\V1\ApplicationResource;
use App\Http\Resources\V1\PermissionResource;
use App\Http\Resources\V1\RoleResource;
use App\Http\Resources\V1\TenantResource;
use App\Http\Resources\V1\UserResource;
use App\Models\Application;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use App\Models\UserApplicationAccess;
use App\Services\AuditService;
use App\Services\AuthorizationService;
use App\Services\Oidc\SessionRevocationService;
use App\Services\PrivilegeEscalationGuard;
use App\Services\Seats\SeatService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class UserController extends Controller
{
    use ApiResponses, HandlesLifecycleTransitions;

    public function __construct(
        private readonly AuditService $audit,
        private readonly AuthorizationService $authorization,
        private readonly PrivilegeEscalationGuard $escalationGuard,
        private readonly SessionRevocationService $revocation,
        private readonly SeatService $seats,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $query = User::query();

        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }

        if ($search = $request->query('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'ilike', "%{$search}%")->orWhere('email', 'ilike', "%{$search}%");
            });
        }

        if ($tenantId = $request->query('tenant_id')) {
            $query->whereHas('tenantMemberships', fn ($q) => $q->where('tenant_id', $tenantId));
        }

        $paginator = $query->orderByDesc('created_at')->paginate((int) $request->query('per_page', 20));

        return $this->paginated($paginator, UserResource::class);
    }

    public function store(StoreUserRequest $request): JsonResponse
    {
        $data = $request->validated();

        $user = User::create([
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => $data['password'] ?? null,
            'status' => User::STATUS_INVITED,
            'metadata' => $data['metadata'] ?? null,
        ]);

        $this->audit->record('user.created', $request, resourceType: 'User', resourceId: $user->id, newValue: ['name' => $user->name, 'email' => $user->email]);

        return $this->created(new UserResource($user));
    }

    public function show(User $user): JsonResponse
    {
        return $this->ok(new UserResource($user));
    }

    public function update(UpdateUserRequest $request, User $user): JsonResponse
    {
        $old = $user->only(['name', 'email']);
        $wasVerified = $user->email_verified_at !== null;
        $user->update($request->validated());

        $this->audit->record('user.updated', $request, resourceType: 'User', resourceId: $user->id, oldValue: $old, newValue: $user->only(['name', 'email']));

        // A verified address belongs to that address: the model drops the verification when the e-mail changes, and it is recorded here.
        if ($wasVerified && $user->email_verified_at === null) {
            $this->audit->record('user.email_verification_revoked', $request, resourceType: 'User', resourceId: $user->id,
                oldValue: ['email' => $old['email']], newValue: ['email' => $user->email],
                metadata: ['reason' => 'email_changed', 'reverification' => 'required']);
        }

        return $this->ok(new UserResource($user));
    }

    public function activate(Request $request, User $user): JsonResponse
    {
        // An account in the first Admin Tenant flow becomes active only when its e-mail was verified AND its holder chose a password.
        if ($user->isInManagedActivation()) {
            return $this->fail('ACTIVATION_NOT_COMPLETE', 'This account activates itself when its e-mail address is verified and its holder has chosen a password. It cannot be activated by hand.', 409, ['state' => $user->activationState()]);
        }

        if ($response = $this->guardTransition($user, User::STATUS_ACTIVE, [User::STATUS_INVITED, User::STATUS_SUSPENDED])) {
            return $response;
        }
        $password = Str::password(20);
        DB::transaction(function () use ($user, $password, $request): void {
            $user->update(['password' => $password, 'status' => User::STATUS_ACTIVE]);
            $this->audit->record('user.activated', $request, resourceType: 'User', resourceId: $user->id, newValue: ['status' => User::STATUS_ACTIVE, 'password_generated' => true]);
        });
        $this->revocation->logoutEverywhere($user, 'password_generated', $request);

        return $this->ok([...(new UserResource($user))->resolve($request), 'generated_password' => $password])
            ->header('Cache-Control', 'no-store, private')->header('Pragma', 'no-cache');
    }

    public function suspend(Request $request, User $user): JsonResponse
    {
        return $this->transitionTo($request, $user, User::STATUS_SUSPENDED, [User::STATUS_ACTIVE]);
    }

    public function disable(Request $request, User $user): JsonResponse
    {
        return $this->transitionTo($request, $user, User::STATUS_DISABLED, [User::STATUS_ACTIVE, User::STATUS_SUSPENDED, User::STATUS_INVITED]);
    }

    /** Sign the user out of every application and OptiNexus itself. */
    public function forceLogout(Request $request, User $user): JsonResponse
    {
        $notified = $this->revocation->logoutEverywhere($user, 'admin_logout', $request);

        return $this->ok(['applications_notified' => $notified]);
    }

    public function tenants(User $user): JsonResponse
    {
        return $this->ok(TenantResource::collection($user->tenants));
    }

    public function attachTenant(Request $request, User $user, Tenant $tenant): JsonResponse
    {
        $membership = $user->tenantMemberships()->where('tenant_id', $tenant->id)->first();
        if ($membership) {
            return $this->fail('DUPLICATE_RESOURCE', 'The user is already a member of this tenant.', 409);
        }

        $user->tenantMemberships()->create(['tenant_id' => $tenant->id, 'status' => TenantMembership::STATUS_ACTIVE]);

        $this->audit->record('user.tenant_assigned', $request, resourceType: 'User', resourceId: $user->id, newValue: ['tenant_id' => $tenant->id], tenantId: $tenant->id);

        return $this->created(TenantResource::collection($user->tenants()->get()));
    }

    public function detachTenant(Request $request, User $user, Tenant $tenant): JsonResponse
    {
        // Before the access rows go: they decide which applications must be told.
        $this->revocation->revokeAccess($user, 'tenant_membership_removed', $tenant, null, $request);

        $user->tenantMemberships()->where('tenant_id', $tenant->id)->delete();
        $user->applicationAccess()->where('tenant_id', $tenant->id)->delete();
        $user->userRoles()->where('tenant_id', $tenant->id)->delete();

        $this->audit->record('user.tenant_removed', $request, resourceType: 'User', resourceId: $user->id, oldValue: ['tenant_id' => $tenant->id], tenantId: $tenant->id);

        return $this->ok(null);
    }

    public function applications(User $user): JsonResponse
    {
        return $this->ok(ApplicationResource::collection(
            Application::query()->whereIn('id', $user->applicationAccess()->where('status', UserApplicationAccess::STATUS_ACTIVE)->pluck('application_id'))->get()
        ));
    }

    public function attachApplication(Request $request, User $user, Application $application): JsonResponse
    {
        $validated = $request->validate(['tenant_id' => ['required', 'uuid', 'exists:tenants,id']]);

        $hasMembership = $user->tenantMemberships()->where('tenant_id', $validated['tenant_id'])->where('status', TenantMembership::STATUS_ACTIVE)->exists();
        if (! $hasMembership) {
            return $this->fail('TENANT_ACCESS_DENIED', 'The user must have an active membership in this tenant before receiving application access.', 422);
        }

        // Operators obey the seat limit like every other path (no bypass); a refusal is a SeatException the API answers with its code.
        $this->seats->grantAccess(Tenant::query()->findOrFail($validated['tenant_id']), $application, $user);

        $this->audit->record('user.application_access_granted', $request, resourceType: 'User', resourceId: $user->id, newValue: ['application_id' => $application->id, 'tenant_id' => $validated['tenant_id']], tenantId: $validated['tenant_id'], applicationId: $application->id);

        return $this->created(new ApplicationResource($application));
    }

    public function detachApplication(Request $request, User $user, Application $application): JsonResponse
    {
        $tenantId = $request->query('tenant_id');

        $this->revocation->revokeAccess($user, 'application_access_revoked', $tenantId ? Tenant::query()->find($tenantId) : null, $application, $request);

        $query = $user->applicationAccess()->where('application_id', $application->id);
        if ($tenantId) {
            $query->where('tenant_id', $tenantId);
        }
        $query->delete();

        $this->audit->record('user.application_access_revoked', $request, resourceType: 'User', resourceId: $user->id, oldValue: ['application_id' => $application->id], applicationId: $application->id, tenantId: $tenantId);

        return $this->ok(null);
    }

    public function roles(Request $request, User $user): JsonResponse
    {
        $query = $user->userRoles()->with('role');
        if ($tenantId = $request->query('tenant_id')) {
            $query->where('tenant_id', $tenantId);
        }

        return $this->ok(RoleResource::collection($query->get()->pluck('role')->filter()->unique('id')->values()));
    }

    public function attachRole(Request $request, User $user, Role $role): JsonResponse
    {
        $validated = $request->validate(['tenant_id' => ['nullable', 'uuid', 'exists:tenants,id']]);
        $tenantId = $validated['tenant_id'] ?? null;

        if ($role->role_type !== Role::TYPE_SYSTEM && ! $tenantId) {
            $memberships = $user->tenantMemberships()->where('status', TenantMembership::STATUS_ACTIVE)
                ->whereHas('tenant', fn ($query) => $query->where('status', Tenant::STATUS_ACTIVE))->get();
            if ($memberships->isEmpty()) {
                return $this->fail('TENANT_ACCESS_DENIED', 'Add an active tenant membership first.', 422);
            }
            foreach ($memberships as $membership) {
                if (($role->tenant_id && $role->tenant_id !== $membership->tenant_id)
                    || ! $this->escalationGuard->canGrantRole($request->user(), $role, $membership->tenant_id)) {
                    return $this->fail('PRIVILEGE_ESCALATION_DENIED', 'The role cannot be assigned in every active membership.', 403);
                }
            }
            DB::transaction(function () use ($memberships, $user, $role, $request): void {
                foreach ($memberships as $membership) {
                    $assignment = $user->userRoles()->firstOrCreate(['role_id' => $role->id, 'tenant_id' => $membership->tenant_id], ['granted_by' => $request->user()->id]);
                    if ($assignment->wasRecentlyCreated) {
                        $this->audit->record('user.role_assigned', $request, resourceType: 'User', resourceId: $user->id,
                            newValue: ['role_id' => $role->id], tenantId: $membership->tenant_id);
                    }
                }
            });

            return $this->created(new RoleResource($role));
        }

        if (($role->role_type !== Role::TYPE_SYSTEM) && ! $tenantId) {
            return $this->fail('VALIDATION_ERROR', 'tenant_id is required to assign a TENANT or APPLICATION role.', 422);
        }

        if ($tenantId && ! $user->tenantMemberships()->where('tenant_id', $tenantId)->where('status', TenantMembership::STATUS_ACTIVE)->exists()) {
            return $this->fail('TENANT_ACCESS_DENIED', 'The user must have an active membership in this tenant before receiving a tenant-scoped role.', 422);
        }

        if (! $this->escalationGuard->canGrantRole($request->user(), $role, $tenantId)) {
            return $this->fail('PRIVILEGE_ESCALATION_DENIED', 'You cannot grant a role whose permissions exceed your own.', 403);
        }

        $exists = $user->userRoles()->where('role_id', $role->id)->where('tenant_id', $tenantId)->exists();
        if ($exists) {
            return $this->fail('DUPLICATE_RESOURCE', 'This role is already assigned to the user in this scope.', 409);
        }

        $user->userRoles()->create(['role_id' => $role->id, 'tenant_id' => $tenantId, 'granted_by' => $request->user()?->id]);

        $this->audit->record('user.role_assigned', $request, resourceType: 'User', resourceId: $user->id, newValue: ['role_id' => $role->id, 'tenant_id' => $tenantId], tenantId: $tenantId);

        return $this->created(new RoleResource($role));
    }

    public function detachRole(Request $request, User $user, Role $role): JsonResponse
    {
        $tenantId = $request->query('tenant_id');

        $query = $user->userRoles()->where('role_id', $role->id);
        if ($tenantId) {
            $query->where('tenant_id', $tenantId);
        }
        $query->delete();

        $this->audit->record('user.role_revoked', $request, resourceType: 'User', resourceId: $user->id, oldValue: ['role_id' => $role->id, 'tenant_id' => $tenantId], tenantId: $tenantId);

        return $this->ok(null);
    }

    public function effectivePermissions(Request $request, User $user): JsonResponse
    {
        $tenantId = $request->query('tenant_id');
        $applicationId = $request->query('application_id');

        $permissions = $this->authorization->effectivePermissions($user, $tenantId, $applicationId);
        if (! $tenantId) {
            foreach ($user->tenantMemberships()->where('status', TenantMembership::STATUS_ACTIVE)->pluck('tenant_id') as $memberTenantId) {
                $permissions = $permissions->union($this->authorization->effectivePermissions($user, $memberTenantId, $applicationId));
            }
        }

        $definitions = Permission::query()->whereIn('permission_key', $permissions->keys())
            ->with(['application', 'capability.parent.parent.parent.parent.parent'])->get()->keyBy('permission_key');

        return $this->ok([
            'user_id' => $user->id,
            'tenant_id' => $tenantId,
            'application_id' => $applicationId,
            'permissions' => $permissions->map(function ($grant, $key) use ($definitions, $request) {
                $definition = $definitions->get($key);

                return [...($definition ? (new PermissionResource($definition))->resolve($request) : ['permission_key' => $key]), 'scope' => $grant['scope']];
            })->values(),
        ]);
    }

    private function transitionTo(Request $request, User $user, string $to, array $allowedFrom): JsonResponse
    {
        if ($response = $this->guardTransition($user, $to, $allowedFrom)) {
            return $response;
        }

        $old = $user->status;
        $user->update(['status' => $to]);

        $this->audit->record('user.status_changed', $request, resourceType: 'User', resourceId: $user->id, oldValue: ['status' => $old], newValue: ['status' => $to]);

        if (in_array($to, [User::STATUS_SUSPENDED, User::STATUS_DISABLED], true)) {
            $this->revocation->revokeAccess($user, $to === User::STATUS_DISABLED ? 'user_disabled' : 'user_suspended', null, null, $request);
        }

        return $this->ok(new UserResource($user));
    }
}
