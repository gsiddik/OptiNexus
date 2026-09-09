<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\V1\Concerns\ApiResponses;
use App\Http\Controllers\Api\V1\Concerns\HandlesLifecycleTransitions;
use App\Http\Controllers\Controller;
use App\Http\Requests\V1\StoreFeatureFlagOverrideRequest;
use App\Http\Requests\V1\StoreFeatureFlagRequest;
use App\Http\Requests\V1\UpdateFeatureFlagRequest;
use App\Http\Resources\V1\FeatureFlagOverrideResource;
use App\Http\Resources\V1\FeatureFlagResource;
use App\Models\FeatureFlag;
use App\Models\FeatureFlagOverride;
use App\Services\AuditService;
use App\Services\FeatureFlag\FeatureFlagEvaluationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FeatureFlagController extends Controller
{
    use ApiResponses, HandlesLifecycleTransitions;

    public function __construct(
        private readonly AuditService $audit,
        private readonly FeatureFlagEvaluationService $evaluation,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $query = FeatureFlag::query();

        foreach (['application_id', 'status', 'flag_type'] as $filter) {
            if ($value = $request->query($filter)) {
                $query->where($filter, $value);
            }
        }

        $paginator = $query->orderByDesc('created_at')->paginate((int) $request->query('per_page', 20));

        return $this->paginated($paginator, FeatureFlagResource::class);
    }

    public function store(StoreFeatureFlagRequest $request): JsonResponse
    {
        $flag = FeatureFlag::create([
            ...$request->validated(),
            'status' => FeatureFlag::STATUS_DRAFT,
            'created_by' => $request->user()?->id,
        ]);

        $this->audit->record('feature_flag.created', $request, resourceType: 'FeatureFlag', resourceId: $flag->id, newValue: $flag->toArray(), applicationId: $flag->application_id);

        return $this->created(new FeatureFlagResource($flag));
    }

    public function show(FeatureFlag $featureFlag): JsonResponse
    {
        return $this->ok(new FeatureFlagResource($featureFlag->load('overrides')));
    }

    public function update(UpdateFeatureFlagRequest $request, FeatureFlag $featureFlag): JsonResponse
    {
        $old = $featureFlag->toArray();
        $featureFlag->update($request->validated());

        $this->audit->record('feature_flag.updated', $request, resourceType: 'FeatureFlag', resourceId: $featureFlag->id, oldValue: $old, newValue: $featureFlag->toArray(), applicationId: $featureFlag->application_id);

        return $this->ok(new FeatureFlagResource($featureFlag));
    }

    public function activate(Request $request, FeatureFlag $featureFlag): JsonResponse
    {
        return $this->transitionTo($request, $featureFlag, FeatureFlag::STATUS_ACTIVE, [FeatureFlag::STATUS_DRAFT, FeatureFlag::STATUS_INACTIVE]);
    }

    public function deactivate(Request $request, FeatureFlag $featureFlag): JsonResponse
    {
        return $this->transitionTo($request, $featureFlag, FeatureFlag::STATUS_INACTIVE, [FeatureFlag::STATUS_ACTIVE]);
    }

    public function storeOverride(StoreFeatureFlagOverrideRequest $request, FeatureFlag $featureFlag): JsonResponse
    {
        $data = $request->validated();

        if ($data['scope_type'] === FeatureFlag::SCOPE_GLOBAL) {
            $data['scope_id'] = null;
        }

        $override = FeatureFlagOverride::updateOrCreate(
            ['feature_flag_id' => $featureFlag->id, 'scope_type' => $data['scope_type'], 'scope_id' => $data['scope_id'] ?? null],
            [
                'value' => $data['value'],
                'effective_from' => $data['effective_from'] ?? null,
                'effective_until' => $data['effective_until'] ?? null,
                'created_by' => $request->user()?->id,
            ],
        );

        $this->audit->record('feature_flag.override_changed', $request, resourceType: 'FeatureFlagOverride', resourceId: $override->id, newValue: $override->toArray(), applicationId: $featureFlag->application_id);

        return $this->created(new FeatureFlagOverrideResource($override));
    }

    public function destroyOverride(Request $request, FeatureFlag $featureFlag, FeatureFlagOverride $override): JsonResponse
    {
        if ($override->feature_flag_id !== $featureFlag->id) {
            return $this->fail('NOT_FOUND', 'This override does not belong to the given feature flag.', 404);
        }

        $override->delete();

        $this->audit->record('feature_flag.override_changed', $request, resourceType: 'FeatureFlagOverride', resourceId: $override->id, oldValue: $override->toArray(), applicationId: $featureFlag->application_id);

        return $this->ok(['deleted' => true]);
    }

    public function evaluate(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'flag_key' => ['required', 'string'],
            'tenant_id' => ['nullable', 'uuid'],
            'user_id' => ['nullable', 'uuid'],
        ]);

        $flag = FeatureFlag::where('flag_key', $validated['flag_key'])->first();

        if (! $flag) {
            return $this->fail('FEATURE_FLAG_NOT_FOUND', 'No feature flag exists for this flag_key.', 404);
        }

        $result = $this->evaluation->evaluate($flag, $validated['tenant_id'] ?? null, $validated['user_id'] ?? null);

        return $this->ok($result);
    }

    private function transitionTo(Request $request, FeatureFlag $featureFlag, string $to, array $allowedFrom): JsonResponse
    {
        if ($response = $this->guardTransition($featureFlag, $to, $allowedFrom)) {
            return $response;
        }

        $old = $featureFlag->status;
        $featureFlag->update(['status' => $to]);

        $this->audit->record('feature_flag.status_changed', $request, resourceType: 'FeatureFlag', resourceId: $featureFlag->id, oldValue: ['status' => $old], newValue: ['status' => $to], applicationId: $featureFlag->application_id);

        return $this->ok(new FeatureFlagResource($featureFlag));
    }
}
