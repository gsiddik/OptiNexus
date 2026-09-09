<?php

namespace App\Services\Integration;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Centralized outbound HTTP client for Integrations. Blocks obvious SSRF
 * vectors: non-http(s) schemes, localhost, and private/reserved IP ranges
 * - resolved BEFORE the request and pinned via CURLOPT_RESOLVE so the
 * actual TCP connection targets the same validated address (closing the
 * DNS-rebinding TOCTOU gap a naive "resolve then let the client
 * re-resolve" check would leave open). Redirects are disabled outright
 * rather than re-validated, per "redirect controls".
 */
class SsrfSafeHttpClient
{
    private const BLOCKED_V4_RANGES = [
        '127.0.0.0/8', '10.0.0.0/8', '172.16.0.0/12', '192.168.0.0/16', '169.254.0.0/16', '0.0.0.0/8',
    ];

    /**
     * @return array{0: string, 1: int, 2: string} [host, port, resolvedIp]
     */
    public function validateUrl(string $url): array
    {
        $parts = parse_url($url);
        $scheme = strtolower($parts['scheme'] ?? '');

        if (! in_array($scheme, ['http', 'https'], true)) {
            throw new SsrfProtectedException('Only http/https URLs are allowed for integration endpoints.');
        }

        $host = $parts['host'] ?? null;
        if (! $host) {
            throw new SsrfProtectedException('The integration URL has no host.');
        }

        if (in_array(strtolower($host), ['localhost'], true)) {
            throw new SsrfProtectedException('localhost is not an allowed integration target.');
        }

        $ip = filter_var($host, FILTER_VALIDATE_IP) ? $host : gethostbyname($host);
        if (! filter_var($ip, FILTER_VALIDATE_IP)) {
            throw new SsrfProtectedException('The integration host could not be resolved.');
        }

        if ($this->isBlockedIp($ip)) {
            throw new SsrfProtectedException('The integration target resolves to a blocked private/reserved address.');
        }

        $port = $parts['port'] ?? ($scheme === 'https' ? 443 : 80);

        return [$host, $port, $ip];
    }

    public function send(string $method, string $url, array $options = []): Response
    {
        [$host, $port, $ip] = $this->validateUrl($url);

        return Http::withOptions([
            'allow_redirects' => false,
            'curl' => [CURLOPT_RESOLVE => ["{$host}:{$port}:{$ip}"]],
        ])->send($method, $url, $options);
    }

    private function isBlockedIp(string $ip): bool
    {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            return $this->isBlockedIpv6($ip);
        }

        foreach (self::BLOCKED_V4_RANGES as $range) {
            if ($this->ipv4InRange($ip, $range)) {
                return true;
            }
        }

        return false;
    }

    private function isBlockedIpv6(string $ip): bool
    {
        $bin = inet_pton($ip);
        if ($bin === inet_pton('::1')) {
            return true;
        }

        $first = ord($bin[0]);
        if (($first & 0xfe) === 0xfc) { // fc00::/7 (unique local)
            return true;
        }

        return $first === 0xfe && (ord($bin[1]) & 0xc0) === 0x80; // fe80::/10 (link-local)
    }

    private function ipv4InRange(string $ip, string $range): bool
    {
        [$subnet, $bits] = explode('/', $range);
        $mask = -1 << (32 - (int) $bits);

        return (ip2long($ip) & $mask) === (ip2long($subnet) & $mask);
    }
}
