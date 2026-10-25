<?php

/*
| Operational e-mail (customer/tenant/user lifecycle, invoices, pricing). Behaviour only: the transport is the runtime MAIL_*
| environment (config/mail.php), the provider guide is docs/OPERATIONAL_EMAIL.md. No secret is committed here.
*/
return [
    // Language of operational e-mails (lang/{id,en}/operational_mail.php). Recipients have no stored locale yet.
    'mail_locale' => env('NOTIFICATION_MAIL_LOCALE', 'id'),

    // Public address of the OptiNexus console (the React UI), used for the links in operational e-mails. Falls back to APP_URL
    // when the console is served from the same origin.
    'console_url' => env('NEXUS_CONSOLE_URL', env('APP_URL', 'http://localhost')),

    // Invoices: a reminder this many days before the due date (0 disables it); overdue marking runs regardless.
    'invoice_due_reminder_days' => (int) env('INVOICE_DUE_REMINDER_DAYS', 3),

    // Postmark bounce/spam-complaint/subscription-change webhook (POST /api/v1/webhooks/postmark). The endpoint answers 404 until
    // both values are set; Postmark sends them as HTTP Basic credentials embedded in the webhook URL.
    'postmark_webhook' => [
        'username' => env('POSTMARK_WEBHOOK_USERNAME'),
        'password' => env('POSTMARK_WEBHOOK_PASSWORD'),
    ],
];
