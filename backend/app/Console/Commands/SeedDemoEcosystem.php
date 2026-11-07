<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\File;

/**
 * Sets up the demo tenant of the whole ecosystem in OptiNexus: one customer, one tenant and one active subscription to every
 * application, plus a tenant administrator who can sign in to all of them through single sign-on. It goes through the operator
 * API of OptiNexus, in this process, exactly as an operator would (same validation, events and audit), so the applications are
 * provisioned by the real assignment events. Safe to run again: whatever exists is reused and nothing is reset.
 *
 * The data inside each application (masters, vehicles, tariffs, orders, stock...) is loaded by that application's own demo
 * command afterwards; `optiecosystem.ps1 seed-demo` runs all of them in order.
 *
 * The administrator's password is written to --credentials-file only (never printed). Without that file the password is not
 * available, and a new temporary password is issued by running the command with --reissue.
 */
class SeedDemoEcosystem extends Command
{
    protected $signature = 'ecosystem:seed-demo
        {--customer=Demo Ekosistem : Customer and tenant name}
        {--admin-email=admin@demo-ekosistem.test : Tenant administrator}
        {--credentials-file= : Where to write the administrator credential (mode 0600)}
        {--reissue : Issue a new temporary password for the administrator}
        {--applications=optifleet,optideli,optistash,optiaccounting,optiradar : Application codes to subscribe}';

    protected $description = 'Create the demo customer, tenant, suite subscription and tenant administrator for every application';

    private ?string $token = null;

