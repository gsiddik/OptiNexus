<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\CreatesGovernanceFixtures;
use Tests\Concerns\IssuesServiceAccountTokens;
use Tests\TestCase;

class MachineClientQuotaTest extends TestCase
{
    use CreatesGovernanceFixtures, IssuesServiceAccountTokens, RefreshDatabase;

    private function check(string $token): TestResponse
    {
        return $this->withHeader('Authorization', "Bearer {$token}")->postJson('/api/v1/authorization/check', [
            'user_id' => (string) Str::uuid(), 'application_code' => 'x', 'permission' => 'x.y.z',
        ]);
    }

    public function test_requests_beyond_the_old_per_address_limit_succeed_for_an_authenticated_client(): void
    {
        [$token] = $this->issueServiceAccountTokenFor(null);

        for ($i = 0; $i < 130; $i++) {
            // An unknown user is a real answer (allowed: false); the point is that no request is throttled.
            $this->check($token)->assertOk();
        }
    }

    public function test_the_quota_is_per_client_so_a_shared_address_does_not_pool_two_products(): void
    {
        config(['m2m.rate_per_minute' => 3]);
        [$first] = $this->issueServiceAccountTokenFor(null, name: 'first');
        [$second] = $this->issueServiceAccountTokenFor(null, name: 'second');

        for ($i = 0; $i < 3; $i++) {
            $this->check($first)->assertOk()->assertHeader('X-RateLimit-Limit', '3');
        }

        $this->check($first)->assertStatus(429)
            ->assertJsonPath('error.code', 'RATE_LIMITED')
            ->assertHeader('X-RateLimit-Remaining', '0');
        $this->assertGreaterThan(0, (int) $this->check($first)->headers->get('Retry-After'));

        $this->check($second)->assertOk();
    }

    public function test_a_scope_with_bulk_traffic_gets_its_own_higher_ceiling(): void
    {
        config(['m2m.rate_per_minute' => 2, 'm2m.scope_rate_per_minute' => ['authorization.check' => 4]]);
        [$token] = $this->issueServiceAccountTokenFor(null);

        for ($i = 0; $i < 4; $i++) {
            $this->check($token)->assertOk();
        }
        $this->check($token)->assertStatus(429);
    }

    public function test_bad_tokens_are_still_throttled_per_source_address(): void
    {
        config(['m2m.failed_auth_per_minute_per_ip' => 3]);

        for ($i = 0; $i < 3; $i++) {
            $this->check('not-a-token')->assertUnauthorized();
        }

        $this->check('not-a-token')->assertStatus(429)->assertJsonPath('error.code', 'RATE_LIMITED')->assertHeader('Retry-After');
    }

    public function test_a_flood_of_bad_tokens_does_not_lock_out_a_valid_client_on_the_same_address_until_the_failure_limit_trips(): void
    {
        [$token] = $this->issueServiceAccountTokenFor(null);
        config(['m2m.failed_auth_per_minute_per_ip' => 5]);

        for ($i = 0; $i < 4; $i++) {
            $this->check('not-a-token')->assertUnauthorized();
        }

        $this->check($token)->assertOk();
    }

    public function test_interactive_routes_keep_the_per_address_limiter_and_report_when_to_retry(): void
    {
        $this->actingAsSuperAdmin();

        for ($i = 0; $i < 120; $i++) {
            $this->getJson('/api/v1/applications')->assertOk();
        }

        $response = $this->getJson('/api/v1/applications')->assertStatus(429)->assertJsonPath('error.code', 'RATE_LIMITED');
        $this->assertGreaterThan(0, (int) $response->headers->get('Retry-After'));
    }
}
