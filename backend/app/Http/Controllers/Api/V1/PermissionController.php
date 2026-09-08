<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\V1\Concerns\ApiResponses;
use App\Http\Controllers\Api\V1\Concerns\HandlesLifecycleTransitions;
use App\Http\Controllers\Controller;
use App\Http\Requests\V1\StorePermissionRequest;
use App\Http\Requests\V1\UpdatePermissionRequest;
use App\Http\Resources\V1\PermissionResource;
use App\Models\Permission;
use App\Services\AuditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PermissionController extends Controller
{
    use ApiResponses, HandlesLifecycleTransitions;

    public function __construct(private readonly AuditService $audit) {}

    public function index(Request $request): JsonResponse
    {
        $query = Permission::query();

        foreach (['application_id', 'capability_id', 'status'] as $filter) {
            if ($value = $request->query($filter)) {
                $query->where($filter, $value);
            }
        }

        if ($action = $request->query('action')) {
            $query->where('permission_key', 'ilike', "%.{$action}");
        }

        if ($key = $request->query('permission_key')) {
            $query->where('permission_key', 'ilike', "%{$key}%");
        }

        $paginator = $query->orderByDesc('created_at')->paginate((int) $request->query('per_page', 20));

        return $this->paginated($paginator, PermissionResource::class);
    }

    public function store(StorePermissionRequest $request): JsonResponse
    {
        $permission = Permission::create([...$request->validated(), 'status' => Permission::STATUS_ACTIVE]);

        $this->audit->record('permission.created', $request, resourceType: 'Permission', resourceId: $permission->id, newValue: $permission->toArray(), applicationId: $permission->application_id);

        return $this->created(new PermissionResource($permission));
    }

    public function show(Permission $permission): JsonResponse
    {
        return $this->ok(new PermissionResource($permission));
    }

    public function update(UpdatePermissionRequest $request, Permission $permission): JsonResponse
    {
        $old = $permission->toArray();
        $permission->update($request->validated());

        $this->audit->record('permission.updated', $request, resourceType: 'Permission', resourceId: $permission->id, oldValue: $old, newValue: $permission->toArray(), applicationId: $permission->application_id);

        return $this->ok(new PermissionResource($permission));
    }

    public function deprecate(Request $request, Permission $permission): JsonResponse
    {
        if ($response = $this->guardTransition($permission, Permission::STATUS_DEPRECATED, [Permission::STATUS_ACTIVE])) {
            return $response;
        }

        $permission->update(['status' => Permission::STATUS_DEPRECATED]);

        $this->audit->record('permission.deprecated', $request, resourceType: 'Permission', resourceId: $permission->id, newValue: ['status' => Permission::STATUS_DEPRECATED], applicationId: $permission->application_id);

        return $this->ok(new PermissionResource($permission));
    }
}
