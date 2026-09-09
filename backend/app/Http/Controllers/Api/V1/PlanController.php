<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\V1\Concerns\ApiResponses;
use App\Http\Controllers\Api\V1\Concerns\HandlesLifecycleTransitions;
use App\Http\Controllers\Controller;
use App\Http\Requests\V1\StorePlanRequest;
use App\Http\Requests\V1\UpdatePlanRequest;
use App\Http\Resources\V1\CapabilityResource;
use App\Http\Resources\V1\PlanLimitResource;
use App\Http\Resources\V1\PlanResource;
use App\Models\Capability;
use App\Models\Plan;
use App\Models\PlanLimit;
use App\Services\AuditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class PlanController extends Controller
{
    use ApiResponses, HandlesLifecycleTransitions;

    public function __construct(private readonly AuditService $audit) {}

    public function index(Request $request): JsonResponse
    {
        $query = Plan::query()->withCount('capabilities');

        foreach (['product_id', 'status', 'billing_interval'] as $filter) {
            if ($value = $request->query($filter)) {
                $query->where($filter, $value);
            }
        }

        $paginator = $query->orderByDesc('created_at')->paginate((int) $request->query('per_page', 15));

        return $this->paginated($paginator, PlanResource::class);
    }

    public function store(StorePlanRequest $request): JsonResponse
    {
        $plan = Plan::create([...$request->validated(), 'status' => Plan::STATUS_DRAFT]);

        $this->audit->record('plan.created', $request, resourceType: 'Plan', resourceId: $plan->id, newValue: $plan->toArray());

        return $this->created(new PlanResource($plan));
    }

    public function show(Plan $plan): JsonResponse
    {
        return $this->ok(new PlanResource($plan->loadCount('capabilities')));
    }

    public function update(UpdatePlanRequest $request, Plan $plan): JsonResponse
    {
        $old = $plan->toArray();
        $plan->update($request->validated());

        $this->audit->record('plan.updated', $request, resourceType: 'Plan', resourceId: $plan->id, oldValue: $old, newValue: $plan->toArray());

        return $this->ok(new PlanResource($plan));
    }

    public function clone(Request $request, Plan $plan): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'plan_code' => ['required', 'string', 'max:64', 'unique:plans,plan_code'],
        ]);

        $clone = Plan::create([
            'product_id' => $plan->product_id,
            'name' => $validated['name'],
            'plan_code' => $validated['plan_code'],
            'description' => $plan->description,
            'billing_interval' => $plan->billing_interval,
            'trial_days' => $plan->trial_days,
            'currency' => $plan->currency,
            'status' => Plan::STATUS_DRAFT,
            'metadata' => $plan->metadata,
        ]);

        $clone->capabilities()->sync($plan->capabilities()->pluck('capabilities.id'));

        foreach ($plan->limits as $limit) {
            $clone->limits()->create([
                'limit_key' => $limit->limit_key,
                'limit_value' => $limit->limit_value,
                'is_unlimited' => $limit->is_unlimited,
                'unit' => $limit->unit,
            ]);
        }

        $this->audit->record('plan.cloned', $request, resourceType: 'Plan', resourceId: $clone->id, newValue: ['cloned_from' => $plan->id]);

        return $this->created(new PlanResource($clone->loadCount('capabilities')));
    }

    public function activate(Request $request, Plan $plan): JsonResponse
    {
        return $this->transitionTo($request, $plan, Plan::STATUS_ACTIVE, [Plan::STATUS_DRAFT, Plan::STATUS_INACTIVE]);
    }

    public function deactivate(Request $request, Plan $plan): JsonResponse
    {
        return $this->transitionTo($request, $plan, Plan::STATUS_INACTIVE, [Plan::STATUS_ACTIVE]);
    }

    public function retire(Request $request, Plan $plan): JsonResponse
    {
        return $this->transitionTo($request, $plan, Plan::STATUS_RETIRED, [Plan::STATUS_ACTIVE, Plan::STATUS_INACTIVE]);
    }

    public function capabilities(Plan $plan): JsonResponse
    {
        return $this->ok(CapabilityResource::collection($plan->capabilities));
    }

    public function attachCapability(Request $request, Plan $plan, Capability $capability): JsonResponse
    {
        if ($plan->capabilities()->where('capabilities.id', $capability->id)->exists()) {
            return $this->fail('DUPLICATE_RESOURCE', 'This capability is already included in the plan.', 409);
        }

        $plan->capabilities()->attach($capability->id, ['id' => (string) Str::uuid()]);

        $this->audit->record('plan.capability_assigned', $request, resourceType: 'Plan', resourceId: $plan->id, newValue: ['capability_id' => $capability->id]);

        return $this->created(CapabilityResource::collection($plan->capabilities()->get()));
    }

    public function detachCapability(Request $request, Plan $plan, Capability $capability): JsonResponse
    {
        $plan->capabilities()->detach($capability->id);

        $this->audit->record('plan.capability_revoked', $request, resourceType: 'Plan', resourceId: $plan->id, oldValue: ['capability_id' => $capability->id]);

        return $this->ok(null);
    }

    public function limits(Plan $plan): JsonResponse
    {
        return $this->ok(PlanLimitResource::collection($plan->limits));
    }

    public function configureLimit(Request $request, Plan $plan): JsonResponse
    {
        $validated = $request->validate([
            'limit_key' => ['required', 'string', 'max:100'],
            'limit_value' => ['nullable', 'numeric', 'min:0', 'required_if:is_unlimited,false'],
            'is_unlimited' => ['sometimes', 'boolean'],
            'unit' => ['nullable', 'string', 'max:50'],
        ]);

        $limit = PlanLimit::query()->updateOrCreate(
            ['plan_id' => $plan->id, 'limit_key' => $validated['limit_key']],
            [
                'limit_value' => $validated['is_unlimited'] ?? false ? null : $validated['limit_value'],
                'is_unlimited' => $validated['is_unlimited'] ?? false,
                'unit' => $validated['unit'] ?? null,
            ],
        );

        $this->audit->record('plan.limit_configured', $request, resourceType: 'Plan', resourceId: $plan->id, newValue: $limit->toArray());

        return $this->ok(new PlanLimitResource($limit));
    }

    public function removeLimit(Request $request, Plan $plan, PlanLimit $limit): JsonResponse
    {
        if ($limit->plan_id !== $plan->id) {
            return $this->fail('RESOURCE_NOT_FOUND', 'This limit does not belong to the given plan.', 404);
        }

        $old = $limit->toArray();
        $limit->delete();

        $this->audit->record('plan.limit_removed', $request, resourceType: 'Plan', resourceId: $plan->id, oldValue: $old);

        return $this->ok(null);
    }

    private function transitionTo(Request $request, Plan $plan, string $to, array $allowedFrom): JsonResponse
    {
        if ($response = $this->guardTransition($plan, $to, $allowedFrom)) {
            return $response;
        }

        $old = $plan->status;
        $plan->update(['status' => $to]);

        $this->audit->record('plan.status_changed', $request, resourceType: 'Plan', resourceId: $plan->id, oldValue: ['status' => $old], newValue: ['status' => $to]);

        return $this->ok(new PlanResource($plan));
    }
}