    public function handle(): int
    {
        if (! app()->environment(['local', 'testing']) && ! filter_var(env('ECOSYSTEM_ALLOW_DEMO_SEED', false), FILTER_VALIDATE_BOOL)) {
            $this->error('Demo data is for local installations. Set ECOSYSTEM_ALLOW_DEMO_SEED=true to allow it elsewhere.');

            return self::FAILURE;
        }
        $email = (string) env('CGO_SUPERADMIN_EMAIL');
        $password = (string) env('CGO_SUPERADMIN_PASSWORD');
        if ($email === '' || $password === '') {
            $this->error('CGO_SUPERADMIN_EMAIL and CGO_SUPERADMIN_PASSWORD are needed to act as the operator.');

            return self::FAILURE;
        }
        $login = $this->call_('POST', '/auth/login', ['email' => $email, 'password' => $password, 'device_name' => 'seed-demo']);
        $this->token = $login['data']['token'] ?? $login['data']['access_token'] ?? null;
        if (! $this->token) {
            $this->error('The operator could not sign in.');

            return self::FAILURE;
        }

        $name = (string) $this->option('customer');
        $codes = array_filter(array_map('trim', explode(',', (string) $this->option('applications'))));

        // Applications and the suite plan.
        $apps = [];
        foreach ($this->rows($this->call_('GET', '/applications?per_page=100')) as $a) {
            $apps[$a['application_code']] = $a['id'];
        }
        $missing = array_values(array_diff($codes, array_keys($apps)));
        if ($missing !== []) {
            $this->error('Applications not in the catalog: '.implode(', ', $missing).'. Import their manifests first.');

            return self::FAILURE;
        }
        $plan = collect($this->rows($this->call_('GET', '/plans?per_page=100')))->firstWhere('plan_code', 'SUITE-MONTHLY');
        if (! $plan) {
            $this->error('Plan SUITE-MONTHLY not found. Run the ecosystem catalog seeder first.');

            return self::FAILURE;
        }

        // Customer.
        $customer = collect($this->rows($this->call_('GET', '/customers?per_page=100&search='.rawurlencode($name))))->firstWhere('legal_name', $name);
        if (! $customer) {
            $customer = $this->ok($this->call_('POST', '/customers', ['legal_name' => $name, 'business_name' => $name, 'industry' => 'Logistic', 'email' => 'demo.ekosistem@example.test']), 'customer')['data'];
        }
        if (($customer['status'] ?? null) !== 'ACTIVE') {
            $this->ok($this->call_('POST', "/customers/{$customer['id']}/activate"), 'customer activation');
        }
        $this->line("customer   {$customer['id']}");

        // Tenant.
        $tenant = collect($this->rows($this->call_('GET', '/tenants?per_page=100&search='.rawurlencode($name))))->firstWhere('name', $name);
        if (! $tenant) {
            $tenant = $this->ok($this->call_('POST', '/tenants', ['customer_id' => $customer['id'], 'name' => $name, 'region' => 'ID', 'timezone' => 'Asia/Jakarta', 'currency' => 'IDR', 'language' => 'id']), 'tenant')['data'];
        }
        $this->line("tenant     {$tenant['id']}  {$tenant['tenant_code']}");

        // One suite subscription for all the applications.
        $subs = $this->rows($this->call_('GET', '/subscriptions?per_page=100&tenant_id='.$tenant['id']));
        $sub = collect($subs)->first(fn ($s) => in_array($s['status'], ['ACTIVE', 'TRIAL', 'DRAFT'], true));
        if (! $sub) {
            $sub = $this->ok($this->call_('POST', '/subscriptions', [
                'customer_id' => $customer['id'], 'tenant_id' => $tenant['id'], 'application_ids' => array_map(fn ($c) => $apps[$c], $codes),
                'plan_id' => $plan['id'], 'idempotency_key' => 'demo-ecosystem-'.$tenant['id'],
            ]), 'subscription')['data'];
        }
        if (($sub['status'] ?? null) !== 'ACTIVE') {
            $this->ok($this->call_('POST', "/subscriptions/{$sub['id']}/activate"), 'subscription activation');
        }
        $this->line("subscription {$sub['id']}  ".($sub['subscription_number'] ?? ''));

        $current = $this->call_('GET', "/tenants/{$tenant['id']}")['data'] ?? [];
        if (in_array($current['status'] ?? '', ['DRAFT', 'PENDING'], true)) {
            $this->call_('POST', "/tenants/{$tenant['id']}/provision");
        }
        $current = $this->call_('GET', "/tenants/{$tenant['id']}")['data'] ?? [];
        if (($current['status'] ?? '') !== 'ACTIVE') {
            $this->ok($this->call_('POST', "/tenants/{$tenant['id']}/activate"), 'tenant activation');
        }

        // The tenant administrator, once. Its temporary password exists only in the response that creates (or reissues) it.
        $adminEmail = (string) $this->option('admin-email');
        $accounts = $this->rows($this->call_('GET', "/ecosystem/tenants/{$tenant['id']}/admin-accounts"));
        $existing = collect($accounts)->first(fn ($a) => strcasecmp((string) ($a['email'] ?? $a['user']['email'] ?? ''), $adminEmail) === 0);
        $credential = null;
        if (! $existing) {
            $r = $this->ok($this->call_('POST', "/ecosystem/tenants/{$tenant['id']}/admin-accounts", ['name' => 'Admin '.$name, 'email' => $adminEmail, 'application_ids' => array_map(fn ($c) => $apps[$c], $codes)]), 'administrator');
            $credential = $r['data']['temporary_credential'] ?? null;
            $this->line('administrator created');
        } elseif ($this->option('reissue')) {
            $userId = $existing['user_id'] ?? $existing['id'] ?? $existing['user']['id'] ?? null;
            $r = $this->ok($this->call_('POST', "/ecosystem/tenants/{$tenant['id']}/admin-accounts/{$userId}/temporary-credential"), 'reissue');
            $credential = $r['data']['temporary_credential'] ?? $r['data'] ?? null;
            $this->line('administrator password reissued');
        } else {
            $this->line('administrator exists (use --reissue for a new temporary password)');
        }
        if (is_array($credential) && ! empty($credential['password'])) {
            $file = (string) $this->option('credentials-file');
            if ($file === '') {
                $this->warn('A temporary password was issued but no --credentials-file was given; run again with --reissue and a file.');
            } else {
                File::ensureDirectoryExists(dirname($file));
                File::put($file, "tenant={$tenant['tenant_code']}\nemail={$adminEmail}\ntemporary_password={$credential['password']}\nexpires_at=".($credential['expires_at'] ?? '')."\n");
                @chmod($file, 0600);
                $this->line("credential written to {$file}");
            }
        }

        $this->info("Done. Tenant code {$tenant['tenant_code']}; applications: ".implode(', ', $codes).'.');

        return self::SUCCESS;
    }

    /** The operator API in this process: same routes, middleware, validation and audit as over the wire. */
    private function call_(string $method, string $path, array $body = []): array
    {
        $uri = '/api/v1'.$path;
        $request = Request::create($uri, $method, $method === 'GET' ? [] : $body, [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'], $method === 'GET' ? null : json_encode($body));
        if ($this->token) {
            $request->headers->set('Authorization', 'Bearer '.$this->token);
        }
        Auth::forgetGuards();
        $response = app()->handle($request);
        $json = json_decode($response->getContent(), true) ?? [];
        $json['_status'] = $response->getStatusCode();

        return $json;
    }

    /** @param array<string, mixed> $response */
    private function ok(array $response, string $what): array
    {
        if (($response['_status'] ?? 500) >= 400) {
            $this->error("{$what} failed (".$response['_status'].'): '.json_encode($response['error'] ?? $response['message'] ?? $response['errors'] ?? null));
            exit(self::FAILURE);
        }

        return $response;
    }

    /** The rows of a list response, paginated or not. */
    private function rows(array $response): array
    {
        $data = $response['data'] ?? [];

        return array_is_list($data) ? $data : ($data['data'] ?? []);
    }
}
