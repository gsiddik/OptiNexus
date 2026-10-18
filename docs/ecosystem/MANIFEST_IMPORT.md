# Product manifest import and operator registration (A02, A14, A23)

Nexus is the one place a product's catalog, OIDC client and service account are registered. A product never creates them for itself.
Everything below is idempotent and additive: repeating a command changes nothing, and nothing is ever deleted.

## Manifest v2

A product publishes `manifest_version: "2.0"`, a `revision` string, `capabilities` (module/feature, optional `parent_code`) and
`permissions` (`permission_key`, `name`, optional `capability_code`, `delegable`). Field limits are in
`ImportManifestRequest::manifestRules()`.

| Rule | Behaviour |
|---|---|
| Additive | Missing capabilities/permissions are created, changed names/descriptions updated, nothing removed. A permission absent from a new revision is reported in `unlisted_permissions` and stays. |
| Delegable flag | New permissions take the manifest value. An existing permission going `false` -> `true` is only listed in `delegable_widening_pending` unless `--allow-delegable-widening` (or `allow_delegable_widening: true`) is passed: widening what an Admin Tenant may hand out is an operator decision. Narrowing applies at once. |
| Key ownership | Keys must belong to the application's own namespace; a manifest can never touch another product's keys or `cgo.*`. |
| Dry run | `--dry-run` / `dry_run: true` runs the whole import in a transaction and rolls it back; the summary is exact. |
| Audit | `application.manifest_imported` with revision and counts; `applications.metadata.manifest` keeps version, revision, time and actor. |

## Commands (operator, run inside the Nexus backend)

```
php artisan ecosystem:import-manifest <app_code> <file.json> [--dry-run] [--allow-delegable-widening] [--create-application]
php artisan ecosystem:register-oidc-client <app_code> --redirect-uri=URL [--post-logout-uri=URL] [--launch-url=URL] [--rotate] --secret-file=PATH
php artisan ecosystem:register-service-account <app_code> [--name=N] [--tenant=ID] [--rotate] --secret-file=PATH
```

- The governance application `cgo` cannot be registered with either command.
- OIDC clients are always created with PKCE required (`require_pkce` is not an option).
- A secret exists only when a client/account is created or with `--rotate`. It is written to `--secret-file` (mode 0600, refused when
  the file cannot be created that way) and is never printed. A command that issues a secret without `--secret-file` fails.
- Rotation invalidates the old secret at once; the client id is kept.

## Order of operations (found by the live test, 2026-10-17)

1. `ecosystem:import-manifest <app> <file> --create-application` (the manifest must declare `manifest_version: "2.0"` as a **string** and an
   `application` block with `application_code`, `name`, `frontend_url`; OptiEntry and OptiFleet do since the live test).
2. **Run the bootstrap job again** (`docker compose run --rm bootstrap`, which runs the production seeder). The platform roles are defined
   as "every permission" and only get the imported permissions when the seeder runs. Until then even a platform administrator cannot
   build a role from the new product: the escalation guard refuses a grant of a permission the actor does not hold
   (`403 PRIVILEGE_ESCALATION_DENIED`). Import does not widen any role by itself: this is a deliberate operator step.
3. `ecosystem:register-oidc-client`, `ecosystem:register-service-account`, then commercial setup (product, plan, subscription), tenant,
   Admin Tenant and roles.

## HTTP equivalents and onboarding report

- `POST /api/v1/applications/{application}/manifest` (operator with `cgo.application.update`, `cgo.capability.create`,
  `cgo.permission.create`; same body plus `dry_run`, `allow_delegable_widening`).
- `GET /api/v1/ecosystem/onboarding/tenants/{tenant}` (operator with `cgo.tenant.view` for that tenant): actionable gaps per tenant and
  application: tenant usable, Admin Tenant present, an application assigned; per application an OIDC client (PKCE, launch URL), a
  service account, an imported manifest with delegable permissions, users with access and an Admin Tenant with access. It reports
  only what Nexus holds as records; it never claims a product callback or connection works.
