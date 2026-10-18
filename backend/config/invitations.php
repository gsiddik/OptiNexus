<?php

/*
| Onboarding by invitation (owner decision 2026-10-18). Behaviour only: no secret lives here. The mail transport itself is the
| runtime MAIL_* environment (config/mail.php); the link base is APP_URL (the public OptiNexus address).
*/
return [
    // How long an invitation link works after it was sent (hours).
    'ttl_hours' => (int) env('INVITATION_TTL_HOURS', 72),

    // Resend: minimum seconds between two sends of the same invitation, and the most resends one invitation may get.
    'resend_cooldown_seconds' => (int) env('INVITATION_RESEND_COOLDOWN_SECONDS', 60),
    'max_resends' => (int) env('INVITATION_MAX_RESENDS', 5),

    // Creation limit per tenant and hour (an Admin Tenant cannot turn Nexus into a mail cannon).
    'create_per_tenant_per_hour' => (int) env('INVITATION_CREATE_PER_TENANT_PER_HOUR', 60),

    // Wrong-password attempts allowed per invitation while an existing identity confirms itself, and the lock-out that follows.
    'confirm_max_attempts' => (int) env('INVITATION_CONFIRM_MAX_ATTEMPTS', 5),
    'confirm_lockout_seconds' => (int) env('INVITATION_CONFIRM_LOCKOUT_SECONDS', 900),

    // Minimum length of the password the invitee chooses (same floor as every other human password in OptiNexus).
    'password_min_length' => 12,

    // Direct user creation without invitation (POST /delegation/.../users) is deprecated. Setting this to false switches it off
    // per deployment; it stays on by default until the owner picks the date (DECISION_PACKAGE.md item 3).
    'allow_direct_user_creation' => (bool) env('NEXUS_DELEGATION_DIRECT_USERS', true),

    'locales' => ['en', 'id'],
];
