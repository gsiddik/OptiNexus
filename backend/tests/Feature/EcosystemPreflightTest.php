<?php

namespace Tests\Feature;

use Laravel\Passport\Passport;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class EcosystemPreflightTest extends TestCase
{
    public function test_an_empty_oidc_issuer_falls_back_to_the_application_url(): void
    {
        // A copied .env.example carries `OIDC_ISSUER=` (empty); the config must not keep the empty string.
        $_ENV['OIDC_ISSUER'] = $_SERVER['OIDC_ISSUER'] = '';
        $_ENV['APP_URL'] = $_SERVER['APP_URL'] = 'https://nexus.example.test/';

        try {
            $this->assertSame('https://nexus.example.test', (require base_path('config/oidc.php'))['issuer']);
        } finally {
            unset($_ENV['OIDC_ISSUER'], $_SERVER['OIDC_ISSUER']);
        }
    }

    public function test_a_usable_configuration_passes(): void
    {
        config(['oidc.issuer' => 'https://nexus.example.test', 'app.key' => 'base64:'.base64_encode(random_bytes(32))]);

        $this->artisan('ecosystem:preflight')->assertSuccessful()->expectsOutputToContain('https://nexus.example.test');
    }

    #[DataProvider('unusableIssuers')]
    public function test_an_unusable_issuer_is_refused_with_the_reason(string $issuer, string $reason): void
    {
        config(['oidc.issuer' => $issuer]);

        $this->artisan('ecosystem:preflight')->assertFailed()->expectsOutputToContain($reason);
    }

    /** @return array<string, array{0: string, 1: string}> */
    public static function unusableIssuers(): array
    {
        return [
            'empty' => ['', 'empty or not an http(s) URL'],
            'no scheme' => ['nexus.example.test', 'empty or not an http(s) URL'],
            'path' => ['https://nexus.example.test/app', 'origin only'],
        ];
    }

    public function test_sqlite_is_refused_because_the_migrations_are_postgresql_specific(): void
    {
        config(['database.default' => 'sqlite', 'database.connections.sqlite.driver' => 'sqlite']);

        $this->artisan('ecosystem:preflight --skip-database')->assertFailed()->expectsOutputToContain("'sqlite' is not supported");
    }

    public function test_production_refuses_debug_mode_and_a_sync_queue(): void
    {
        $this->app['env'] = 'production';
        config(['oidc.issuer' => 'https://nexus.example.test', 'app.debug' => true, 'queue.default' => 'sync']);

        $this->artisan('ecosystem:preflight')->assertFailed()->expectsOutputToContain('APP_DEBUG')->expectsOutputToContain('QUEUE_CONNECTION');
    }

    public function test_a_local_issuer_is_a_warning_alone_and_a_failure_when_other_products_need_it(): void
    {
        config(['oidc.issuer' => 'http://localhost:8100', 'app.key' => 'base64:'.base64_encode(random_bytes(32)), 'oidc.require_public_issuer' => false]);
        $this->artisan('ecosystem:preflight')->assertSuccessful()->expectsOutputToContain('only works for this machine');

        config(['oidc.require_public_issuer' => true]);
        $this->artisan('ecosystem:preflight')->assertFailed()->expectsOutputToContain('other containers cannot reach it');
    }

    public function test_the_signing_key_check_can_wait_for_the_bootstrap_to_create_it(): void
    {
        config(['oidc.issuer' => 'https://nexus.example.test', 'oidc.private_key' => null, 'oidc.private_key_path' => '/nonexistent/key.pem', 'app.key' => 'base64:'.base64_encode(random_bytes(32))]);
        Passport::loadKeysFrom('/nonexistent');

        $this->artisan('ecosystem:preflight --skip-keys')->assertSuccessful();
    }

    public function test_a_missing_signing_key_is_reported_without_printing_secrets(): void
    {
        config(['oidc.private_key' => null, 'oidc.private_key_path' => '/nonexistent/key.pem', 'app.key' => 'base64:'.base64_encode(random_bytes(32))]);
        Passport::loadKeysFrom('/nonexistent');

        $this->artisan('ecosystem:preflight')->assertFailed()->expectsOutputToContain('No OIDC signing key configured');
    }
}
