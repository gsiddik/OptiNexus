<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\V1\Concerns\ApiResponses;
use App\Http\Controllers\Api\V1\Concerns\HandlesLifecycleTransitions;
use App\Http\Controllers\Controller;
use App\Http\Requests\V1\StoreUserRequest;
use App\Http\Requests\V1\UpdateUserRequest;
use App\Http\Resources\V1\ApplicationResource;
use App\Http\Resources\V1\RoleResource;
use App\Http\Resources\V1\TenantResource;
use App\Http\Resources\V1\UserResource;
use App\Models\Application;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use App\Models\UserApplicationAccess;
use App\Services\AuditService;
use App\Services\AuthorizationService;
use App\Services\Oidc\SessionRevocationService;
use App\Services\PrivilegeEscalationGuard;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class UserController extends Controller
{
    use ApiResponses, HandlesLifecycleTransitions;

    public function __construct(
        private readonly AuditService $audit,
        private readonly AuthorizationService $authorization,
        private readonly PrivilegeEscalationGuard $escalationGuard,
        private readonly SessionRevocationService $revocation,
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
        $user->update($request->validated());

        $this->audit->record('user.updated', $request, resourceType: 'User', resourceId: $user->id, oldValue: $old, newValue: $user->only(['name', 'email']));

        return $this->ok(new UserResource($user));
    }

    public function activate(Request $request, User $user): JsonResponse
    {
        return $this->transitionTo($request, $user, User::STATUS_ACTIVE, [User::STATUS_INVITED, User::STATUS_SUSPENDED]);
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

        $access = UserApplicationAccess::query()->firstOrCreate(
            ['user_id' => $user->id, 'tenant_id' => $validated['tenant_id'], 'application_id' => $application->id],
            ['status' => UserApplicationAccess::STATUS_ACTIVE],
        );
        if (! $access->wasRecentlyCreated) {
            $access->update(['status' => UserApplicationAccess::STATUS_ACTIVE]);
        }

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

        return $this->ok(RoleResource::collection($query->get()->pluck('role')->filter()));
    }

    public function attachRole(Request $request, User $user, Role $role): JsonResponse
    {
        $validated = $request->validate(['tenant_id' => ['nullable', 'uuid', 'exists:tenants,id']]);
        $tenantId = $validated['tenant_id'] ?? null;

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
        $tenantId ? $query->where('tenant_id', $tenantId) : $query->whereNull('tenant_id');
        $query->delete();

        $this->audit->record('user.role_revoked', $request, resourceType: 'User', resourceId: $user->id, oldValue: ['role_id' => $role->id, 'tenant_id' => $tenantId], tenantId: $tenantId);

        return $this->ok(null);
    }

    public function effectivePermissions(Request $request, User $user): JsonResponse
    {
        $tenantId = $request->query('tenant_id');
        $applicationId = $request->query('application_id');

        $permissions = $this->authorization->effectivePermissions($user, $tenantId, $applicationId);

        return $this->ok([
            'user_id' => $user->id,
            'tenant_id' => $tenantId,
            'application_id' => $applicationId,
            'permissions' => $permissions->map(fn ($v, $key) => ['permission_key' => $key, 'scope' => $v['scope']])->values(),
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
