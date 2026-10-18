<?php

/*
| First Admin Tenant without e-mail (owner decision 2026-10-18, second batch). Behaviour only: no secret lives here. The temporary
| password is generated at runtime, shown once to the operator and stored only as a hash.
*/
return [
    // How long a temporary password stays usable after it was issued (hours). The operator hands it over out of band, so it is
    // longer than an e-mailed link would be; an expired one is replaced by the operator, never extended.
    'temporary_password_ttl_hours' => (int) env('NEXUS_TEMP_PASSWORD_TTL_HOURS', 48),

    // Characters of the generated temporary password (letters and digits without look-alikes, shown in groups of five).
    'temporary_password_length' => 20,

    // Wrong temporary-password attempts allowed per account, and the lock-out that follows (the sign-in route has its own per-address throttle).
    'max_attempts' => (int) env('NEXUS_TEMP_PASSWORD_MAX_ATTEMPTS', 5),
    'lockout_seconds' => (int) env('NEXUS_TEMP_PASSWORD_LOCKOUT_SECONDS', 900),

    // Minimum length of the password the new Admin Tenant chooses (same floor as every other human password in OptiNexus).
    'password_min_length' => 12,

    // Shortest and longest reason an operator may give for a manual e-mail verification.
    'reason_min_length' => 10,
    'reason_max_length' => 500,

    'locales' => ['en', 'id'],
];
