<?php

namespace App\Console\Commands;

use App\Models\Application;
use App\Models\ServiceAccount;
use App\Models\Tenant;
use App\Models\User;
use App\Services\AuthorizationService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Laravel\Passport\ClientRepository;

/**
 * Simulates an external platform (OptiFleet) integrating with CGO end to
 * end: authenticate as a service account (OAuth2 client_credentials),
 * request an authorization decision for an authorized user (expect ALLOW),
 * and for a cross-tenant user (expect DENY). Exercises the real HTTP API,
 * not internal service calls, so it proves the wire contract works.
 *
 * Usage: php artisan cgo:simulate-integration [--base-url=http://127.0.0.1:8000]
 */
class SimulateIntegration extends Command
{
    protected $signature = 'cgo:simulate-integration {--base-url=http://127.0.0.1:8000}';

    protected $description = 'Simulate an external application (OptiFleet) integrating with the CGO Authorization Check API';

    public function handle(AuthorizationService $authorization): int
    {
        $baseUrl = rtrim($this->option('base-url'), '/').'/api/v1';

        $application = Application::query()->where('application_code', 'optifleet')->first();
        $tenantA = Tenant::query()->where('tenant_code', 'ACME-SG')->first();
        $authorizedUser = User::query()->where('email', 'tenant.admin@acme.example')->first();

        if (! $application || ! $tenantA || ! $authorizedUser) {
            $this->error('Demo data not found. Run `php artisan db:seed` first.');

            return self::FAILURE;
        }

        // A second tenant the authorized user does NOT belong to, to prove
        // cross-tenant isolation on the authorization check itself.
        $tenantB = Tenant::query()->where('id', '!=', $tenantA->id)->first()
            ?? Tenant::create([
                'tenant_code' => 'SIM-OTHER',
                'customer_id' => $tenantA->customer_id,
                'name' => 'Unrelated Tenant',
                'status' => Tenant::STATUS_ACTIVE,
            ]);

        $this->info('Step 1: OptiFleet authenticates against CGO (OAuth2 client_credentials)...');
        $serviceAccount = ServiceAccount::query()->where('name', 'optifleet-integration')->first();
        $client = $serviceAccount?->oauthClient;

        if (! $serviceAccount || ! $client) {
            $this->error('OptiFleet service account not found. Run `php artisan db:seed` first.');

            return self::FAILURE;
        }

        // The plaintext secret only exists at creation time and isn't
        // persisted anywhere - rotate it here so this command is
        // self-contained and repeatable without reseeding.
        $clients = app(ClientRepository::class);
        $clients->regenerateSecret($client);

        $tokenResponse = Http::asJson()->post("{$baseUrl}/oauth/token", [
            'grant_type' => 'client_credentials',
            'client_id' => $client->id,
            'client_secret' => $client->plainSecret,
            'scope' => 'authorization.check audit.write',
        ]);

        if ($tokenResponse->failed()) {
            $this->error('Token request failed: '.$tokenResponse->body());

            return self::FAILURE;
        }

        $accessToken = $tokenResponse->json('access_token');
        $this->line('  -> access token issued (client_credentials, scopes: authorization.check audit.write)');

        $this->info('Step 2: OptiFleet checks optifleet.vehicle.update for its own tenant admin...');
        $allowResponse = Http::withToken($accessToken)->asJson()->post("{$baseUrl}/authorization/check", [
            'user_id' => $authorizedUser->id,
            'tenant_id' => $tenantA->id,
            'application_code' => 'optifleet',
            'permission' => 'optifleet.vehicle.update',
        ]);

        $allowed = $allowResponse->json('data.allowed');
        $scope = $allowResponse->json('data.scope');
        $this->line("  -> HTTP {$allowResponse->status()}: allowed=".json_encode($allowed).", scope={$scope}");

        $this->info('Step 3: OptiFleet checks the SAME permission scoped to a tenant the user does not belong to...');
        $denyResponse = Http::withToken($accessToken)->asJson()->post("{$baseUrl}/authorization/check", [
            'user_id' => $authorizedUser->id,
            'tenant_id' => $tenantB->id,
            'application_code' => 'optifleet',
            'permission' => 'optifleet.vehicle.update',
        ]);
        $denied = $denyResponse->json('data.allowed');
        $this->line("  -> HTTP {$denyResponse->status()}: allowed=".json_encode($denied));

        $this->info('Step 4: OptiFleet submits an audit event for the action it just performed...');
        $auditResponse = Http::withToken($accessToken)->asJson()->post("{$baseUrl}/audit-events", [
            'tenant_id' => $tenantA->id,
            'action' => 'vehicle.updated',
            'resource_type' => 'Vehicle',
            'resource_id' => 'veh-sim-001',
        ]);
        $this->line("  -> HTTP {$auditResponse->status()}: audit log id=".$auditResponse->json('data.id'));

        $this->newLine();
        $success = $allowed === true && $denied === false && $allowResponse->status() === 200 && $denyResponse->status() === 200;

        if ($success) {
            $this->info('RESULT: PASS - authorized request ALLOWED, cross-tenant request DENIED, audit event recorded.');
        } else {
            $this->error('RESULT: FAIL - see responses above.');
        }

        return $success ? self::SUCCESS : self::FAILURE;
    }
}
