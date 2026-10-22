# Operational email and tenant account changes

OptiNexus uses Laravel SMTP with a Postmark transactional stream. The provider account, credentials and verified sending domain must be configured by the deployment owner; source code contains no live credentials. Existing SMTP providers remain supported. No provider can guarantee inbox placement.

## Configure an existing Podman ecosystem

1. Verify your sender domain in Postmark. Publish the supplied DKIM and Return-Path records, configure DMARC alignment, and review bounce/complaint reports. Use a transactional stream with SMTP enabled.
2. Put the values in `deploy/ecosystem/postmark.env.example` into `D:\Project\OptiEcosystem\OptiNexus\deploy\ecosystem\.state\nexus.env`. Use the stream SMTP Access Key as username and Secret Key as password, port 587 with STARTTLS. For a standalone Compose installation, update the root `.env` instead. Do not replace the complete environment file: preserve APP_KEY, database credentials and signing settings.
3. Run the provided ecosystem updater after all companion PRs merge. Containers must be recreated to receive changed environment values; an image rebuild alone does not change them. Bootstrap migrates and reconciles existing subscription application assignments.
4. Confirm `ecosystem:preflight` mail configuration, send a test using a controlled tenant account, and check Nexus Notifications/Recorded Deliveries plus the provider delivery and bounce logs. SMTP acceptance is not proof of inbox delivery. No real email was sent during implementation tests.

Official configuration: https://postmarkapp.com/developer/user-guide/send-email-with-smtp and https://postmarkapp.com/manual .

## Recipients and events

Recipients are active, registered tenant admins with active memberships in the affected tenant, and active Nexus system operators with ecosystem access plus the corresponding view permission. User lifecycle notices use that user's tenant memberships; customer lifecycle notices use that customer's tenants. Unrelated tenant admins receive nothing. Price overrides notify only their tenant; global plan/add-on price changes notify tenants using that plan/add-on. Repeating an unchanged status does not generate another notice.

Events: customer Suspend/Terminate, tenant Suspend/Terminate, user Suspend/Disable, invoice issuance and subsequent status transitions, approved price creation/approval/retirement/effective-window changes. Price amounts are versioned: creating a new price notifies users but never rewrites issued invoices. Scheduled prices are announced with their effective date when approved.

Operational messages use the existing notification outbox, delivery history and retry mechanism. Notification status SENT means accepted by the configured transport, not guaranteed recipient inbox placement. User notification preferences are respected by the existing dispatcher.

## Password and email lifecycle

Reactivating a suspended tenant user restarts managed activation, replaces the password, expires the temporary password after the configured TTL, invalidates old sessions, and requires the user to choose a new password on the Nexus sign-in page reached from Fleet/Entry. It does not create a local password in the product. Existing verified email remains verified on reactivation.

A real transactional mailer must be configured before changing a tenant email; log/array/failover mailers are refused before the transaction commits. Changing an active/invited tenant user's email through Users or the tenant-admin linking flow resets managed activation and old sessions. A new temporary password is emailed to the new address. The new email loses its previous verification. Replacing the temporary password emailed only to that new address proves mailbox control, verifies the address as EMAIL_CREDENTIAL, and completes activation before application access. An out-of-band temporary-password reissue clears this proof flag and uses the existing operator-verification flow. Suspended/disabled accounts must be reactivated before changing email.

The temporary secret is hashed in users and encrypted in the queued job; no notification or audit body contains it. Expired, consumed, superseded or wrong-address credential jobs send nothing. Credential mail refuses the log/array mailer outside tests, so temporary passwords are not written to application logs. Failed credential jobs can be retried via the queue after configuring the provider; the temporary password must still be valid. Nexus/Fleet/Entry retain the immutable identity and account history. Email ownership conflicts are refused.

## Invoice and display behavior

Only finalized billings with no current invoice can generate a replacement. CANCELLED and VOID invoices are history; DRAFT, ISSUED, PAID, OVERDUE and PARTIALLY_PAID invoices block another generation. Pending/verified payments block void/cancel. Replacement numbers are new; original issue dates remain intact. Download Invoice returns a self-contained, escaped HTML document that can be opened, printed, or saved as PDF from the browser, including for a Paid invoice.

Displayed date/time values use `dd/mm/YYYY, HH:MM:SS` in the browser's timezone, with 24-hour time and seconds. Date-only values keep their calendar date at 00:00:00. API/storage values remain ISO timestamps; form date inputs retain their native machine format.

Assigned Application is read-only on Tenant Detail. Creation derives it from the selected plan/product/add-ons or a validated subset selected during subscription creation. Reconciliation uses the union across subscriptions; changing one cannot remove an application supplied by another. Roles, memberships and user seats are not silently recreated. Existing assignments are reconciled during bootstrap; customer/tenant suspension still blocks access independently of an assignment.
