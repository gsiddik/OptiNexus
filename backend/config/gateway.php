<?php

return [
    /*
    | OptiRadar connector. The base URL and token are operator
    | configuration, never user input, so private-network addresses are allowed
    | here (unlike tenant-defined integration endpoints, which go through the
    | SSRF-safe client). The token is a OptiRadar API token of a read-only user
    | that can see every tenant group.
    */
    'optiradar' => [
        'enabled' => (bool) env('OPTIRADAR_SYNC_ENABLED', false),
        'application_code' => env('OPTIRADAR_APPLICATION_CODE', 'optiradar'),
        'base_url' => rtrim((string) env('OPTIRADAR_BASE_URL', ''), '/'),
        'token' => env('OPTIRADAR_API_TOKEN'),
        'timeout_seconds' => (int) env('OPTIRADAR_TIMEOUT', 20),
        // Embed sessions (K3/K5): the token of an OptiRadar administrator, used only to ask OptiRadar for a short-lived,
        // read-only token limited to one tenant's group. Separate from the read-only sync token above.
        'embed_token' => env('OPTIRADAR_EMBED_TOKEN'),
        'embed_max_ttl_seconds' => 900,
        // One OptiRadar read (groups, devices, positions) is shared by every caller for this many seconds.
        'positions_cache_seconds' => (int) env('OPTIRADAR_POSITIONS_CACHE', 10),
        // Group attribute that carries the OptiNexus tenant id.
        'tenant_group_attribute' => 'optinexusTenantId',
    ],
];
