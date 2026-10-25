<?php

return [
    'brand' => 'OptiNexus',
    'greeting' => 'Hello :name,',
    'action' => 'Open in OptiNexus',
    'footer' => 'Automated message from OptiNexus. You receive it as a registered tenant admin or an authorized OptiNexus operator. Please do not reply to this e-mail.',

    'statuses' => [
        'ACTIVE' => 'Active', 'SUSPENDED' => 'Suspended', 'TERMINATED' => 'Terminated', 'DISABLED' => 'Disabled',
        'DRAFT' => 'Draft', 'ISSUED' => 'Issued', 'PARTIALLY_PAID' => 'Partially paid', 'PAID' => 'Paid',
        'OVERDUE' => 'Overdue', 'VOID' => 'Void', 'CANCELLED' => 'Cancelled',
    ],

    'facts' => [
        'customer' => 'Customer', 'customer_code' => 'Customer code', 'tenant' => 'Tenant', 'user' => 'User', 'email' => 'E-mail',
        'previous_status' => 'Previous status', 'new_status' => 'New status', 'invoice_number' => 'Invoice number',
        'total' => 'Total', 'balance_due' => 'Balance due', 'due_date' => 'Due date', 'issue_date' => 'Issue date',
        'item' => 'Plan/Add-on', 'price_type' => 'Price type', 'currency' => 'Currency', 'unit_amount' => 'Unit amount',
        'tiered' => 'See pricing tiers', 'effective_from' => 'Effective from', 'effective_until' => 'Effective until',
    ],

    'customer' => [
        'subject' => 'Customer :name is now :status',
        'intro' => 'Customer :name (:code) is now :status.',
        'follow_up' => 'While this status lasts, the tenants of this customer cannot use their applications. Contact your OptiNexus administrator for follow-up.',
    ],
    'tenant' => [
        'subject' => 'Tenant :name is now :status',
        'intro' => 'Tenant :name is now :status.',
        'follow_up' => 'While this status lasts, the users of this tenant cannot access their applications. Contact your OptiNexus administrator for follow-up.',
    ],
    'user' => [
        'subject' => 'User :name is now :status',
        'intro' => 'The account :name (:email) is now :status.',
        'follow_up' => 'This user cannot sign in to the applications until the account is reactivated. Review the account access in OptiNexus.',
    ],
    'invoice' => [
        'issued_subject' => 'New invoice :number needs follow-up',
        'issued_intro' => 'Invoice :number for tenant :tenant has been issued.',
        'issued_follow_up' => 'Please review this invoice and settle it before :due.',
        'status_subject' => 'Invoice :number status: :status',
        'status_intro' => 'Invoice :number for tenant :tenant changed from :old to :new.',
        'follow_up' => [
            'OVERDUE' => 'This invoice is past its due date. Please settle the balance due promptly to avoid service interruption.',
            'PARTIALLY_PAID' => 'A partial payment was received. The balance due must still be paid before the due date.',
            'PAID' => 'The payment was received in full. No further action is needed.',
            'VOID' => 'This invoice is no longer valid. If a replacement invoice is issued, you will receive a separate notice.',
            'CANCELLED' => 'This invoice is no longer valid. If a replacement invoice is issued, you will receive a separate notice.',
            'default' => 'Please review this invoice in OptiNexus or in your tenant application.',
        ],
        'reminder_subject' => 'Reminder: invoice :number is due :due',
        'reminder_intro' => 'Invoice :number for tenant :tenant is due on :due.',
        'reminder_follow_up' => 'Please settle the balance due before that date. Ignore this reminder if the payment is already on its way.',
    ],
    'price' => [
        'subject' => 'Price change for :item',
        'intro' => 'The price of :item used by tenant :tenant has changed.',
        'follow_up' => 'Invoices already issued remain unchanged. Review the new price and follow up with your administrator.',
    ],
];
