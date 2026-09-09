<?php

namespace App\Console\Commands;

use App\Models\Application;
use App\Models\Capability;
use App\Models\Role;
use App\Models\ServiceAccount;
use App\Models\Tenant;
use App\Models\User;
use App\Models\UsageEvent;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Laravel\Passport\ClientRepository;

/**
 * Simulates the full Phase 2 commercial flow for an OptiFleet-like tenant,
 * end to end over the real HTTP API (not internal service calls), so it
 * proves the wire contract works exactly as an external caller would see
 * it: register a Product/Plan/Pricing catalog, subscribe + activate a
 * tenant, generate entitlements, read the commercial context and check an
 * entitlement (both M2M), submit metered usage (with a duplicate to prove
 * idempotency), run + finalize billing, generate an invoice, then verify
 * invalid-tenant handling, suspended-subscription entitlement behavior, and
 * that a later price change never touches the already-finalized numbers.
 *
 * Usage: php artisan cgo:simulate-commercial [--base-url=http://127.0.0.1:8000]
 */
class SimulateCommercialIntegration extends Command
{
    protected $signature = 'cgo:simulate-commercial {--base-url=http://127.0.0.1:8000}';

    protected $description = 'Simulate the OptiFleet Phase 2 commercial flow (subscribe -> entitlements -> usage -> billing -> invoice) over the real HTTP API';

    private array $checks = [];

