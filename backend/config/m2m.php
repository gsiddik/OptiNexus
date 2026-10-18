<?php

/*
|--------------------------------------------------------------------------
| Machine-to-machine (service account) request quotas
|--------------------------------------------------------------------------
|
| Routes behind the `service_account:` middleware are throttled per authenticated OAuth client, never per source
| address: every product server behind one proxy or NAT shares an address, and a cold permission refresh of one product
| must not lock another one out. Requests that do not authenticate are counted per source address instead, so a client
| that is spraying bad tokens still hits a limit. Interactive (human) routes keep the `api` limiter in AppServiceProvider.
|
*/

return [
    // Authenticated requests per OAuth client and minute (HTTP 429 + Retry-After beyond it).
    'rate_per_minute' => max(1, (int) env('M2M_RATE_PER_MINUTE', 600)),

    // Higher ceilings for scopes that carry bulk traffic; a route's ceiling is the largest value among its required scopes.
    'scope_rate_per_minute' => [
        'gateway.telematics.write' => max(1, (int) env('M2M_TELEMATICS_RATE_PER_MINUTE', 3000)),
        'event.write' => max(1, (int) env('M2M_EVENT_RATE_PER_MINUTE', 1200)),
    ],

    // Longest a caller may reuse an answer of POST /authorization/effective-permissions (seconds); sent as `ttl_seconds`.
    'permission_ttl_seconds' => max(1, min(300, (int) env('M2M_PERMISSION_TTL', 300))),

    // Failed authentications (missing/invalid token, wrong scope, revoked service account) per source address and minute.
    'failed_auth_per_minute_per_ip' => max(1, (int) env('M2M_FAILED_AUTH_PER_MINUTE', 60)),
];
