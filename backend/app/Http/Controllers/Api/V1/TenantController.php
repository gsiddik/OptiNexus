<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\V1\Concerns\ApiResponses;
use App\Http\Controllers\Api\V1\Concerns\HandlesLifecycleTransitions;
use App\Http\Controllers\Controller;
use App\Http\Requests\V1\StoreTenantRequest;
use App\Http\Requests\V1\UpdateTenantRequest;
use App\Http\Resources\V1\ApplicationResource;
use App\Http\Resources\V1\TenantResource;
use App\Http\Resources\V1\UserResource;
use App\Models\Application;
use App\Models\Entitlement;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use App\Services\AuditService;
use App\Services\Commercial\EntitlementService;
use App\Services\Oidc\SessionRevocationService;
use App\Services\Seats\SeatService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class TenantController extends Controller
{
    use ApiResponses, HandlesLifecycleTransitions;

    public function __construct(
        private readonly AuditService $audit,
        private readonly SessionRevocationService $revocation,
        private readonly SeatService $seats,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $query = Tenant::query();

        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }

        if ($customerId = $request->query('customer_id')) {
            $query->where('customer_id', $customerId);
        }

        if ($search = $request->query('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'ilike', "%{$search}%")->orWhere('tenant_code', 'ilike', "%{$search}%");
            });
        }

        $paginator = $query->orderByDesc('created_at')->paginate((int) $request->query('per_page', 15));

        return $this->paginated($paginator, TenantResource::class);
    }

    public function store(StoreTenantRequest $request): JsonResponse
    {
        $tenant = Tenant::create([...$request->validated(), 'tenant_code' => 'TEN-'.Str::ulid(), 'status' => Tenant::STATUS_DRAFT]);

        $this->audit->record('tenant.created', $request, resourceType: 'Tenant', resourceId: $tenant->id, newValue: $tenant->toArray(), tenantId: $tenant->id, customerId: $tenant->customer_id);

        return $this->created(new TenantResource($tenant));
    }

    public function show(Tenant $tenant): JsonResponse
    {
        return $this->ok(new TenantResource($tenant));
    }

    public function update(UpdateTenantRequest $request, Tenant $tenant): JsonResponse
    {
        $old = $tenant->toArray();
        $tenant->update($request->validated());

        $this->audit->record('tenant.updated', $request, resourceType: 'Tenant', resourceId: $tenant->id, oldValue: $old, newValue: $tenant->toArray(), tenantId: $tenant->id, customerId: $tenant->customer_id);

        return $this->ok(new TenantResource($tenant));
    }

    public function provision(Request $request, Tenant $tenant): JsonResponse
    {
        return DB::transaction(function () use ($request, $tenant): JsonResponse {
            $tenant = Tenant::query()->whereKey($tenant->id)->lockForUpdate()->firstOrFail();
            if ($response = $this->guardTransition($tenant, Tenant::STATUS_PROVISIONING, [Tenant::STATUS_DRAFT])) {
                return $response;
            }
            if ($reason = $tenant->provisioningBlocker()) {
                return $this->fail('TENANT_PROVISION_PREREQUISITES', $reason, 422);
            }

            return $this->transitionTo($request, $tenant, Tenant::STATUS_PROVISIONING, [Tenant::STATUS_DRAFT]);
        });
    }

    public function activate(Request $request, Tenant $tenant): JsonResponse
    {
        return $this->transitionTo($request, $tenant, Tenant::STATUS_ACTIVE, [Tenant::STATUS_PROVISIONING]);
    }

    public function suspend(Request $request, Tenant $tenant): JsonResponse
    {
        return $this->transitionTo($request, $tenant, Tenant::STATUS_SUSPENDED, [Tenant::STATUS_ACTIVE]);
    }

    public function reactivate(Request $request, Tenant $tenant): JsonResponse
    {
        return $this->transitionTo($request, $tenant, Tenant::STATUS_ACTIVE, [Tenant::STATUS_SUSPENDED]);
    }

    public function terminate(Request $request, Tenant $tenant): JsonResponse
    {
        return $this->transitionTo($request, $tenant, Tenant::STATUS_TERMINATED, [Tenant::STATUS_ACTIVE, Tenant::STATUS_SUSPENDED]);
    }

    public function archive(Request $request, Tenant $tenant): JsonResponse
    {
        return $this->transitionTo($request, $tenant, Tenant::STATUS_ARCHIVED, [Tenant::STATUS_TERMINATED]);
    }

    public function applications(Tenant $tenant): JsonResponse
    {
        return $this->ok(ApplicationResource::collection($tenant->applications));
    }

    /** Seats of every application the tenant has, as OptiNexus counts them (used, reserved, limit, available, over-limit). Read-only. */
    public function updateSeats(Request $request, Tenant $tenant, Application $application, EntitlementService $entitlements): JsonResponse
    {
        $data = $request->validate([
            'limit' => ['required', 'integer', 'min:0', 'max:2000000000'],
            'reason' => ['required', 'string', 'min:10', 'max:500'],
        ]);
        if (! $tenant->applications()->where('applications.id', $application->id)->wherePivot('status', 'ACTIVE')->exists() || ! $this->seats->declares($application)) {
            return $this->fail('SEAT_APPLICATION_INVALID', 'Select an assigned application that declares a user limit.', 422);
        }

        DB::transaction(function () use ($tenant, $application, $data, $request, $entitlements): void {
            $this->seats->lock($tenant->id, [$application->id]);
            $key = $this->seats->limitKey();
            // Preserve the history of the previous application override, including scheduled overrides.
            Entitlement::query()->where('tenant_id', $tenant->id)->where('application_id', $application->id)
                ->where('entitlement_type', 'LIMIT')->where('entitlement_key', $key)->where('source_type', 'MANUAL_OVERRIDE')
                ->whereIn('status', ['ACTIVE', 'SUSPENDED'])->update(['status' => 'REVOKED', 'effective_until' => now()]);
            $entitlement = $entitlements->manualOverride($tenant, [
                'application_id' => $application->id, 'entitlement_type' => 'LIMIT', 'entitlement_key' => $key,
                'value' => $data['limit'], 'reason' => $data['reason'],
            ], $request->user());
            $this->audit->record('tenant.seats_updated', $request, tenantId: $tenant->id, applicationId: $application->id,
                resourceType: 'Entitlement', resourceId: $entitlement->id, newValue: $entitlement->toArray());
        });

        return $this->ok($this->seats->forTenant($tenant)->map->toArray());
    }

    public function seats(Tenant $tenant): JsonResponse
    {
        return $this->ok($this->seats->forTenant($tenant)->map(fn ($status): array => $status->toArray())->all());
    }

    public function attachApplication(Request $request, Tenant $tenant, Application $application): JsonResponse
    {
        return $this->fail('SUBSCRIPTION_MANAGED_APPLICATION', 'Applications are assigned by the tenant subscription. Update its plan or add-ons instead.', 409);
    }

    public function detachApplication(Request $request, Tenant $tenant, Application $application): JsonResponse
    {
        return $this->fail('SUBSCRIPTION_MANAGED_APPLICATION', 'Applications are assigned by the tenant subscription. Update its plan or add-ons instead.', 409);
    }

    public function admins(Tenant $tenant): JsonResponse
    {
        return $this->ok(UserResource::collection($tenant->admins));
    }

    public function addAdmin(Request $request, Tenant $tenant, User $user): JsonResponse
    {
        $membership = $tenant->memberships()->firstOrCreate(
            ['user_id' => $user->id],
            ['status' => TenantMembership::STATUS_ACTIVE, 'is_tenant_admin' => true],
        );

        if (! $membership->wasRecentlyCreated) {
            $membership->update(['is_tenant_admin' => true, 'status' => TenantMembership::STATUS_ACTIVE]);
        }

        $this->audit->record('tenant.admin_assigned', $request, resourceType: 'Tenant', resourceId: $tenant->id, newValue: ['user_id' => $user->id], tenantId: $tenant->id);

        return $this->created(UserResource::collection($tenant->admins));
    }

    public function removeAdmin(Request $request, Tenant $tenant, User $user): JsonResponse
    {
        $membership = $tenant->memberships()->where('user_id', $user->id)->first();
        $membership?->update(['is_tenant_admin' => false]);

        $this->audit->record('tenant.admin_removed', $request, resourceType: 'Tenant', resourceId: $tenant->id, oldValue: ['user_id' => $user->id], tenantId: $tenant->id);

        return $this->ok(UserResource::collection($tenant->admins));
    }

    private function transitionTo(Request $request, Tenant $tenant, string $to, array $allowedFrom): JsonResponse
    {
        if ($response = $this->guardTransition($tenant, $to, $allowedFrom)) {
            return $response;
        }

        $old = $tenant->status;
        $tenant->update(['status' => $to]);

        if (in_array($to, [Tenant::STATUS_SUSPENDED, Tenant::STATUS_TERMINATED, Tenant::STATUS_ARCHIVED], true)) {
            $this->revocation->revokeTenant($tenant, 'tenant_'.strtolower($to), null, $request);
        }

        $this->audit->record(
            'tenant.status_changed',
            $request,
            resourceType: 'Tenant',
            resourceId: $tenant->id,
            oldValue: ['status' => $old],
            newValue: ['status' => $to],
            tenantId: $tenant->id,
            customerId: $tenant->customer_id,
        );

        return $this->ok(new TenantResource($tenant));
    }
}
