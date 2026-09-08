<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\V1\Concerns\ApiResponses;
use App\Http\Controllers\Controller;
use App\Http\Requests\V1\StoreServiceAccountRequest;
use App\Http\Resources\V1\ServiceAccountResource;
use App\Models\ServiceAccount;
use App\Services\AuditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Laravel\Passport\ClientRepository;

/**
 * Manages machine-to-machine credentials (OAuth2 client-credentials grant)
 * used by integrated applications such as OptiFleet or OptiAccounting to
 * call CGO's integration APIs. Human admin access only - this is not
 * exposed to service accounts themselves.
 */
class ServiceAccountController extends Controller
{
    use ApiResponses;

    public function __construct(
        private readonly AuditService $audit,
        private readonly ClientRepository $clients,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $query = ServiceAccount::query();

        foreach (['application_id', 'tenant_id', 'status'] as $filter) {
            if ($value = $request->query($filter)) {
                $query->where($filter, $value);
            }
        }

        $paginator = $query->orderByDesc('created_at')->paginate((int) $request->query('per_page', 20));

        return $this->paginated($paginator, ServiceAccountResource::class);
    }

    public function store(StoreServiceAccountRequest $request): JsonResponse
    {
        $data = $request->validated();

        $client = $this->clients->createClientCredentialsGrantClient($data['name']);

        $serviceAccount = ServiceAccount::create([
            'name' => $data['name'],
            'application_id' => $data['application_id'] ?? null,
            'tenant_id' => $data['tenant_id'] ?? null,
            'oauth_client_id' => $client->id,
            'status' => ServiceAccount::STATUS_ACTIVE,
            'created_by' => $request->user()?->id,
        ]);

        $this->audit->record('service_account.created', $request, resourceType: 'ServiceAccount', resourceId: $serviceAccount->id, newValue: ['name' => $serviceAccount->name, 'application_id' => $serviceAccount->application_id], applicationId: $serviceAccount->application_id, tenantId: $serviceAccount->tenant_id);

        return $this->created([
            'service_account' => new ServiceAccountResource($serviceAccount),
            // Returned once; the plaintext secret is never persisted or retrievable again.
            'client_id' => $client->id,
            'client_secret' => $client->plainSecret,
        ]);
    }

    public function show(ServiceAccount $serviceAccount): JsonResponse
    {
        return $this->ok(new ServiceAccountResource($serviceAccount));
    }

    public function rotateSecret(Request $request, ServiceAccount $serviceAccount): JsonResponse
    {
        $client = $serviceAccount->oauthClient;
        if (! $client) {
            return $this->fail('RESOURCE_NOT_FOUND', 'No OAuth client is associated with this service account.', 404);
        }

        $this->clients->regenerateSecret($client);

        $this->audit->record('service_account.secret_rotated', $request, resourceType: 'ServiceAccount', resourceId: $serviceAccount->id, applicationId: $serviceAccount->application_id, tenantId: $serviceAccount->tenant_id);

        return $this->ok([
            'client_id' => $client->id,
            'client_secret' => $client->plainSecret,
        ]);
    }

    public function revoke(Request $request, ServiceAccount $serviceAccount): JsonResponse
    {
        $serviceAccount->update(['status' => ServiceAccount::STATUS_REVOKED]);

        if ($client = $serviceAccount->oauthClient) {
            $this->clients->delete($client);
        }

        $this->audit->record('service_account.revoked', $request, resourceType: 'ServiceAccount', resourceId: $serviceAccount->id, applicationId: $serviceAccount->application_id, tenantId: $serviceAccount->tenant_id);

        return $this->ok(new ServiceAccountResource($serviceAccount));
    }
}
