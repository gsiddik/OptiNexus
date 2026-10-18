<?php

/*
| Central seat-limit enforcement (owner decision 2026-10-18; contract: GOVERNANCE_CONTRACTS.md section 2d). Behaviour only: the limit
| itself is the tenant's `user_limit` entitlement, never a number in this file. No secret lives here.
*/
return [
    // The entitlement key that bounds how many people may hold access to one application in one tenant. An application is
    // seat-controlled only when its manifest declares this key (`entitlement_limit_keys`); nothing is enforced for one that does not.
    'limit_key' => 'user_limit',

    // How long a request waits for the seat lock of one tenant-application pair before it answers "retry" (seconds).
    'lock_wait_seconds' => (float) env('SEATS_LOCK_WAIT_SECONDS', 5),

    // The Retry-After a caller gets when the seat lock stayed busy or the entitlement source could not be read (seconds).
    'retry_after_seconds' => (int) env('SEATS_RETRY_AFTER_SECONDS', 2),
];