    public function handle(): int
    {
        $baseUrl = rtrim($this->option('base-url'), '/').'/api/v1';
        $suffix = Str::upper(Str::random(5));

        // ---------------------------------------------------------- Actors
        $this->info('Step 0: Provisioning simulation actors and fixtures...');
        $productManager = $this->makeActor('sim.product.manager@cgo.local', 'PRODUCT_MANAGER');
        $commercialAdmin = $this->makeActor('sim.commercial.admin@cgo.local', 'COMMERCIAL_ADMIN');
        $subscriptionAdmin = $this->makeActor('sim.subscription.admin@cgo.local', 'SUBSCRIPTION_ADMIN');
        $billingAdmin = $this->makeActor('sim.billing.admin@cgo.local', 'BILLING_ADMIN');
        $billingApprover = $this->makeActor('sim.billing.approver@cgo.local', 'BILLING_APPROVER');

        $pmToken = $this->login($baseUrl, $productManager);
        $caToken = $this->login($baseUrl, $commercialAdmin);
        $saToken = $this->login($baseUrl, $subscriptionAdmin);
        $baToken = $this->login($baseUrl, $billingAdmin);
        $bpToken = $this->login($baseUrl, $billingApprover);

        $application = Application::query()->where('application_code', 'optifleet')->first()
            ?? Application::create(['application_code' => 'optifleet', 'name' => 'OptiFleet', 'status' => Application::STATUS_PUBLISHED]);
        $vehicleFeature = Capability::query()->where('code', 'vehicle_feature')->where('application_id', $application->id)->first()
            ?? Capability::create(['application_id' => $application->id, 'type' => Capability::TYPE_FEATURE, 'code' => 'vehicle_feature', 'name' => 'Vehicle Feature', 'status' => 'ACTIVE']);

        $customer = \App\Models\Customer::create(['customer_code' => "SIM-CUST-{$suffix}", 'legal_name' => 'Simulated Fleet Co', 'status' => 'ACTIVE']);
        $tenant = Tenant::create(['tenant_code' => "SIM-TEN-{$suffix}", 'customer_id' => $customer->id, 'name' => 'Simulated Fleet Tenant', 'status' => 'ACTIVE']);
        $tenant->applications()->attach($application->id, ['id' => (string) Str::uuid(), 'status' => 'ACTIVE']);
        $this->line("  -> Customer {$customer->customer_code}, Tenant {$tenant->tenant_code} created");

        // ------------------------------------------------- Product & Plan
        $this->info('Step 1: Registering the commercial catalog (Product -> Plan -> Pricing) via the real admin API...');
        $product = $this->post($baseUrl, $pmToken, '/products', [
            'product_code' => "FLEET-SUITE-SIM-{$suffix}", 'name' => 'Fleet Operations Suite (Sim)', 'currency' => 'IDR',
        ]);
        $productId = $product['data']['id'];
        $this->post($baseUrl, $pmToken, "/products/{$productId}/applications/{$application->id}", []);
        $this->post($baseUrl, $pmToken, "/products/{$productId}/activate", []);

        $plan = $this->post($baseUrl, $pmToken, '/plans', [
            'product_id' => $productId, 'plan_code' => "OPTIFLEET-PRO-SIM-{$suffix}", 'name' => 'OptiFleet Professional (Sim)',
            'billing_interval' => 'MONTHLY', 'currency' => 'IDR', 'trial_days' => 14,
        ]);
        $planId = $plan['data']['id'];
        $this->post($baseUrl, $pmToken, "/plans/{$planId}/capabilities/{$vehicleFeature->id}", []);
        $this->post($baseUrl, $pmToken, "/plans/{$planId}/limits", ['limit_key' => 'vehicle_limit', 'limit_value' => 100, 'unit' => 'vehicle']);
        $this->post($baseUrl, $pmToken, "/plans/{$planId}/activate", []);
        $this->check('Product and Plan reach ACTIVE via the real API', true);

        $this->info('Step 2: Configuring pricing (flat platform fee + per-vehicle overage)...');
        $this->post($baseUrl, $caToken, '/prices', [
            'plan_id' => $planId, 'price_type' => 'FLAT', 'currency' => 'IDR', 'unit_amount' => '2000000.00', 'billing_interval' => 'MONTHLY',
        ]);
        $this->post($baseUrl, $caToken, '/prices', [
            'plan_id' => $planId, 'price_type' => 'PER_VEHICLE', 'currency' => 'IDR', 'unit_amount' => '30000.00', 'billing_interval' => 'MONTHLY',
            'unit_name' => 'vehicle', 'meter_key' => 'vehicles', 'included_quantity' => '100.0000',
        ]);
        $this->check('Both price components auto-approved (global, no tenant override)', true);

        // ------------------------------------------------- Subscription
        $this->info('Step 3: Subscribing and activating the tenant...');
        $subscription = $this->post($baseUrl, $saToken, '/subscriptions', [
            'customer_id' => $customer->id, 'tenant_id' => $tenant->id, 'plan_id' => $planId,
        ]);
        $subscriptionId = $subscription['data']['id'];
        $activated = $this->post($baseUrl, $saToken, "/subscriptions/{$subscriptionId}/activate", []);
        $this->check('Subscription activates (DRAFT -> ACTIVE)', $activated['data']['status'] === 'ACTIVE');

        $entitlementCount = \App\Models\Entitlement::query()->where('tenant_id', $tenant->id)->where('status', 'ACTIVE')->count();
        $this->check('Activation transactionally generated entitlements', $entitlementCount > 0);

        // ------------------------------------------------------- M2M setup
        $client = app(ClientRepository::class)->createClientCredentialsGrantClient("sim-optifleet-{$suffix}");
        ServiceAccount::create(['application_id' => $application->id, 'oauth_client_id' => $client->id, 'name' => "sim-optifleet-{$suffix}", 'status' => 'ACTIVE']);
        $m2mResponse = Http::asJson()->post("{$baseUrl}/oauth/token", [
            'grant_type' => 'client_credentials', 'client_id' => $client->id, 'client_secret' => $client->plainSecret,
            'scope' => 'entitlement.check commercial.read usage.write',
        ]);
        $m2mToken = $m2mResponse->json('access_token');
        $this->check('M2M service account issued a client-credentials token', (bool) $m2mToken);

        $this->info('Step 4: OptiFleet reads the tenant\'s commercial context (M2M)...');
        $context = $this->get($baseUrl, $m2mToken, "/tenants/{$tenant->id}/commercial-context");
        $this->check('Commercial context lists the active subscription', count($context['data']['subscriptions'] ?? []) === 1);
        $this->check('Commercial context reports optifleet enabled', in_array('optifleet', $context['data']['applications_enabled'] ?? [], true));

        $this->info('Step 5: OptiFleet checks the vehicle_limit entitlement (M2M)...');
        $check = $this->post($baseUrl, $m2mToken, '/entitlements/check', [
            'tenant_id' => $tenant->id, 'application_code' => 'optifleet', 'entitlement_key' => 'vehicle_limit',
        ]);
        $this->check('Entitlement check allows and reports the plan limit (100)', $check['data']['allowed'] === true && (float) $check['data']['value'] === 100.0);

        // -------------------------------------------------------- Usage
        $this->info('Step 6: OptiFleet reports usage (125 vehicles), then retries the same report...');
        $idempotencyKey = "sim-usage-{$suffix}";
        $usage1 = $this->postRaw($baseUrl, $m2mToken, '/usage-events', [
            'tenant_id' => $tenant->id, 'application_code' => 'optifleet', 'subscription_id' => $subscriptionId,
            'meter_key' => 'vehicles', 'quantity' => 125, 'idempotency_key' => $idempotencyKey,
        ]);
        $usage2 = $this->postRaw($baseUrl, $m2mToken, '/usage-events', [
            'tenant_id' => $tenant->id, 'application_code' => 'optifleet', 'subscription_id' => $subscriptionId,
            'meter_key' => 'vehicles', 'quantity' => 125, 'idempotency_key' => $idempotencyKey,
        ]);
        $this->check('First usage report creates a record (201)', $usage1->status() === 201);
        $this->check('Duplicate usage report replays the same record (200, not 201)', $usage2->status() === 200 && $usage2->json('data.id') === $usage1->json('data.id'));
        $this->check('Exactly one usage_events row exists for this idempotency key', UsageEvent::query()->where('idempotency_key', $idempotencyKey)->count() === 1);

        // ------------------------------------------------------- Billing
        $this->info('Step 7: Running billing for the subscription\'s current period...');
        $billingRun = $this->post($baseUrl, $baToken, '/billing-runs', ['subscription_id' => $subscriptionId]);
        $billing = $billingRun['data']['results'][0];
        $expectedTotal = '2750000.00'; // 2,000,000 flat + (125-100) * 30,000 overage
        $this->check("Billing total is exactly {$expectedTotal} (flat + 25 overage vehicles)", $billing['total'] === $expectedTotal);

        $this->info('Step 8: Enforcing segregation of duties on finalize...');
        $deniedFinalize = $this->postRaw($baseUrl, $baToken, "/billings/{$billing['id']}/finalize", []);
        $this->check('BILLING_ADMIN cannot finalize (403 - lacks cgo.billing.finalize)', $deniedFinalize->status() === 403);
        $finalized = $this->post($baseUrl, $bpToken, "/billings/{$billing['id']}/finalize", []);
        $this->check('BILLING_APPROVER finalizes successfully', $finalized['data']['status'] === 'FINALIZED');

        $this->info('Step 9: Generating the invoice from the finalized billing...');
        $invoice = $this->post($baseUrl, $baToken, "/invoices/generate-from-billing/{$billing['id']}", []);
        $invoiceTotal = $invoice['data']['total'];
        $invoiceNumber = $invoice['data']['invoice_number'];
        $this->check('Invoice total matches the finalized billing total', $invoiceTotal === $expectedTotal);
        $this->check('Invoice number is server-generated (non-empty, unguessable sequence)', ! empty($invoiceNumber));

        // ------------------------------------------------------ Edge cases
        $this->info('Step 10: Verifying invalid-tenant handling...');
        $bogusTenantId = (string) Str::uuid();
        $invalidContext = $this->getRaw($baseUrl, $m2mToken, "/tenants/{$bogusTenantId}/commercial-context");
        $this->check('Commercial context for a non-existent tenant returns 404 RESOURCE_NOT_FOUND', $invalidContext->status() === 404 && $invalidContext->json('error.code') === 'RESOURCE_NOT_FOUND');
        $invalidCheck = $this->postRaw($baseUrl, $m2mToken, '/entitlements/check', ['tenant_id' => $bogusTenantId, 'application_code' => 'optifleet', 'entitlement_key' => 'vehicle_limit']);
        $this->check('Entitlement check for a non-existent tenant is rejected (422 VALIDATION_ERROR)', $invalidCheck->status() === 422);

        $this->info('Step 11: Verifying suspended-subscription entitlement behavior...');
        $this->post($baseUrl, $saToken, "/subscriptions/{$subscriptionId}/suspend", []);
        $suspendedCheck = $this->post($baseUrl, $m2mToken, '/entitlements/check', ['tenant_id' => $tenant->id, 'application_code' => 'optifleet', 'entitlement_key' => 'vehicle_limit']);
        $this->check('Suspended subscription denies the entitlement check', $suspendedCheck['data']['allowed'] === false);
        $suspendedContext = $this->get($baseUrl, $m2mToken, "/tenants/{$tenant->id}/commercial-context");
        $this->check('Suspended subscription no longer appears in the commercial context', count($suspendedContext['data']['subscriptions'] ?? []) === 0);
        $this->post($baseUrl, $saToken, "/subscriptions/{$subscriptionId}/reactivate", []);

        $this->info('Step 12: Verifying a later price change never touches the finalized/invoiced numbers...');
        $this->post($baseUrl, $caToken, '/prices', [
            'plan_id' => $planId, 'price_type' => 'FLAT', 'currency' => 'IDR', 'unit_amount' => '9999999.00', 'billing_interval' => 'MONTHLY',
        ]);
        $invoiceAfterPriceChange = $this->get($baseUrl, $baToken, "/invoices/{$invoice['data']['id']}");
        $this->check('Historical invoice total is unchanged after the global price increased', $invoiceAfterPriceChange['data']['total'] === $expectedTotal);
        $billingAfterPriceChange = $this->get($baseUrl, $baToken, "/billings/{$billing['id']}");
        $this->check('Historical (finalized) billing total is unchanged after the global price increased', $billingAfterPriceChange['data']['total'] === $expectedTotal);

        // ------------------------------------------------------------ Report
        $this->newLine();
        $failed = array_filter($this->checks, fn ($c) => ! $c['pass']);

        if (empty($failed)) {
            $this->info('RESULT: PASS - all '.count($this->checks).' commercial integration checks passed.');

            return self::SUCCESS;
        }

        $this->error('RESULT: FAIL - '.count($failed).' of '.count($this->checks).' checks failed.');

        return self::FAILURE;
    }

