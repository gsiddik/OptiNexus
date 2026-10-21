<?php

namespace Tests\Feature\Governance;

use App\Models\OidcClient;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesGovernanceFixtures;
use Tests\Concerns\IssuesServiceAccountTokens;
use Tests\TestCase;

/** Audit A14: the onboarding report names what is missing; it never reports a false success. */
class OnboardingReportTest extends TestCase
{
    use CreatesGovernanceFixtures, IssuesServiceAccountTokens, RefreshDatabase;

    private function report(Tenant $tenant)
    {
        return $this->getJson("/api/v1/ecosystem/onboarding/tenants/{$tenant->id}");
    }

    private function statuses(array $checks): array
    {
        return collect($checks)->mapWithKeys(fn (array $c): array => [$c['key'] => $c['status']])->all();
    }

    public function test_a_bare_tenant_reports_every_gap_with_an_action(): void
    {
        $this->actingAsSuperAdmin();
        $tenant = $this->makeTenant();
        $entry = $this->makeApplication(['application_code' => 'optiaccounting']);
        $tenant->applications()->attach($entry->id, ['id' => (string) Str::uuid(), 'status' => 'ACTIVE']);

        $data = $this->report($tenant)->assertOk()->json('data');
        $status = $this->statuses($data['checks']);

        $this->assertFalse($data['ready']);
        $this->assertSame('MISSING', $status['tenant.admin']);
        $this->assertSame('MISSING', $status['optiaccounting.oidc_client']);
        $this->assertSame('MISSING', $status['optiaccounting.service_account']);
        $this->assertSame('WARN', $status['optiaccounting.manifest']);
        $this->assertSame('MISSING', $status['optiaccounting.users']);
        $missing = collect($data['checks'])->firstWhere('key', 'optiaccounting.oidc_client');
        $this->assertSame('oidc_client_missing', $missing['message_code']);
        $this->assertNotEmpty($missing['action']['route']);
        $this->assertSame('optiaccounting', $data['applications'][0]['code']);
        $this->assertNull($data['applications'][0]['oidc_client']);
    }

    public function test_a_complete_setup_is_ready(): void
    {
        $this->actingAsSuperAdmin();
        $tenant = $this->makeTenant();
        $entry = $this->makeApplication(['application_code' => 'optiaccounting', 'frontend_url' => 'https://entry.example.test', 'metadata' => ['manifest' => ['revision' => 'r1', 'synced_at' => now()->toIso8601String()]]]);
        $this->makePermission($entry, 'optiaccounting.journal.view')->update(['delegable' => true]);
        $tenant->applications()->attach($entry->id, ['id' => (string) Str::uuid(), 'status' => 'ACTIVE']);
        $admin = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $this->attachUserToTenant($admin, $tenant, null, isTenantAdmin: true);
        $this->grantApplicationAccess($admin, $tenant, $entry);
        OidcClient::create(['application_id' => $entry->id, 'client_id' => 'onx_test', 'client_secret_hash' => hash('sha256', 'x'), 'name' => 'Entry', 'redirect_uris' => ['https://entry.example.test/cb'], 'require_pkce' => true, 'status' => 'ACTIVE']);
        $this->issueServiceAccountTokenFor($entry->id);

        $data = $this->report($tenant)->assertOk()->json('data');

        $this->assertTrue($data['ready'], json_encode(array_filter($data['checks'], fn ($c) => $c['status'] !== 'OK')));
        $this->assertSame(1, $data['applications'][0]['users_with_access']);
        $this->assertTrue($data['applications'][0]['oidc_client']['pkce_required']);
    }

    public function test_a_client_without_pkce_is_a_warning_not_a_success(): void
    {
        $this->actingAsSuperAdmin();
        $tenant = $this->makeTenant();
        $entry = $this->makeApplication(['application_code' => 'optiaccounting']);
        $tenant->applications()->attach($entry->id, ['id' => (string) Str::uuid(), 'status' => 'ACTIVE']);
        OidcClient::create(['application_id' => $entry->id, 'client_id' => 'onx_test', 'name' => 'Entry', 'redirect_uris' => ['https://entry.example.test/cb'], 'require_pkce' => false, 'status' => 'ACTIVE']);

        $this->assertSame('WARN', $this->statuses($this->report($tenant)->json('data.checks'))['optiaccounting.oidc_pkce']);
    }

    public function test_an_active_tenant_report_formats_a_persisted_service_account_usage_timestamp(): void
    {
        $this->actingAsSuperAdmin();
        $tenant = $this->makeTenant();
        $fleet = $this->makeApplication(['application_code' => 'optifleet']);
        $tenant->applications()->attach($fleet->id, ['id' => (string) Str::uuid(), 'status' => 'ACTIVE']);
        [, $account] = $this->issueServiceAccountTokenFor($fleet->id);
        $account->forceFill(['last_used_at' => '2026-10-21 02:30:00'])->save();

        $this->report($tenant)->assertOk()
            ->assertJsonPath('data.tenant.status', Tenant::STATUS_ACTIVE)
            ->assertJsonPath('data.applications.0.service_account.present', true)
            ->assertJsonPath('data.applications.0.service_account.last_used_at', '2026-10-21T02:30:00+00:00');
    }

    public function test_it_is_closed_to_tenant_users_and_checks_the_tenant_permission(): void
    {
        $tenant = $this->makeTenant();
        $this->seedGovernanceBaseline();
        $role = $this->makeTenantRole($tenant, ['cgo.tenant.view']);
        $admin = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $this->attachUserToTenant($admin, $tenant, $role, isTenantAdmin: true);
        Sanctum::actingAs($admin);
        $this->report($tenant)->assertStatus(403)->assertJsonPath('error.code', 'ECOSYSTEM_ACCESS_REQUIRED');

        $this->actingAsOperatorWith();
        $this->report($tenant)->assertStatus(403)->assertJsonPath('error.code', 'TENANT_ACCESS_DENIED');
    }
}
