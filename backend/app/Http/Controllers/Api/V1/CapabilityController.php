<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\V1\Concerns\ApiResponses;
use App\Http\Controllers\Api\V1\Concerns\HandlesLifecycleTransitions;
use App\Http\Controllers\Controller;
use App\Http\Requests\V1\StoreCapabilityRequest;
use App\Http\Requests\V1\UpdateCapabilityRequest;
use App\Http\Resources\V1\CapabilityResource;
use App\Http\Resources\V1\PermissionResource;
use App\Models\Application;
use App\Models\Capability;
use App\Models\Permission;
use App\Services\AuditService;
use App\Services\CapabilityHierarchyService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CapabilityController extends Controller
{
    use ApiResponses, HandlesLifecycleTransitions;

    public function __construct(
        private readonly AuditService $audit,
        private readonly CapabilityHierarchyService $hierarchy,
    ) {}

    public function index(Request $request, Application $application): JsonResponse
    {
        $query = $application->capabilities();

        if ($type = $request->query('type')) {
            $query->where('type', $type);
        }

        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }

        if ($request->boolean('tree')) {
            $roots = $query->whereNull('parent_id')->with('children.children.children.children.children')->orderBy('sort_order')->get();

            return $this->ok(CapabilityResource::collection($roots));
        }

        return $this->ok(CapabilityResource::collection($query->orderBy('sort_order')->get()));
    }

    public function store(StoreCapabilityRequest $request, Application $application): JsonResponse
    {
        $data = $request->validated();

        $violation = $this->hierarchy->validate(new Capability, $data['parent_id'] ?? null, $data['type'], $application->id);
        if ($violation) {
            return $this->fail('VALIDATION_ERROR', $violation, 422);
        }

        if (Capability::query()->where('application_id', $application->id)->where('code', $data['code'])->exists()) {
            return $this->fail('DUPLICATE_RESOURCE', 'A capability with this code already exists for this application.', 409);
        }

        $capability = Capability::create([
            ...$data,
            'application_id' => $application->id,
            'status' => Capability::STATUS_ACTIVE,
        ]);

        $this->audit->record('capability.created', $request, resourceType: 'Capability', resourceId: $capability->id, newValue: $capability->toArray(), applicationId: $application->id);

        return $this->created(new CapabilityResource($capability));
    }

    public function show(Capability $capability): JsonResponse
    {
        return $this->ok(new CapabilityResource($capability->load('children')));
    }

    public function update(UpdateCapabilityRequest $request, Capability $capability): JsonResponse
    {
        $old = $capability->toArray();
        $data = $request->validated();

        if (isset($data['code']) && $data['code'] !== $capability->code) {
            if (Capability::query()->where('application_id', $capability->application_id)->where('code', $data['code'])->where('id', '!=', $capability->id)->exists()) {
                return $this->fail('DUPLICATE_RESOURCE', 'A capability with this code already exists for this application.', 409);
            }
        }

        $capability->update($data);

        $this->audit->record('capability.updated', $request, resourceType: 'Capability', resourceId: $capability->id, oldValue: $old, newValue: $capability->toArray(), applicationId: $capability->application_id);

        return $this->ok(new CapabilityResource($capability));
    }

    public function destroy(Request $request, Capability $capability): JsonResponse
    {
        if ($capability->children()->exists()) {
            return $this->fail('CONFLICT', 'Cannot delete a capability that has children. Move or delete children first.', 409);
        }

        $old = $capability->toArray();
        $capability->delete();

        $this->audit->record('capability.deleted', $request, resourceType: 'Capability', resourceId: $capability->id, oldValue: $old, applicationId: $capability->application_id);

        return $this->ok(null);
    }

    public function move(Request $request, Capability $capability): JsonResponse
    {
        $validated = $request->validate([
            'parent_id' => ['nullable', 'uuid', 'exists:capabilities,id'],
        ]);

        $violation = $this->hierarchy->validate($capability, $validated['parent_id'] ?? null, $capability->type, $capability->application_id);
        if ($violation) {
            return $this->fail('VALIDATION_ERROR', $violation, 422);
        }

        $old = ['parent_id' => $capability->parent_id];
        $capability->update(['parent_id' => $validated['parent_id'] ?? null]);

        $this->audit->record('capability.moved', $request, resourceType: 'Capability', resourceId: $capability->id, oldValue: $old, newValue: ['parent_id' => $capability->parent_id], applicationId: $capability->application_id);

        return $this->ok(new CapabilityResource($capability));
    }

    public function reorder(Request $request, Capability $capability): JsonResponse
    {
        $validated = $request->validate([
            'sort_order' => ['required', 'integer'],
        ]);

        $old = ['sort_order' => $capability->sort_order];
        $capability->update(['sort_order' => $validated['sort_order']]);

        $this->audit->record('capability.reordered', $request, resourceType: 'Capability', resourceId: $capability->id, oldValue: $old, newValue: ['sort_order' => $capability->sort_order], applicationId: $capability->application_id);

        return $this->ok(new CapabilityResource($capability));
    }

    public function activate(Request $request, Capability $capability): JsonResponse
    {
        return $this->transitionTo($request, $capability, Capability::STATUS_ACTIVE, [Capability::STATUS_DISABLED, Capability::STATUS_DEPRECATED]);
    }

    public function disable(Request $request, Capability $capability): JsonResponse
    {
        return $this->transitionTo($request, $capability, Capability::STATUS_DISABLED, [Capability::STATUS_ACTIVE, Capability::STATUS_DEPRECATED]);
    }

    public function deprecate(Request $request, Capability $capability): JsonResponse
    {
        return $this->transitionTo($request, $capability, Capability::STATUS_DEPRECATED, [Capability::STATUS_ACTIVE, Capability::STATUS_DISABLED]);
    }

    public function permissions(Capability $capability): JsonResponse
    {
        return $this->ok(PermissionResource::collection($capability->permissions));
    }

    public function attachPermission(Request $request, Capability $capability, Permission $permission): JsonResponse
    {
        if ($permission->application_id !== $capability->application_id) {
            return $this->fail('VALIDATION_ERROR', 'The permission and capability must belong to the same application.', 422);
        }

        $permission->update(['capability_id' => $capability->id]);

        $this->audit->record('capability.permission_mapped', $request, resourceType: 'Capability', resourceId: $capability->id, newValue: ['permission_id' => $permission->id], applicationId: $capability->application_id);

        return $this->created(PermissionResource::collection($capability->permissions()->get()));
    }

    public function detachPermission(Request $request, Capability $capability, Permission $permission): JsonResponse
    {
        if ($permission->capability_id === $capability->id) {
            $permission->update(['capability_id' => null]);
        }

        $this->audit->record('capability.permission_unmapped', $request, resourceType: 'Capability', resourceId: $capability->id, oldValue: ['permission_id' => $permission->id], applicationId: $capability->application_id);

        return $this->ok(null);
    }

    private function transitionTo(Request $request, Capability $capability, string $to, array $allowedFrom): JsonResponse
    {
        if ($response = $this->guardTransition($capability, $to, $allowedFrom)) {
            return $response;
        }

        $old = $capability->status;
        $capability->update(['status' => $to]);

        $this->audit->record('capability.status_changed', $request, resourceType: 'Capability', resourceId: $capability->id, oldValue: ['status' => $old], newValue: ['status' => $to], applicationId: $capability->application_id);

        return $this->ok(new CapabilityResource($capability));
    }
}
