<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\V1\Concerns\ApiResponses;
use App\Http\Controllers\Api\V1\Concerns\HandlesLifecycleTransitions;
use App\Http\Controllers\Controller;
use App\Http\Requests\V1\StoreRoleRequest;
use App\Http\Requests\V1\UpdateRoleRequest;
use App\Http\Resources\V1\PermissionResource;
use App\Http\Resources\V1\RoleResource;
use App\Models\Permission;
use App\Models\Role;
use App\Services\AuditService;
use App\Services\PrivilegeEscalationGuard;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RoleController extends Controller
{
    use ApiResponses, HandlesLifecycleTransitions;

    public function __construct(
        private readonly AuditService $audit,
        private readonly PrivilegeEscalationGuard $escalationGuard,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $query = Role::query()->withCount('permissions');

        foreach (['tenant_id', 'application_id', 'role_type', 'status'] as $filter) {
            if ($value = $request->query($filter)) {
                $query->where($filter, $value);
            }
        }

        $paginator = $query->orderByDesc('created_at')->paginate((int) $request->query('per_page', 20));

        return $this->paginated($paginator, RoleResource::class);
    }

    public function store(StoreRoleRequest $request): JsonResponse
    {
        $data = $request->validated();

        if (($data['role_type'] === Role::TYPE_TENANT) !== (bool) ($data['tenant_id'] ?? null)) {
            return $this->fail('VALIDATION_ERROR', 'TENANT roles require a tenant_id; other role types must not set one.', 422);
        }

        if ($data['role_type'] === Role::TYPE_APPLICATION && empty($data['application_id'])) {
            return $this->fail('VALIDATION_ERROR', 'APPLICATION roles require an application_id.', 422);
        }

        $role = Role::create([...$data, 'is_system' => false, 'status' => Role::STATUS_ACTIVE]);

        $this->audit->record('role.created', $request, resourceType: 'Role', resourceId: $role->id, newValue: $role->toArray(), tenantId: $role->tenant_id, applicationId: $role->application_id);

        return $this->created(new RoleResource($role));
    }

    public function show(Role $role): JsonResponse
    {
        return $this->ok(new RoleResource($role->loadCount('permissions')));
    }

    public function update(UpdateRoleRequest $request, Role $role): JsonResponse
    {
        $old = $role->toArray();
        $role->update($request->validated());

        $this->audit->record('role.updated', $request, resourceType: 'Role', resourceId: $role->id, oldValue: $old, newValue: $role->toArray(), tenantId: $role->tenant_id);

        return $this->ok(new RoleResource($role));
    }

    public function clone(Request $request, Role $role): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'code' => ['required', 'string', 'max:100'],
        ]);

        $clone = Role::create([
            'tenant_id' => $role->tenant_id,
            'application_id' => $role->application_id,
            'name' => $validated['name'],
            'code' => $validated['code'],
            'description' => $role->description,
            'role_type' => $role->role_type,
            'is_system' => false,
            'status' => Role::STATUS_ACTIVE,
        ]);

        $clone->permissions()->sync($role->permissions()->pluck('permissions.id'));

        $this->audit->record('role.cloned', $request, resourceType: 'Role', resourceId: $clone->id, newValue: ['cloned_from' => $role->id], tenantId: $clone->tenant_id);

        return $this->created(new RoleResource($clone->loadCount('permissions')));
    }

    public function disable(Request $request, Role $role): JsonResponse
    {
        if ($role->is_system) {
            return $this->fail('UNAUTHORIZED', 'System roles cannot be disabled.', 403);
        }

        if ($response = $this->guardTransition($role, Role::STATUS_DISABLED, [Role::STATUS_ACTIVE])) {
            return $response;
        }

        $role->update(['status' => Role::STATUS_DISABLED]);

        $this->audit->record('role.disabled', $request, resourceType: 'Role', resourceId: $role->id, newValue: ['status' => Role::STATUS_DISABLED], tenantId: $role->tenant_id);

        return $this->ok(new RoleResource($role));
    }

    public function permissions(Role $role): JsonResponse
    {
        return $this->ok(PermissionResource::collection($role->permissions));
    }

    public function attachPermission(Request $request, Role $role, Permission $permission): JsonResponse
    {
        if (! $this->escalationGuard->canGrantPermission($request->user(), $permission, $role->tenant_id)) {
            return $this->fail('PRIVILEGE_ESCALATION_DENIED', 'You cannot grant a permission you do not hold yourself.', 403);
        }

        if ($role->permissions()->where('permissions.id', $permission->id)->exists()) {
            return $this->fail('DUPLICATE_RESOURCE', 'This permission is already assigned to the role.', 409);
        }

        $role->permissions()->attach($permission->id, [
            'id' => (string) \Illuminate\Support\Str::uuid(),
            'granted_by' => $request->user()?->id,
        ]);

        $this->audit->record('role.permission_granted', $request, resourceType: 'Role', resourceId: $role->id, newValue: ['permission_id' => $permission->id], tenantId: $role->tenant_id);

        return $this->created(PermissionResource::collection($role->permissions()->get()));
    }

    public function detachPermission(Request $request, Role $role, Permission $permission): JsonResponse
    {
        $role->permissions()->detach($permission->id);

        $this->audit->record('role.permission_revoked', $request, resourceType: 'Role', resourceId: $role->id, oldValue: ['permission_id' => $permission->id], tenantId: $role->tenant_id);

        return $this->ok(null);
    }
}
