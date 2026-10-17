<?php

namespace Tests\Feature\Governance;

use App\Models\Application;
use App\Models\AuditLog;
use App\Models\ServiceAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Concerns\CreatesGovernanceFixtures;
use Tests\Concerns\IssuesServiceAccountTokens;
use Tests\TestCase;

/** A token only exercises the scopes its own service account is allowed to hold (audit A23). */
class ServiceAccountScopeAllowListTest extends TestCase
{
    use CreatesGovernanceFixtures, IssuesServiceAccountTokens, RefreshDatabase;

    private Application $radar;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedGovernanceBaseline();
        $this->radar = $this->makeApplication(['application_code' => 'optiradar', 'name' => 'OptiRadar']);
    }

    public function test_an_account_with_an_allow_list_cannot_use_a_scope_outside_it_even_when_the_token_carries_it(): void
    {
        [$token, $account] = $this->issueServiceAccountTokenFor($this->radar->id, ['authorization.check', 'tenant.delegate']);
        $account->update(['allowed_scopes' => ['authorization.check']]);

        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/authorization/effective-permissions', ['user_id' => (string) Str::uuid(), 'tenant_id' => (string) Str::uuid()])
            ->assertOk();

        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/v1/delegation/tenants/'.Str::uuid().'/catalog?acting_user_id='.Str::uuid())
            ->assertForbidden()
            ->assertJsonPath('error.code', 'SCOPE_NOT_ALLOWED');
    }

    public function test_an_account_registered_before_the_list_existed_keeps_every_scope(): void
    {
        [, $account] = $this->issueServiceAccountTokenFor($this->radar->id, ['authorization.check']);

        $this->assertNull($account->allowed_scopes);
        $this->assertTrue($account->allowsScope('tenant.delegate'));
    }

    public function test_the_registration_command_gives_a_new_account_the_product_scopes_only_and_can_narrow_it(): void
    {
        $file = sys_get_temp_dir().'/scopes-'.bin2hex(random_bytes(4)).'.env';
        $this->artisan("ecosystem:register-service-account optiradar --secret-file={$file}")->assertSuccessful();

        $account = ServiceAccount::query()->firstOrFail();
        $this->assertSame(ServiceAccount::PRODUCT_SCOPES, $account->allowed_scopes);
        $this->assertFalse($account->allowsScope('gateway.telematics.write'));

        $this->artisan('ecosystem:register-service-account optiradar --scopes=authorization.check,gateway.telematics.write')->assertSuccessful();
        $this->assertSame(['authorization.check', 'gateway.telematics.write'], $account->refresh()->allowed_scopes);
        $this->assertSame(1, AuditLog::query()->where('action', 'service_account.scopes_updated')->count());

        $this->artisan('ecosystem:register-service-account optiradar --scopes=not.a.scope')->assertFailed();
        $this->assertSame(['authorization.check', 'gateway.telematics.write'], $account->refresh()->allowed_scopes);
        @unlink($file);
    }

    public function test_an_operator_narrows_an_account_through_the_admin_api_and_unknown_scopes_are_refused(): void
    {
        [, $account] = $this->issueServiceAccountTokenFor($this->radar->id, ['authorization.check']);
        $operator = $this->makeOperatorWith(['cgo.service_account.manage']);
        $this->actingAs($operator, 'sanctum');

        $this->putJson("/api/v1/service-accounts/{$account->id}/scopes", ['allowed_scopes' => ['nope']])->assertUnprocessable();
        $this->putJson("/api/v1/service-accounts/{$account->id}/scopes", ['allowed_scopes' => []])->assertUnprocessable();
        $this->putJson("/api/v1/service-accounts/{$account->id}/scopes", ['allowed_scopes' => ['authorization.check', 'authorization.check']])->assertUnprocessable();

        $this->putJson("/api/v1/service-accounts/{$account->id}/scopes", ['allowed_scopes' => ['authorization.check']])
            ->assertOk()->assertJsonPath('data.allowed_scopes', ['authorization.check']);
        $this->assertSame(1, AuditLog::query()->where('action', 'service_account.scopes_updated')->count());
    }
}
