<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\V1\Concerns\ApiResponses;
use App\Http\Controllers\Api\V1\Concerns\HandlesLifecycleTransitions;
use App\Http\Controllers\Controller;
use App\Http\Requests\V1\StoreAddonRequest;
use App\Http\Requests\V1\UpdateAddonRequest;
use App\Http\Resources\V1\AddonLimitResource;
use App\Http\Resources\V1\AddonResource;
use App\Http\Resources\V1\CapabilityResource;
use App\Models\Addon;
use App\Models\AddonLimit;
use App\Models\Capability;
use App\Services\AuditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class AddonController extends Controller
{
    use ApiResponses, HandlesLifecycleTransitions;

    public function __construct(private readonly AuditService $audit) {}

    public function index(Request $request): JsonResponse
    {
        $query = Addon::query();

        foreach (['product_id', 'status'] as $filter) {
            if ($value = $request->query($filter)) {
                $query->where($filter, $value);
            }
        }

        $paginator = $query->orderByDesc('created_at')->paginate((int) $request->query('per_page', 15));

        return $this->paginated($paginator, AddonResource::class);
    }

    public function store(StoreAddonRequest $request): JsonResponse
    {
        $addon = Addon::create([...$request->validated(), 'status' => Addon::STATUS_DRAFT]);

        $this->audit->record('addon.created', $request, resourceType: 'Addon', resourceId: $addon->id, newValue: $addon->toArray());

        return $this->created(new AddonResource($addon));
    }

    public function show(Addon $addon): JsonResponse
    {
        return $this->ok(new AddonResource($addon));
    }

    public function update(UpdateAddonRequest $request, Addon $addon): JsonResponse
    {
        $old = $addon->toArray();
        $addon->update($request->validated());

        $this->audit->record('addon.updated', $request, resourceType: 'Addon', resourceId: $addon->id, oldValue: $old, newValue: $addon->toArray());

        return $this->ok(new AddonResource($addon));
    }

    public function activate(Request $request, Addon $addon): JsonResponse
    {
        return $this->transitionTo($request, $addon, Addon::STATUS_ACTIVE, [Addon::STATUS_DRAFT, Addon::STATUS_INACTIVE]);
    }

    public function deactivate(Request $request, Addon $addon): JsonResponse
    {
        return $this->transitionTo($request, $addon, Addon::STATUS_INACTIVE, [Addon::STATUS_ACTIVE]);
    }

    public function retire(Request $request, Addon $addon): JsonResponse
    {
        return $this->transitionTo($request, $addon, Addon::STATUS_RETIRED, [Addon::STATUS_ACTIVE, Addon::STATUS_INACTIVE]);
    }

    public function capabilities(Addon $addon): JsonResponse
    {
        return $this->ok(CapabilityResource::collection($addon->capabilities));
    }

    public function attachCapability(Request $request, Addon $addon, Capability $capability): JsonResponse
    {
        if ($addon->capabilities()->where('capabilities.id', $capability->id)->exists()) {
            return $this->fail('DUPLICATE_RESOURCE', 'This capability is already included in the add-on.', 409);
        }

        $addon->capabilities()->attach($capability->id, ['id' => (string) Str::uuid()]);

        $this->audit->record('addon.capability_assigned', $request, resourceType: 'Addon', resourceId: $addon->id, newValue: ['capability_id' => $capability->id]);

        return $this->created(CapabilityResource::collection($addon->capabilities()->get()));
    }

    public function detachCapability(Request $request, Addon $addon, Capability $capability): JsonResponse
    {
        $addon->capabilities()->detach($capability->id);

        $this->audit->record('addon.capability_revoked', $request, resourceType: 'Addon', resourceId: $addon->id, oldValue: ['capability_id' => $capability->id]);

        return $this->ok(null);
    }

    public function limits(Addon $addon): JsonResponse
    {
        return $this->ok(AddonLimitResource::collection($addon->limits));
    }

    public function configureLimit(Request $request, Addon $addon): JsonResponse
    {
        $validated = $request->validate([
            'limit_key' => ['required', 'string', 'max:100'],
            'limit_delta' => ['required', 'numeric'],
            'unit' => ['nullable', 'string', 'max:50'],
        ]);

        $limit = AddonLimit::query()->updateOrCreate(
            ['addon_id' => $addon->id, 'limit_key' => $validated['limit_key']],
            ['limit_delta' => $validated['limit_delta'], 'unit' => $validated['unit'] ?? null],
        );

        $this->audit->record('addon.limit_configured', $request, resourceType: 'Addon', resourceId: $addon->id, newValue: $limit->toArray());

        return $this->ok(new AddonLimitResource($limit));
    }

    private function transitionTo(Request $request, Addon $addon, string $to, array $allowedFrom): JsonResponse
    {
        if ($response = $this->guardTransition($addon, $to, $allowedFrom)) {
            return $response;
        }

        $old = $addon->status;
        $addon->update(['status' => $to]);

        $this->audit->record('addon.status_changed', $request, resourceType: 'Addon', resourceId: $addon->id, oldValue: ['status' => $old], newValue: ['status' => $to]);

        return $this->ok(new AddonResource($addon));
    }
}
