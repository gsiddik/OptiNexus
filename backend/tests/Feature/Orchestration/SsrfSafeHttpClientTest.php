<?php

namespace Tests\Feature\Orchestration;

use App\Services\Integration\SsrfProtectedException;
use App\Services\Integration\SsrfSafeHttpClient;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Deterministic SSRF checks: a fixed resolver stands in for DNS, so nothing here needs a network. The one test that resolves a real public
 * name lives in OrchestrationSecurityTest (group `network`) and is the only evidence of live resolution.
 */
class SsrfSafeHttpClientTest extends TestCase
{
    /** @param array<string, string> $dns name => the address the resolver answers (a name that is not listed stays unresolved, like gethostbyname) */
    private function client(array $dns = []): SsrfSafeHttpClient
    {
        return new class($dns) extends SsrfSafeHttpClient
        {
            /** @param array<string, string> $dns */
            public function __construct(private array $dns) {}

            protected function resolveHost(string $host): string
            {
                return $this->dns[$host] ?? $host;
            }
        };
    }

    #[DataProvider('blockedAnswers')]
    public function test_a_public_looking_name_that_resolves_to_a_private_or_reserved_address_is_refused(string $address): void
    {
        $this->expectException(SsrfProtectedException::class);
        $this->expectExceptionMessage('blocked private/reserved address');
        $this->client(['hooks.example.com' => $address])->validateUrl('https://hooks.example.com/in');
    }

    public static function blockedAnswers(): array
    {
        return [
            'loopback' => ['127.0.0.1'],
            'loopback range end' => ['127.255.255.254'],
            'class A private' => ['10.1.2.3'],
            'class B private start' => ['172.16.0.1'],
            'class B private end' => ['172.31.255.255'],
            'class C private' => ['192.168.0.9'],
            'link-local metadata service' => ['169.254.169.254'],
            'link-local range end' => ['169.254.255.255'],
            'this-network' => ['0.0.0.0'],
            'this-network range' => ['0.1.2.3'],
        ];
    }

    #[DataProvider('allowedAnswers')]
    public function test_addresses_just_outside_the_blocked_ranges_are_allowed(string $address): void
    {
        [$host, $port, $ip] = $this->client(['hooks.example.com' => $address])->validateUrl('https://hooks.example.com/in');

        $this->assertSame('hooks.example.com', $host);
        $this->assertSame(443, $port);
        $this->assertSame($address, $ip);
    }

    public static function allowedAnswers(): array
    {
        return [
            'public' => ['93.184.216.34'],
            'just below 10/8' => ['9.255.255.255'],
            'just above 10/8' => ['11.0.0.1'],
            'just below 172.16/12' => ['172.15.255.255'],
            'just above 172.16/12' => ['172.32.0.1'],
            'just below 192.168/16' => ['192.167.255.255'],
            'just below link-local' => ['169.253.255.255'],
            'just above link-local' => ['169.255.0.1'],
        ];
    }

    public function test_the_port_follows_the_scheme_unless_the_url_names_one(): void
    {
        $client = $this->client(['hooks.example.com' => '93.184.216.34']);

        $this->assertSame(80, $client->validateUrl('http://hooks.example.com/in')[1]);
        $this->assertSame(443, $client->validateUrl('https://hooks.example.com/in')[1]);
        $this->assertSame(8443, $client->validateUrl('https://hooks.example.com:8443/in')[1]);
    }

    public function test_a_name_that_does_not_resolve_is_refused(): void
    {
        $this->expectException(SsrfProtectedException::class);
        $this->expectExceptionMessage('could not be resolved');
        $this->client()->validateUrl('https://nothing-here.example.com/in');
    }

    #[DataProvider('refusedUrls')]
    public function test_other_forms_of_the_same_target_are_refused(string $url): void
    {
        $this->expectException(SsrfProtectedException::class);
        $this->client()->validateUrl($url);
    }

    public static function refusedUrls(): array
    {
        return [
            'localhost in capitals' => ['http://LOCALHOST/'],
            'IPv6 loopback literal' => ['http://[::1]/'],
            'IPv6 unique-local literal' => ['http://[fd00::1]/'],
            'IPv6 link-local literal' => ['http://[fe80::1]/'],
            'IPv4-mapped IPv6 loopback' => ['http://[::ffff:127.0.0.1]/'],
            'no host' => ['http:///path'],
            'file scheme' => ['file:///etc/passwd'],
            'gopher scheme' => ['gopher://example.com/'],
            'scheme-less' => ['//example.com/'],
        ];
    }

    public function test_the_connection_is_pinned_to_the_validated_address_and_redirects_are_not_followed(): void
    {
        $seen = [];
        Http::fake(function ($request, $options = []) use (&$seen) {
            $seen[] = ['url' => (string) $request->url(), 'options' => $options];

            return Http::response('', 302, ['Location' => 'http://127.0.0.1/admin']);
        });

        $response = $this->client(['hooks.example.com' => '93.184.216.34'])->send('POST', 'https://hooks.example.com/in', ['json' => ['a' => 1]]);

        $this->assertSame(302, $response->status(), 'the redirect answer is returned as it is');
        $this->assertCount(1, $seen, 'the Location header is never fetched');
        $this->assertFalse($seen[0]['options']['allow_redirects']);
        $this->assertSame(['hooks.example.com:443:93.184.216.34'], $seen[0]['options']['curl'][CURLOPT_RESOLVE]);
    }

    public function test_nothing_is_sent_when_the_target_is_refused(): void
    {
        Http::fake();

        try {
            $this->client(['hooks.example.com' => '10.0.0.5'])->send('GET', 'https://hooks.example.com/in');
            $this->fail('A private target must be refused before any request.');
        } catch (SsrfProtectedException) {
            Http::assertNothingSent();
        }
    }
}
