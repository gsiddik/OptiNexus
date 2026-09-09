<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\V1\Concerns\ApiResponses;
use App\Http\Controllers\Api\V1\Concerns\HandlesLifecycleTransitions;
use App\Http\Controllers\Controller;
use App\Http\Requests\V1\StoreIntegrationRequest;
use App\Http\Requests\V1\UpdateIntegrationRequest;
use App\Http\Resources\V1\IntegrationCredentialResource;
use App\Http\Resources\V1\IntegrationLogResource;
use App\Http\Resources\V1\IntegrationResource;
use App\Models\Integration;
use App\Models\IntegrationCredential;
use App\Services\AuditService;
use App\Services\Integration\IntegrationDeliveryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class IntegrationController extends Controller
{
    use ApiResponses, HandlesLifecycleTransitions;

    public function __construct(
        private readonly AuditService $audit,
        private readonly IntegrationDeliveryService $delivery,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $query = Integration::query();

        foreach (['tenant_id', 'source_application_id', 'status', 'integration_type'] as $filter) {
            if ($value = $request->query($filter)) {
                $query->where($filter, $value);
            }
        }

        $paginator = $query->orderByDesc('created_at')->paginate((int) $request->query('per_page', 20));

        return $this->paginated($paginator, IntegrationResource::class);
    }

    public function store(StoreIntegrationRequest $request): JsonResponse
    {
        $data = $request->validated();

        $integration = DB::transaction(function () use ($data, $request) {
            $integration = Integration::create([
                ...collect($data)->except('endpoints')->all(),
                'status' => Integration::STATUS_DRAFT,
                'created_by' => $request->user()?->id,
            ]);

            foreach ($data['endpoints'] ?? [] as $endpoint) {
                $integration->endpoints()->create($endpoint);
            }

            return $integration;
        });

        $this->audit->record('integration.created', $request, resourceType: 'Integration', resourceId: $integration->id, newValue: $integration->toArray(), tenantId: $integration->tenant_id, applicationId: $integration->source_application_id);

        return $this->created(new IntegrationResource($integration->load('endpoints')));
    }

    public function show(Integration $integration): JsonResponse
    {
        return $this->ok(new IntegrationResource($integration->load('endpoints', 'credentials')));
    }

    public function update(UpdateIntegrationRequest $request, Integration $integration): JsonResponse
    {
        $old = $integration->toArray();
        $integration->update($request->validated());

        $this->audit->record('integration.updated', $request, resourceType: 'Integration', resourceId: $integration->id, oldValue: $old, newValue: $integration->toArray(), tenantId: $integration->tenant_id, applicationId: $integration->source_application_id);

        return $this->ok(new IntegrationResource($integration->load('endpoints')));
    }

    public function activate(Request $request, Integration $integration): JsonResponse
    {
        return $this->transitionTo($request, $integration, Integration::STATUS_ACTIVE, [Integration::STATUS_DRAFT, Integration::STATUS_INACTIVE]);
    }

    public function deactivate(Request $request, Integration $integration): JsonResponse
    {
        return $this->transitionTo($request, $integration, Integration::STATUS_INACTIVE, [Integration::STATUS_ACTIVE]);
    }

    public function test(Request $request, Integration $integration): JsonResponse
    {
        $result = $this->delivery->test($integration);

        $this->audit->record('integration.tested', $request, resourceType: 'Integration', resourceId: $integration->id, newValue: ['ok' => $result['ok']], tenantId: $integration->tenant_id, applicationId: $integration->source_application_id);

        return $this->ok($result);
    }

    public function storeCredential(Request $request, Integration $integration): JsonResponse
    {
        $validated = $request->validate([
            'credential_type' => ['required', 'string', 'in:'.implode(',', IntegrationCredential::TYPES)],
            'secret' => ['required', 'string', 'max:4000'],
            'reference_label' => ['required', 'string', 'max:150'],
        ]);

        $credential = DB::transaction(function () use ($integration, $validated, $request) {
            $integration->credentials()->where('status', IntegrationCredential::STATUS_ACTIVE)->update(['status' => IntegrationCredential::STATUS_ROTATED, 'rotated_at' => now()]);

            return $integration->credentials()->create([
                'credential_type' => $validated['credential_type'],
                'encrypted_secret' => $validated['secret'],
                'reference_label' => $validated['reference_label'],
                'status' => IntegrationCredential::STATUS_ACTIVE,
                'created_by' => $request->user()?->id,
            ]);
        });

        // Never write the secret itself into the audit trail.
        $this->audit->record('integration.credential.created', $request, resourceType: 'IntegrationCredential', resourceId: $credential->id, newValue: ['credential_type' => $credential->credential_type, 'reference_label' => $credential->reference_label], tenantId: $integration->tenant_id, applicationId: $integration->source_application_id);

        return $this->created(new IntegrationCredentialResource($credential));
    }

    /**
     * Rotation is the same operation as creating a new credential: the
     * previous ACTIVE one is marked ROTATED and superseded atomically.
     */
    public function rotateCredential(Request $request, Integration $integration): JsonResponse
    {
        return $this->storeCredential($request, $integration);
    }

    public function revokeCredential(Request $request, Integration $integration): JsonResponse
    {
        $credential = $integration->activeCredential();
        if (! $credential) {
            return $this->fail('CONFLICT', 'This integration has no active credential to revoke.', 409);
        }

        $credential->update(['status' => IntegrationCredential::STATUS_REVOKED, 'revoked_at' => now()]);

        $this->audit->record('integration.credential.revoked', $request, resourceType: 'IntegrationCredential', resourceId: $credential->id, tenantId: $integration->tenant_id, applicationId: $integration->source_application_id);

        return $this->ok(new IntegrationCredentialResource($credential));
    }

    public function logs(Request $request, Integration $integration): JsonResponse
    {
        $paginator = $integration->logs()->orderByDesc('created_at')->paginate((int) $request->query('per_page', 25));

        return $this->paginated($paginator, IntegrationLogResource::class);
    }

    private function transitionTo(Request $request, Integration $integration, string $to, array $allowedFrom): JsonResponse
    {
        if ($response = $this->guardTransition($integration, $to, $allowedFrom)) {
            return $response;
        }

        $old = $integration->status;
        $integration->update(['status' => $to]);

        $this->audit->record('integration.status_changed', $request, resourceType: 'Integration', resourceId: $integration->id, oldValue: ['status' => $old], newValue: ['status' => $to], tenantId: $integration->tenant_id, applicationId: $integration->source_application_id);

        return $this->ok(new IntegrationResource($integration));
    }
}
