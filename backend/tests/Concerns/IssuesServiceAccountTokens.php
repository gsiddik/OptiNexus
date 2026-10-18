<?php

namespace Tests\Concerns;

use App\Models\ServiceAccount;
use Laravel\Passport\ClientRepository;

/** Machine-to-machine fixtures: a registered service account and a client-credentials token for it. */
trait IssuesServiceAccountTokens
{
    /** @return array{0: string, 1: ServiceAccount} bearer token and its service account */
    protected function issueServiceAccountTokenFor(?string $applicationId, array $scopes = ['authorization.check'], ?string $tenantId = null, string $name = 'test-integration'): array
    {
        $client = app(ClientRepository::class)->createClientCredentialsGrantClient($name);

        $serviceAccount = ServiceAccount::create([
            'application_id' => $applicationId,
            'tenant_id' => $tenantId,
            'oauth_client_id' => $client->id,
            'name' => $name,
            'status' => ServiceAccount::STATUS_ACTIVE,
        ]);

        $token = $this->postJson('/api/v1/oauth/token', [
            'grant_type' => 'client_credentials',
            'client_id' => $client->id,
            'client_secret' => $client->plainSecret,
            'scope' => implode(' ', $scopes),
        ])->assertOk()->json('access_token');

        return [$token, $serviceAccount];
    }
}