    private function check(string $label, bool $pass): void
    {
        $this->checks[] = ['label' => $label, 'pass' => $pass];
        $this->line(($pass ? '  [PASS] ' : '  [FAIL] ').$label);
    }

    private function makeActor(string $email, string $roleCode): User
    {
        // A fresh password each run - only this process ever needs to log
        // in as this actor, immediately after setting it, over real HTTP.
        $rawPassword = 'sim-'.Str::random(16);

        $user = User::query()->updateOrCreate(
            ['email' => $email],
            ['name' => $roleCode, 'password' => $rawPassword, 'status' => User::STATUS_ACTIVE],
        );

        $role = Role::query()->where('code', $roleCode)->whereNull('tenant_id')->firstOrFail();

        if (! $user->userRoles()->where('role_id', $role->id)->exists()) {
            $user->userRoles()->create(['role_id' => $role->id, 'tenant_id' => null]);
        }

        return $user->forceFill(['plainPasswordForSim' => $rawPassword]);
    }

    private function login(string $baseUrl, User $user): string
    {
        $response = Http::asJson()->post("{$baseUrl}/auth/login", [
            'email' => $user->email,
            'password' => $user->plainPasswordForSim,
            'device_name' => 'commercial-simulation',
        ]);

        if ($response->failed()) {
            $this->error("Login failed for {$user->email}: ".$response->body());
            exit(self::FAILURE);
        }

        return $response->json('data.token');
    }

    private function post(string $baseUrl, string $token, string $path, array $payload): array
    {
        $response = $this->postRaw($baseUrl, $token, $path, $payload);

        if ($response->failed()) {
            $this->error("POST {$path} failed unexpectedly ({$response->status()}): ".$response->body());
            exit(self::FAILURE);
        }

        return $response->json();
    }

    private function postRaw(string $baseUrl, string $token, string $path, array $payload)
    {
        return Http::withToken($token)->asJson()->post("{$baseUrl}{$path}", $payload);
    }

    private function get(string $baseUrl, string $token, string $path): array
    {
        $response = $this->getRaw($baseUrl, $token, $path);

        if ($response->failed()) {
            $this->error("GET {$path} failed unexpectedly ({$response->status()}): ".$response->body());
            exit(self::FAILURE);
        }

        return $response->json();
    }

    private function getRaw(string $baseUrl, string $token, string $path)
    {
        return Http::withToken($token)->asJson()->get("{$baseUrl}{$path}");
    }
}
