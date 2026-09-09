<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\V1\Concerns\ApiResponses;
use App\Http\Controllers\Api\V1\Concerns\HandlesLifecycleTransitions;
use App\Http\Controllers\Controller;
use App\Http\Requests\V1\EntitlementCheckRequest;
use App\Http\Requests\V1\StoreEntitlementOverrideRequest;
use App\Http\Resources\V1\EntitlementResource;
use App\Http\Resources\V1\SubscriptionResource;
use App\Models\Entitlement;
use App\Models\Tenant;
use App\Services\AuditService;
use App\Services\Commercial\EntitlementService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EntitlementController extends Controller
{
    use ApiResponses, HandlesLifecycleTransitions;

    public function __construct(
        private readonly EntitlementService $entitlements,
        private readonly AuditService $audit,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $query = Entitlement::query();

        foreach (['tenant_id', 'subscription_id', 'entitlement_type', 'status', 'source_type'] as $filter) {
            if ($value = $request->query($filter)) {
                $query->where($filter, $value);
            }
        }

        $paginator = $query->orderByDesc('created_at')->paginate((int) $request->query('per_page', 25));

        return $this->paginated($paginator, EntitlementResource::class);
    }

    public function forTenant(Request $request, Tenant $tenant): JsonResponse
    {
        $query = $tenant->entitlements();

        foreach (['status', 'entitlement_type', 'source_type'] as $filter) {
            if ($value = $request->query($filter)) {
                $query->where($filter, $value);
            }
        }

        $paginator = $query->orderByDesc('created_at')->paginate((int) $request->query('per_page', 25));

        return $this->paginated($paginator, EntitlementResource::class);
    }

    public function effectiveForTenant(Request $request, Tenant $tenant): JsonResponse
    {
        $applicationCode = $request->query('application_code');

        return $this->ok([
            'tenant_id' => $tenant->id,
            'entitlements' => $this->entitlements->effectiveEntitlements($tenant, $applicationCode)->values(),
        ]);
    }

    public function commercialContext(Tenant $tenant): JsonResponse
    {
        $subscriptions = $tenant->subscriptions()->with(['plan', 'product'])->whereIn('status', \App\Models\Subscription::ACTIVE_LIKE_STATUSES)->get();
        $effective = $this->entitlements->effectiveEntitlements($tenant);

        return $this->ok([
            'tenant_id' => $tenant->id,
            'tenant_status' => $tenant->status,
            'subscriptions' => SubscriptionResource::collection($subscriptions),
            'applications_enabled' => $effective->where('entitlement_type', Entitlement::TYPE_APPLICATION)->where('value', true)->pluck('entitlement_key')->values(),
            'effective_entitlements' => $effective->values(),
        ]);
    }

    /**
     * Machine-to-machine: an integrated application asks whether a tenant
     * is entitled to a given application/feature/limit.
     */
    public function check(EntitlementCheckRequest $request): JsonResponse
    {
        $tenant = Tenant::findOrFail($request->input('tenant_id'));

        $result = $this->entitlements->check($tenant, $request->input('application_code'), $request->input('entitlement_key'));

        return $this->ok($result);
    }

    public function suspend(Request $request, Entitlement $entitlement): JsonResponse
    {
        if ($response = $this->guardTransition($entitlement, Entitlement::STATUS_SUSPENDED, [Entitlement::STATUS_ACTIVE])) {
            return $response;
        }

        $entitlement->update(['status' => Entitlement::STATUS_SUSPENDED]);

        $this->audit->record('entitlement.suspended', $request, resourceType: 'Entitlement', resourceId: $entitlement->id, tenantId: $entitlement->tenant_id);

        return $this->ok(new EntitlementResource($entitlement));
    }

    public function restore(Request $request, Entitlement $entitlement): JsonResponse
    {
        if ($response = $this->guardTransition($entitlement, Entitlement::STATUS_ACTIVE, [Entitlement::STATUS_SUSPENDED])) {
            return $response;
        }

        $entitlement->update(['status' => Entitlement::STATUS_ACTIVE]);

        $this->audit->record('entitlement.restored', $request, resourceType: 'Entitlement', resourceId: $entitlement->id, tenantId: $entitlement->tenant_id);

        return $this->ok(new EntitlementResource($entitlement));
    }

    public function storeOverride(StoreEntitlementOverrideRequest $request, Tenant $tenant): JsonResponse
    {
        $entitlement = $this->entitlements->manualOverride($tenant, $request->validated(), $request->user());

        $this->audit->record('entitlement.overridden', $request, resourceType: 'Entitlement', resourceId: $entitlement->id, newValue: $entitlement->toArray(), tenantId: $tenant->id);

        return $this->created(new EntitlementResource($entitlement));
    }
}
