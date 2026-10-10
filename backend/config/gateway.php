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
        // Group attribute that carries the OptiNexus tenant id.
        'tenant_group_attribute' => 'optinexusTenantId',
    ],
];
