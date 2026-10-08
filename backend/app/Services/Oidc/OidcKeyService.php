<?php

namespace App\Services\Oidc;

use Laravel\Passport\Passport;
use OpenSSLAsymmetricKey;
use RuntimeException;

/**
 * RS256 signing for id_tokens and the matching public JWKS. Built on ext-openssl
 * directly so the token format is fully under our control (Passport does not
 * issue OpenID Connect id_tokens).
 */
class OidcKeyService
{
    private ?OpenSSLAsymmetricKey $key = null;

    /**
     * @param  string  $type  JOSE `typ` header: "JWT" for id_tokens, "logout+jwt" for logout tokens, so one kind of token can never be mistaken for the other.
     */
    public function sign(array $claims, string $type = 'JWT'): string
    {
        $header = ['alg' => 'RS256', 'typ' => $type, 'kid' => $this->keyId()];
        $input = self::base64Url(json_encode($header, JSON_UNESCAPED_SLASHES)).'.'
            .self::base64Url(json_encode($claims, JSON_UNESCAPED_SLASHES));

        if (! openssl_sign($input, $signature, $this->privateKey(), OPENSSL_ALGO_SHA256)) {
            throw new RuntimeException('Unable to sign the id_token.');
        }

        return $input.'.'.self::base64Url($signature);
    }

    /**
     * Verifies a token this service signed and returns its claims, or null.
     * Used by tests and by the gateway to validate OptiNexus-issued JWTs.
     */
    public function verify(string $jwt): ?array
    {
        $parts = explode('.', $jwt);
        if (count($parts) !== 3) {
            return null;
        }

        [$header, $payload, $signature] = $parts;
        $publicKey = openssl_pkey_get_public($this->publicKeyPem());
        $ok = openssl_verify("{$header}.{$payload}", self::base64UrlDecode($signature), $publicKey, OPENSSL_ALGO_SHA256);

        return $ok === 1 ? json_decode(self::base64UrlDecode($payload), true) : null;
    }

    public function jwks(): array
    {
        $details = openssl_pkey_get_details($this->privateKey());

        return ['keys' => [[
            'kty' => 'RSA',
            'use' => 'sig',
            'alg' => 'RS256',
            'kid' => $this->keyId(),
            'n' => self::base64Url($details['rsa']['n']),
            'e' => self::base64Url($details['rsa']['e']),
        ]]];
    }

    public function keyId(): string
    {
        $details = openssl_pkey_get_details($this->privateKey());

        // RFC 7638 JWK thumbprint, so the kid changes whenever the key does.
        $jwk = json_encode([
            'e' => self::base64Url($details['rsa']['e']),
            'kty' => 'RSA',
            'n' => self::base64Url($details['rsa']['n']),
        ], JSON_UNESCAPED_SLASHES);

        return self::base64Url(hash('sha256', $jwk, true));
    }

    private function publicKeyPem(): string
    {
        return openssl_pkey_get_details($this->privateKey())['key'];
    }

    private function privateKey(): OpenSSLAsymmetricKey
    {
        if ($this->key) {
            return $this->key;
        }

        $pem = config('oidc.private_key');
        if (! $pem) {
            $path = config('oidc.private_key_path') ?: Passport::keyPath('oauth-private.key');
            $pem = is_readable($path) ? file_get_contents($path) : null;
        }

        if (! $pem) {
            throw new RuntimeException('No OIDC signing key configured. Set OIDC_PRIVATE_KEY or run `php artisan passport:keys`.');
        }

        $key = openssl_pkey_get_private(str_replace('\n', "\n", $pem));
        if (! $key || openssl_pkey_get_details($key)['type'] !== OPENSSL_KEYTYPE_RSA) {
            throw new RuntimeException('The OIDC signing key must be an RSA private key.');
        }

        return $this->key = $key;
    }

    public static function base64Url(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    public static function base64UrlDecode(string $data): string
    {
        return (string) base64_decode(strtr($data, '-_', '+/'), true);
    }
}
