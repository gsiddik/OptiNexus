<?php

namespace Tests\Feature\Governance;

use App\Models\OidcClient;
use App\Models\ServiceAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\Concerns\CreatesGovernanceFixtures;
use Tests\TestCase;

/** Operator registration paths: PKCE always on, secrets only in a 0600 file, repeatable without side effects. */
class RegistrationCommandsTest extends TestCase
{
    use CreatesGovernanceFixtures, RefreshDatabase;

    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dir = sys_get_temp_dir().'/reg-'.bin2hex(random_bytes(4));
        mkdir($this->dir, 0700);
        $this->seedGovernanceBaseline();
        $this->makeApplication(['application_code' => 'optiradar', 'name' => 'OptiRadar']);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->dir);

        parent::tearDown();
    }

    private function read(string $file): array
    {
        return collect(explode("\n", trim((string) file_get_contents($file))))->mapWithKeys(function (string $line): array {
            [$k, $v] = explode('=', $line, 2);

            return [$k => $v];
        })->all();
    }

    public function test_an_oidc_client_is_created_with_pkce_and_the_secret_only_in_the_file(): void
    {
        $file = $this->dir.'/oidc.env';

        $this->artisan("ecosystem:register-oidc-client optiradar --redirect-uri=https://radar.example.test/api/session/openid/callback --post-logout-uri=https://radar.example.test/login --launch-url=https://radar.example.test --secret-file={$file}")
            ->assertSuccessful()
            ->doesntExpectOutputToContain($this->secretOf($file));

        $client = OidcClient::query()->firstOrFail();
        $this->assertTrue($client->require_pkce);
        $this->assertTrue($client->isConfidential());
        $this->assertSame('0600', substr(sprintf('%o', fileperms($file)), -4));
        $values = $this->read($file);
        $this->assertSame($client->client_id, $values['OIDC_CLIENT_ID']);
        $this->assertTrue($client->verifySecret($values['OIDC_CLIENT_SECRET']));
    }

    private function secretOf(string $file): string
    {
        return is_file($file) ? ($this->read($file)['OIDC_CLIENT_SECRET'] ?? 'no-secret-yet') : 'no-secret-yet';
    }

    public function test_a_repeat_run_updates_urls_keeps_the_secret_and_a_rotation_replaces_it(): void
    {
        $file = $this->dir.'/oidc.env';
        $base = 'ecosystem:register-oidc-client optiradar --redirect-uri=https://radar.example.test/cb';
        $this->artisan("{$base} --secret-file={$file}")->assertSuccessful();
        $first = $this->read($file);

        $this->artisan('ecosystem:register-oidc-client optiradar --redirect-uri=https://radar.example.test/cb2 --launch-url=https://radar.example.test')->assertSuccessful();
        $this->assertSame(1, OidcClient::query()->count());
        $client = OidcClient::query()->firstOrFail();
        $this->assertSame(['https://radar.example.test/cb2'], $client->redirect_uris);
        $this->assertTrue($client->verifySecret($first['OIDC_CLIENT_SECRET']));

        $this->artisan("ecosystem:register-oidc-client optiradar --rotate --secret-file={$file}")->assertSuccessful();
        $second = $this->read($file);
        $client->refresh();
        $this->assertNotSame($first['OIDC_CLIENT_SECRET'], $second['OIDC_CLIENT_SECRET']);
        $this->assertTrue($client->verifySecret($second['OIDC_CLIENT_SECRET']));
        $this->assertFalse($client->verifySecret($first['OIDC_CLIENT_SECRET']));
    }

    public function test_bad_input_creates_nothing_and_a_missing_secret_file_does_not_lose_a_secret(): void
    {
        $this->artisan('ecosystem:register-oidc-client unknown --redirect-uri=https://a.example.test/cb')->assertFailed();
        $this->artisan('ecosystem:register-oidc-client optiradar --redirect-uri=javascript:alert(1) --secret-file='.$this->dir.'/x')->assertFailed();
        $this->artisan('ecosystem:register-oidc-client optiradar --redirect-uri=https://a.example.test/cb#frag --secret-file='.$this->dir.'/x')->assertFailed();
        $this->artisan('ecosystem:register-oidc-client optiradar')->assertFailed();
        $this->artisan('ecosystem:register-oidc-client optiradar --redirect-uri=https://a.example.test/cb')->assertFailed();
        $this->artisan('ecosystem:register-oidc-client optiradar --redirect-uri=https://a.example.test/cb --secret-file=/nonexistent-dir/x')->assertFailed();

        $this->assertSame(0, OidcClient::query()->count());
    }

    public function test_a_service_account_is_bound_to_its_application_and_its_secret_works(): void
    {
        $file = $this->dir.'/m2m.env';

        $this->artisan("ecosystem:register-service-account optiradar --secret-file={$file}")->assertSuccessful();
        $this->artisan("ecosystem:register-service-account optiradar --secret-file={$file}")->assertSuccessful();

        $account = ServiceAccount::query()->firstOrFail();
        $this->assertSame(1, ServiceAccount::query()->count());
        $this->assertSame('optiradar', $account->application->application_code);
        $this->assertSame('0600', substr(sprintf('%o', fileperms($file)), -4));
        $values = $this->read($file);

        $this->artisan("ecosystem:register-service-account optiradar --rotate --secret-file={$file}")->assertSuccessful();
        $rotated = $this->read($file);
        $this->assertSame($values['M2M_CLIENT_ID'], $rotated['M2M_CLIENT_ID']);
        $this->assertNotSame($values['M2M_CLIENT_SECRET'], $rotated['M2M_CLIENT_SECRET']);

        $this->postJson('/api/v1/oauth/token', ['grant_type' => 'client_credentials', 'client_id' => $values['M2M_CLIENT_ID'], 'client_secret' => $values['M2M_CLIENT_SECRET'], 'scope' => 'authorization.check'])->assertStatus(401);
        $this->postJson('/api/v1/oauth/token', ['grant_type' => 'client_credentials', 'client_id' => $rotated['M2M_CLIENT_ID'], 'client_secret' => $rotated['M2M_CLIENT_SECRET'], 'scope' => 'authorization.check'])->assertOk();
    }

    public function test_the_governance_application_cannot_get_a_service_account_and_a_secret_needs_a_file(): void
    {
        $this->artisan('ecosystem:register-service-account cgo --secret-file='.$this->dir.'/x')->assertFailed();
        $this->artisan('ecosystem:register-service-account optiradar')->assertFailed();
        $this->assertSame(0, ServiceAccount::query()->count());
    }
}
