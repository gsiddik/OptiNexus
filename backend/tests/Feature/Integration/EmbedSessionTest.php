<?php

namespace Tests\Feature\Integration;

use App\Models\Application;
use App\Models\AuditLog;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Concerns\CreatesGovernanceFixtures;
use Tests\Concerns\CreatesIntegrationFixtures;
use Tests\TestCase;

/** K3/K5: OptiDeli opens a short-lived, read-only OptiRadar embed session for its tenant through Nexus. */
class EmbedSessionTest extends TestCase
{
    use CreatesGovernanceFixtures, CreatesIntegrationFixtures, RefreshDatabase;

    private Application $deli;

    private Application $radar;

    protected function setUp(): void
    {
        parent::setUp();

        config(['gateway.optiradar.base_url' => 'https://radar.test', 'gateway.optiradar.embed_token' => 'admin-token']);
        $this->deli = $this->makeApplication(['application_code' => 'optideli', 'name' => 'OptiDeli']);
        $this->radar = $this->makeApplication(['application_code' => 'optiradar', 'name' => 'OptiRadar', 'frontend_url' => 'https://radar.test']);
    }

    private function entitled(Tenant $tenant, bool $deli = true, bool $radar = true): void
    {
        foreach ([[$deli, $this->deli], [$radar, $this->radar]] as [$yes, $app]) {
            if ($yes) {
                $tenant->applications()->attach($app->id, ['id' => (string) Str::uuid(), 'status' => 'ACTIVE']);
                $this->makeActiveApplicationSubscription($tenant, $app);
            }
        }
    }

    private function open(string $token, Tenant $tenant, array $extra = [])
    {
        return $this->withToken($token)->postJson('/api/v1/embed/sessions', ['tenant_id' => $tenant->id, 'target' => 'optiradar', ...$extra]);
    }

    public function test_an_entitled_tenant_gets_a_short_lived_embed_url(): void
    {
        $tenant = $this->makeTenant();
        $this->entitled($tenant);
        Http::fake(['radar.test/api/optinexus/embed' => Http::response(['token' => 'tok-123', 'expiresAt' => now()->addMinutes(15)->toIso8601String()])]);

        $response = $this->open($this->serviceToken($this->deli, ['embed.create'], $tenant), $tenant, ['ttl_seconds' => 600])->assertStatus(201);

        $this->assertSame(600, $response->json('data.ttl_seconds'));
        $this->assertStringStartsWith('https://radar.test/?embed=1&token=tok-123', $response->json('data.url'));
        Http::assertSent(fn ($r) => $r->hasHeader('Authorization', 'Bearer admin-token') && $r['tenantId'] === $tenant->id && (int) $r['ttl'] === 600);
        $this->assertSame(1, AuditLog::query()->where('action', 'embed.session_opened')->where('tenant_id', $tenant->id)->count());
    }

    public function test_the_ttl_is_capped_at_fifteen_minutes(): void
    {
        $tenant = $this->makeTenant();
        $this->entitled($tenant);
        Http::fake(['*' => Http::response(['token' => 't'])]);

        $this->open($this->serviceToken($this->deli, ['embed.create'], $tenant), $tenant, ['ttl_seconds' => 3600])->assertStatus(422);
    }

    public function test_a_tenant_without_a_radar_subscription_is_refused(): void
    {
        $tenant = $this->makeTenant();
        $this->entitled($tenant, radar: false);
        Http::fake();

        $this->open($this->serviceToken($this->deli, ['embed.create'], $tenant), $tenant)->assertStatus(403)->assertJsonPath('error.code', 'EMBED_TARGET_NOT_ENTITLED');
        Http::assertNothingSent();
    }

    public function test_a_service_account_bound_to_another_tenant_is_refused(): void
    {
        $mine = $this->makeTenant();
        $other = $this->makeTenant();
        $this->entitled($other);
        Http::fake();

        $this->open($this->serviceToken($this->deli, ['embed.create'], $mine), $other)->assertStatus(403)->assertJsonPath('error.code', 'TENANT_MISMATCH');
        Http::assertNothingSent();
    }

    public function test_a_token_without_the_embed_scope_is_refused(): void
    {
        $tenant = $this->makeTenant();
        $this->entitled($tenant);

        $this->open($this->serviceToken($this->deli, ['commercial.read'], $tenant), $tenant)->assertStatus(403);
    }

    public function test_radar_failing_gives_a_502_and_no_url(): void
    {
        $tenant = $this->makeTenant();
        $this->entitled($tenant);
        Http::fake(['*' => Http::response('no', 500)]);

        $this->open($this->serviceToken($this->deli, ['embed.create'], $tenant), $tenant)->assertStatus(502)->assertJsonPath('error.code', 'EMBED_UNAVAILABLE');
    }

    public function test_the_published_radar_manifest_imports_cleanly(): void
    {
        $this->actingAsSuperAdmin();
        $manifest = json_decode((string) file_get_contents(base_path('../docs/ecosystem/manifests/optiradar.manifest.json')), true, flags: JSON_THROW_ON_ERROR);
        unset($manifest['application']);

        $this->postJson("/api/v1/applications/{$this->radar->id}/manifest", $manifest + ['dry_run' => true])->assertOk()
            ->assertJsonPath('data.capabilities.created', 7)
            ->assertJsonPath('data.permissions.created', 4);
    }
}
