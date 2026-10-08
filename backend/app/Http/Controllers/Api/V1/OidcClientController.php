<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\V1\Concerns\ApiResponses;
use App\Http\Controllers\Controller;
use App\Models\OidcAccessToken;
use App\Models\OidcClient;
use App\Services\AuditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Registers the SSO (OpenID Connect) client of an integrated application.
 * The client secret is shown once at creation or rotation and stored only
 * as a hash.
 */
class OidcClientController extends Controller
{
    use ApiResponses;

    public function __construct(private readonly AuditService $audit) {}

    public function index(Request $request): JsonResponse
    {
        $query = OidcClient::query()->with('application:id,application_code,name');
        if ($applicationId = $request->query('application_id')) {
            $query->where('application_id', $applicationId);
        }

        return $this->ok($query->orderBy('name')->get()->map(fn (OidcClient $c) => $this->present($c)));
    }

    public function show(OidcClient $oidcClient): JsonResponse
    {
        return $this->ok($this->present($oidcClient));
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate($this->rules());

        $secret = ($data['confidential'] ?? true) ? Str::random(48) : null;

        $client = OidcClient::create([
            'application_id' => $data['application_id'],
            'client_id' => 'onx_'.Str::lower(Str::random(24)),
            'client_secret_hash' => $secret ? hash('sha256', $secret) : null,
            'name' => $data['name'],
            'redirect_uris' => $data['redirect_uris'],
            'post_logout_redirect_uris' => $data['post_logout_redirect_uris'] ?? [],
            'launch_url' => $data['launch_url'] ?? null,
            'require_pkce' => (bool) ($data['require_pkce'] ?? false),
            'status' => OidcClient::STATUS_ACTIVE,
            'created_by' => $request->user()?->id,
        ]);

        $this->audit->record('sso.client.created', $request, applicationId: $client->application_id, resourceType: 'OidcClient', resourceId: $client->id, newValue: ['client_id' => $client->client_id, 'name' => $client->name]);

        return $this->created(['client' => $this->present($client), 'client_secret' => $secret]);
    }

    public function update(Request $request, OidcClient $oidcClient): JsonResponse
    {
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'redirect_uris' => ['sometimes', 'array', 'min:1', 'max:20'],
            'redirect_uris.*' => ['required', 'url:http,https', 'max:2048'],
            'post_logout_redirect_uris' => ['sometimes', 'array', 'max:20'],
            'post_logout_redirect_uris.*' => ['required', 'url:http,https', 'max:2048'],
            'launch_url' => ['sometimes', 'nullable', 'url:http,https', 'max:2048'],
            'require_pkce' => ['sometimes', 'boolean'],
        ]);

        $old = $oidcClient->only(array_keys($data));
        $oidcClient->update($data);
        $this->audit->record('sso.client.updated', $request, applicationId: $oidcClient->application_id, resourceType: 'OidcClient', resourceId: $oidcClient->id, oldValue: $old, newValue: $data);

        return $this->ok($this->present($oidcClient));
    }

    public function rotateSecret(Request $request, OidcClient $oidcClient): JsonResponse
    {
        if (! $oidcClient->isConfidential()) {
            return $this->fail('VALIDATION_ERROR', 'Public clients have no secret.', 422);
        }

        $secret = Str::random(48);
        $oidcClient->update(['client_secret_hash' => hash('sha256', $secret)]);
        $this->audit->record('sso.client.secret_rotated', $request, applicationId: $oidcClient->application_id, resourceType: 'OidcClient', resourceId: $oidcClient->id);

        return $this->ok(['client' => $this->present($oidcClient), 'client_secret' => $secret]);
    }

    public function revoke(Request $request, OidcClient $oidcClient): JsonResponse
    {
        $oidcClient->update(['status' => OidcClient::STATUS_REVOKED]);
        OidcAccessToken::query()->where('oidc_client_id', $oidcClient->id)->whereNull('revoked_at')->update(['revoked_at' => now()]);
        $this->audit->record('sso.client.revoked', $request, applicationId: $oidcClient->application_id, resourceType: 'OidcClient', resourceId: $oidcClient->id);

        return $this->ok($this->present($oidcClient));
    }

    private function rules(): array
    {
        return [
            'application_id' => ['required', 'uuid', Rule::exists('applications', 'id')],
            'name' => ['required', 'string', 'max:255'],
            'redirect_uris' => ['required', 'array', 'min:1', 'max:20'],
            'redirect_uris.*' => ['required', 'url:http,https', 'max:2048'],
            'post_logout_redirect_uris' => ['nullable', 'array', 'max:20'],
            'post_logout_redirect_uris.*' => ['required', 'url:http,https', 'max:2048'],
            'launch_url' => ['nullable', 'url:http,https', 'max:2048'],
            'confidential' => ['sometimes', 'boolean'],
            'require_pkce' => ['sometimes', 'boolean'],
        ];
    }

    private function present(OidcClient $client): array
    {
        return [
            'id' => $client->id,
            'application_id' => $client->application_id,
            'client_id' => $client->client_id,
            'name' => $client->name,
            'confidential' => $client->isConfidential(),
            'redirect_uris' => $client->redirect_uris,
            'post_logout_redirect_uris' => $client->post_logout_redirect_uris,
            'launch_url' => $client->launch_url,
            'require_pkce' => $client->require_pkce,
            'status' => $client->status,
            'created_at' => $client->created_at,
        ];
    }
}
