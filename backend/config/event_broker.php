<?php

return [
    /*
    | Event broker webhooks (docs/integration/INTEGRATION_GUIDE.md §4b). Endpoints must use https; plain http is accepted only
    | when this is true (local development). Redirects are never followed.
    */
    'allow_http' => (bool) env('EVENT_WEBHOOK_ALLOW_HTTP', false),
    'timeout_seconds' => (int) env('EVENT_WEBHOOK_TIMEOUT', 5),
    // Attempts before a delivery is FAILED (dead letter, retried by an operator), and the waits between them in seconds.
    'tries' => 8,
    'backoff' => [10, 30, 120, 600, 1800, 3600, 3600],
];
