<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\V1\Concerns\ApiResponses;
use App\Http\Controllers\Controller;
use App\Http\Requests\V1\StoreAuditEventRequest;
use App\Http\Resources\V1\AuditLogResource;
use App\Models\AuditLog;
use App\Models\ServiceAccount;
use App\Models\Tenant;
use App\Services\AuditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AuditLogController extends Controller
{
    use ApiResponses;

    public function __construct(private readonly AuditService $audit) {}

    public function index(Request $request): JsonResponse
    {
        $query = AuditLog::query();

        foreach (['tenant_id', 'customer_id', 'application_id', 'action', 'resource_type', 'actor_user_id'] as $filter) {
            if ($value = $request->query($filter)) {
                $query->where($filter, $value);
            }
        }

        if ($from = $request->query('from')) {
            $query->where('created_at', '>=', $from);
        }
        if ($to = $request->query('to')) {
            $query->where('created_at', '<=', $to);
        }

        $paginator = $query->orderByDesc('created_at')->paginate((int) $request->query('per_page', 25));

        return $this->paginated($paginator, AuditLogResource::class);
    }

    public function show(AuditLog $auditLog): JsonResponse
    {
        return $this->ok(new AuditLogResource($auditLog));
    }

    /**
     * External event ingestion for trusted, authenticated applications
     * (client-credentials M2M only, see routes/v1/audit.php). The actor
     * identity (application/service account) is derived from the verified
     * OAuth client, never from the request body, which prevents spoofing.
     */
    public function storeEvent(StoreAuditEventRequest $request): JsonResponse
    {
        /** @var ServiceAccount $serviceAccount */
        $serviceAccount = $request->attributes->get('service_account');
        $data = $request->validated();

        if (! empty($data['tenant_id']) && $serviceAccount->application_id) {
            $tenant = Tenant::query()->find($data['tenant_id']);
            $assigned = $tenant?->applications()->where('applications.id', $serviceAccount->application_id)->wherePivot('status', 'ACTIVE')->exists();
            if (! $assigned) {
                return $this->fail('APPLICATION_ACCESS_DENIED', 'This application is not assigned to the given tenant.', 403);
            }
        }

        $log = $this->audit->record(
            action: $data['action'],
            request: $request,
            actorIdentity: $data['actor_identity'] ?? $serviceAccount->name,
            tenantId: $data['tenant_id'] ?? null,
            applicationId: $serviceAccount->application_id,
            resourceType: $data['resource_type'] ?? null,
            resourceId: $data['resource_id'] ?? null,
            oldValue: $data['old_value'] ?? null,
            newValue: $data['new_value'] ?? null,
            source: $serviceAccount->application?->application_code ?? 'external',
            metadata: $data['metadata'] ?? null,
        );

        return $this->created(new AuditLogResource($log));
    }
}
