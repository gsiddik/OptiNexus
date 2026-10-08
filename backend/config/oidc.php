<?php

return [
    /*
    | Issuer identifier published in the discovery document and stamped into
    | every id_token. Must be the public base URL of OptiNexus, no trailing slash.
    */
    'issuer' => rtrim((string) env('OIDC_ISSUER', env('APP_URL', 'http://localhost')), '/'),

    /*
    | RSA private key (PEM) used to sign id_tokens with RS256. When empty,
    | OptiNexus reuses Passport's private key file (storage/oauth-private.key,
    | created by `php artisan passport:keys`), so a fresh install needs no
    | extra key material.
    */
    'private_key' => env('OIDC_PRIVATE_KEY'),
    'private_key_path' => env('OIDC_PRIVATE_KEY_PATH'),

    'code_ttl_seconds' => (int) env('OIDC_CODE_TTL', 300),
    'access_token_ttl_seconds' => (int) env('OIDC_ACCESS_TOKEN_TTL', 3600),
    'id_token_ttl_seconds' => (int) env('OIDC_ID_TOKEN_TTL', 3600),

    'scopes_supported' => ['openid', 'profile', 'email', 'tenant', 'groups'],
];
