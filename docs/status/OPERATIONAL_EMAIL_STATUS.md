# Operational e-mail completion — status

Branch `claude/project-thread-vxiqbt` (from `main` 413ed7f), draft PR, not merged (owner merges on request).
Scope: the four gaps from the 2026-10-25 audit of operational e-mail (customer/tenant/user lifecycle, invoices, pricing).

| # | Gap | Delivered |
|---|---|---|
| 1 | No OVERDUE transition, no due reminder | `invoices:process-due-dates` (hourly): ISSUED/PARTIALLY_PAID past due -> OVERDUE (row lock, audit `invoice.overdue`); one reminder `INVOICE_DUE_REMINDER_DAYS` (default 3, 0 = off) before due. Partial payment keeps OVERDUE. |
| 2 | Tests only for tenant and pricing | `OperationalMailTest` (customer, user, invoice issue/status/overdue/reminder, rendering, locale, money format), `EmailSuppressionTest`, preflight test |
| 3 | Plain English `Mail::raw` | Blade HTML + plain-text multipart, `lang/{id,en}/operational_mail.php`, default `id`, console link (`NEXUS_CONSOLE_URL`) |
| 4 | Bounces/complaints not fed back | `POST /api/v1/webhooks/postmark` (HTTP Basic, 404 until configured) -> `email_suppressions`; dispatcher cancels suppressed addresses; console page Notifications > Email Suppressions |

Also: a cancelled DRAFT invoice is no longer announced (the draft never was).

Operations (owner): set `POSTMARK_WEBHOOK_USERNAME/PASSWORD` and register the webhook in Postmark (needs a public URL);
run `optiecosystem.ps1 init` to add `NEXUS_CONSOLE_URL`; recreate containers. Guide: `docs/OPERATIONAL_EMAIL.md`.

Not validated here: a real send through Postmark and a real Postmark webhook call (no account in the sandbox);
the sandbox runs PHP 8.3, so tests ran against dependencies re-resolved for 8.3 (the committed lock needs PHP 8.4).
