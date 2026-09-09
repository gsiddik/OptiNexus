<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\V1\Concerns\ApiResponses;
use App\Http\Controllers\Api\V1\Concerns\HandlesLifecycleTransitions;
use App\Http\Controllers\Controller;
use App\Http\Requests\V1\PolicySimulateRequest;
use App\Http\Requests\V1\StorePolicyRequest;
use App\Http\Requests\V1\UpdatePolicyRequest;
use App\Http\Resources\V1\PolicyResource;
use App\Models\Policy;
use App\Services\AuditService;
use App\Services\Policy\InvalidPolicyConditionException;
use App\Services\Policy\PolicyConditionEvaluator;
use App\Services\Policy\PolicyEvaluationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PolicyController extends Controller
{
    use ApiResponses, HandlesLifecycleTransitions;

    public function __construct(
        private readonly AuditService $audit,
        private readonly PolicyConditionEvaluator $evaluator,
        private readonly PolicyEvaluationService $policyEvaluation,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $query = Policy::query();

        foreach (['policy_type', 'tenant_id', 'application_id', 'status', 'effect'] as $filter) {
            if ($value = $request->query($filter)) {
                $query->where($filter, $value);
            }
        }

        $paginator = $query->orderByDesc('priority')->orderByDesc('created_at')->paginate((int) $request->query('per_page', 20));

        return $this->paginated($paginator, PolicyResource::class);
    }

    public function store(StorePolicyRequest $request): JsonResponse
    {
        $data = $request->validated();

        if ($error = $this->rejectUnsafeCondition($data['condition_definition'])) {
            return $error;
        }

        $policy = Policy::create([
            ...$data,
            'status' => Policy::STATUS_DRAFT,
            'version' => 1,
            'created_by' => $request->user()?->id,
        ]);

        $this->audit->record('policy.created', $request, resourceType: 'Policy', resourceId: $policy->id, newValue: $policy->toArray(), tenantId: $policy->tenant_id, applicationId: $policy->application_id);

        return $this->created(new PolicyResource($policy));
    }

    public function show(Policy $policy): JsonResponse
    {
        return $this->ok(new PolicyResource($policy));
    }

    public function update(UpdatePolicyRequest $request, Policy $policy): JsonResponse
    {
        $data = $request->validated();

        if (isset($data['condition_definition']) && ($error = $this->rejectUnsafeCondition($data['condition_definition']))) {
            return $error;
        }

        $old = $policy->toArray();

        if (array_intersect(['effect', 'condition_definition', 'priority'], array_keys($data))) {
            $data['version'] = $policy->version + 1;
        }

        $policy->update($data);

        $this->audit->record('policy.updated', $request, resourceType: 'Policy', resourceId: $policy->id, oldValue: $old, newValue: $policy->toArray(), tenantId: $policy->tenant_id, applicationId: $policy->application_id);

        return $this->ok(new PolicyResource($policy));
    }

    public function activate(Request $request, Policy $policy): JsonResponse
    {
        if ($error = $this->rejectUnsafeCondition($policy->condition_definition)) {
            return $error;
        }

        return $this->transitionTo($request, $policy, Policy::STATUS_ACTIVE, [Policy::STATUS_DRAFT, Policy::STATUS_INACTIVE]);
    }

    public function deactivate(Request $request, Policy $policy): JsonResponse
    {
        return $this->transitionTo($request, $policy, Policy::STATUS_INACTIVE, [Policy::STATUS_ACTIVE]);
    }

    public function deprecate(Request $request, Policy $policy): JsonResponse
    {
        return $this->transitionTo($request, $policy, Policy::STATUS_DEPRECATED, [Policy::STATUS_ACTIVE, Policy::STATUS_INACTIVE, Policy::STATUS_DRAFT]);
    }

    public function clone(Request $request, Policy $policy): JsonResponse
    {
        $validated = $request->validate([
            'policy_code' => ['required', 'string', 'max:64', 'unique:policies,policy_code'],
            'name' => ['required', 'string', 'max:255'],
        ]);

        $clone = Policy::create([
            'policy_code' => $validated['policy_code'],
            'name' => $validated['name'],
            'description' => $policy->description,
            'policy_type' => $policy->policy_type,
            'tenant_id' => $policy->tenant_id,
            'application_id' => $policy->application_id,
            'priority' => $policy->priority,
            'effect' => $policy->effect,
            'status' => Policy::STATUS_DRAFT,
            'condition_definition' => $policy->condition_definition,
            'version' => 1,
            'created_by' => $request->user()?->id,
        ]);

        $this->audit->record('policy.cloned', $request, resourceType: 'Policy', resourceId: $clone->id, newValue: ['cloned_from' => $policy->id], tenantId: $clone->tenant_id, applicationId: $clone->application_id);

        return $this->created(new PolicyResource($clone));
    }

    /**
     * Read-only: evaluates candidate policies against a supplied context
     * and returns the decision without any persisted side effect.
     */
    public function simulate(PolicySimulateRequest $request): JsonResponse
    {
        $result = $this->policyEvaluation->evaluate($request->validated());

        return $this->ok([
            'decision' => $result['decision'],
            'effect' => $result['effect'],
            'reason_code' => $result['reason_code'],
            'matched_policies' => $result['matched_policies']->map(fn (Policy $p) => [
                'id' => $p->id,
                'policy_code' => $p->policy_code,
                'effect' => $p->effect,
                'priority' => $p->priority,
                'version' => $p->version,
            ])->all(),
        ]);
    }

    private function rejectUnsafeCondition(array $condition): ?JsonResponse
    {
        try {
            $this->evaluator->validate($condition);
        } catch (InvalidPolicyConditionException $e) {
            return $this->fail('POLICY_INVALID', $e->getMessage(), 422);
        }

        return null;
    }

    private function transitionTo(Request $request, Policy $policy, string $to, array $allowedFrom): JsonResponse
    {
        if ($response = $this->guardTransition($policy, $to, $allowedFrom)) {
            return $response;
        }

        $old = $policy->status;
        $policy->update(['status' => $to]);

        $this->audit->record('policy.status_changed', $request, resourceType: 'Policy', resourceId: $policy->id, oldValue: ['status' => $old], newValue: ['status' => $to], tenantId: $policy->tenant_id, applicationId: $policy->application_id);

        return $this->ok(new PolicyResource($policy));
    }
}
