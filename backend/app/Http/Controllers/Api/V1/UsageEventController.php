<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\V1\Concerns\ApiResponses;
use App\Http\Controllers\Controller;
use App\Http\Requests\V1\StoreUsageEventRequest;
use App\Http\Resources\V1\UsageEventResource;
use App\Models\Application;
use App\Models\ServiceAccount;
use App\Models\Tenant;
use App\Models\UsageEvent;
use App\Services\AuditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class UsageEventController extends Controller
{
    use ApiResponses;

    public function __construct(private readonly AuditService $audit) {}

    public function index(Request $request): JsonResponse
    {
        return $this->listUsage($request);
    }

    public function forTenant(Request $request, Tenant $tenant): JsonResponse
    {
        return $this->listUsage($request, $tenant);
    }

    private function listUsage(Request $request, ?Tenant $tenant = null): JsonResponse
    {
        $query = UsageEvent::query();

        if ($tenant) {
            $query->where('tenant_id', $tenant->id);
        } elseif ($tenantId = $request->query('tenant_id')) {
            $query->where('tenant_id', $tenantId);
        }

        if ($applicationCode = $request->query('application')) {
            $applicationId = Application::query()->where('application_code', $applicationCode)->value('id');
            $query->where('application_id', $applicationId);
        }
        foreach (['meter_key', 'subscription_id'] as $filter) {
            if ($value = $request->query($filter)) {
                $query->where($filter, $value);
            }
        }
        if ($from = $request->query('period_start')) {
            $query->where('period_start', '>=', $from);
        }
        if ($to = $request->query('period_end')) {
            $query->where('period_end', '<=', $to);
        }

        $paginator = $query->orderByDesc('usage_timestamp')->paginate((int) $request->query('per_page', 25));

        return $this->paginated($paginator, UsageEventResource::class);
    }

    /**
     * Machine-to-machine ingestion. The reporting application's identity
     * comes from the authenticated service account, not the request body;
     * if that service account is scoped to one application, it may only
     * submit usage under its own application_code.
     */
    public function store(StoreUsageEventRequest $request): JsonResponse
    {
        /** @var ServiceAccount $serviceAccount */
        $serviceAccount = $request->attributes->get('service_account');
        $data = $request->validated();

        $application = Application::query()->where('application_code', $data['application_code'])->first();
        if (! $application) {
            return $this->fail('RESOURCE_NOT_FOUND', 'Unknown application_code.', 404);
        }

        if ($serviceAccount->application_id && $serviceAccount->application_id !== $application->id) {
            return $this->fail('APPLICATION_ACCESS_DENIED', 'This service account may only submit usage for its own application.', 403);
        }

        $tenant = Tenant::findOrFail($data['tenant_id']);

        if (! $tenant->applications()->where('applications.id', $application->id)->exists()) {
            return $this->fail('APPLICATION_ACCESS_DENIED', 'This application is not assigned to the given tenant.', 403);
        }

        if (! empty($data['idempotency_key'])) {
            $existing = UsageEvent::query()
                ->where('tenant_id', $tenant->id)
                ->where('meter_key', $data['meter_key'])
                ->where('idempotency_key', $data['idempotency_key'])
                ->first();

            if ($existing) {
                return $this->ok(new UsageEventResource($existing));
            }
        }

        $timestamp = isset($data['usage_timestamp']) ? Carbon::parse($data['usage_timestamp']) : now();
        $periodStart = isset($data['period_start']) ? Carbon::parse($data['period_start']) : $timestamp->copy()->startOfMonth();
        $periodEnd = isset($data['period_end']) ? Carbon::parse($data['period_end']) : $timestamp->copy()->endOfMonth();

        try {
            $event = UsageEvent::create([
                'tenant_id' => $tenant->id,
                'application_id' => $application->id,
                'subscription_id' => $data['subscription_id'] ?? null,
                'meter_key' => $data['meter_key'],
                'quantity' => $data['quantity'],
                'usage_timestamp' => $timestamp,
                'period_start' => $periodStart,
                'period_end' => $periodEnd,
                'source' => $application->application_code,
                'external_reference' => $data['external_reference'] ?? null,
                'idempotency_key' => $data['idempotency_key'] ?? null,
                'metadata' => $data['metadata'] ?? null,
            ]);
        } catch (\Illuminate\Database\UniqueConstraintViolationException) {
            // Lost a race against a concurrent request with the same
            // idempotency key: treat it as the same success, not an error.
            $existing = UsageEvent::query()
                ->where('tenant_id', $tenant->id)
                ->where('meter_key', $data['meter_key'])
                ->where('idempotency_key', $data['idempotency_key'])
                ->firstOrFail();

            return $this->ok(new UsageEventResource($existing));
        }

        $this->audit->record(
            'usage.reported',
            $request,
            actorIdentity: $serviceAccount->name,
            tenantId: $tenant->id,
            applicationId: $application->id,
            resourceType: 'UsageEvent',
            resourceId: $event->id,
            newValue: ['meter_key' => $event->meter_key, 'quantity' => $event->quantity, 'subscription_id' => $event->subscription_id],
            source: $application->application_code,
        );

        return $this->created(new UsageEventResource($event));
    }
}
