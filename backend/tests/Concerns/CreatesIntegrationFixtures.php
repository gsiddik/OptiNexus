<?php

namespace Tests\Concerns;

use App\Models\Application;
use App\Models\OidcClient;
use App\Models\ServiceAccount;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Str;
use Laravel\Passport\ClientRepository;

trait CreatesIntegrationFixtures
{
    use CreatesGovernanceFixtures;

    /** @return array{0: OidcClient, 1: string} [client, plain secret] */
    protected function makeOidcClient(Application $application, array $overrides = []): array
    {
        $secret = 'secret-'.Str::random(24);
        $client = OidcClient::create(array_merge([
            'application_id' => $application->id,
            'client_id' => 'onx_test_'.Str::lower(Str::random(10)),
            'client_secret_hash' => hash('sha256', $secret),
            'name' => $application->name,
            'redirect_uris' => ['https://app.example.test/callback'],
            'post_logout_redirect_uris' => ['https://app.example.test/bye'],
            'status' => OidcClient::STATUS_ACTIVE,
        ], $overrides));

        return [$client, $secret];
    }

    /** A user who belongs to the tenant and may open the application in it. */
    protected function makeSsoUser(Tenant $tenant, Application $application, array $overrides = []): User
    {
        $user = User::factory()->create(array_merge([
            'status' => User::STATUS_ACTIVE,
            'password' => 'Passw0rd!Passw0rd',
        ], $overrides));

        $this->attachUserToTenant($user, $tenant);
        $this->grantApplicationAccess($user, $tenant, $application);

        return $user;
    }

    protected function pkcePair(): array
    {
        $verifier = Str::random(64);

        return [$verifier, rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=')];
    }

    /** Issues a client-credentials token for a service account of the application. */
    protected function serviceToken(Application $application, array $scopes, ?Tenant $tenant = null): string
    {
        $client = app(ClientRepository::class)->createClientCredentialsGrantClient('svc-'.Str::random(6));
        ServiceAccount::create([
            'application_id' => $application->id,
            'tenant_id' => $tenant?->id,
            'oauth_client_id' => $client->id,
            'name' => 'svc-'.$application->application_code,
            'status' => ServiceAccount::STATUS_ACTIVE,
        ]);

        return $this->postJson('/api/v1/oauth/token', [
            'grant_type' => 'client_credentials',
            'client_id' => $client->id,
            'client_secret' => $client->plainSecret,
            'scope' => implode(' ', $scopes),
        ])->assertStatus(200)->json('access_token');
    }
}
