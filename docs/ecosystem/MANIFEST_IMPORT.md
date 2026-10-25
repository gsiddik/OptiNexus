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

## OptiRadar manifest and embed sessions (K5)

`docs/ecosystem/manifests/optiradar.manifest.json` is the catalog of OptiRadar (3 modules, 4 features, 4 permissions, limits `device_limit` and
`user_limit`). Import it like any other: `php artisan ecosystem:import-manifest optiradar docs/ecosystem/manifests/optiradar.manifest.json --dry-run`.

Embed sessions (OptiDeli shows the OptiRadar map for its tenant): the caller's service account needs the scope `embed.create` (give it by name) and a tenant
binding. Nexus holds `OPTIRADAR_BASE_URL` and `OPTIRADAR_EMBED_TOKEN` (the token of an OptiRadar administrator, separate from the read-only
`OPTIRADAR_API_TOKEN`), asks `POST /api/optinexus/embed` for a read-only token of at most 15 minutes limited to the tenant's group, and returns
`{url, expires_at}`. OptiRadar lists the embedding origins in `web.frameAncestors`; no Radar cookie is used inside the frame.

## Seeding the whole catalog (EcosystemCatalogSeeder)

`ProductionSeeder` (the container bootstrap job, `NEXUS_SEED=catalog`) runs `EcosystemCatalogSeeder` before the system roles. For OptiFleet
(`optifleet`), OptiEntry (`optiaccounting`), OptiDeli (`optideli`) and OptiRadar (`optiradar`) it registers the application (PUBLISHED), imports
modules, features and permissions from `backend/database/seeders/manifests/<code>.manifest.json`, registers the events in the manifest's
`events[]`, and puts every capability into the product `OPTI-SUITE` and the active plan `SUITE-MONTHLY`, so a tenant can be subscribed,
provisioned and activated from the console. The bootstrap administrator (`AdminUserSeeder`) then receives every imported permission through
PLATFORM_SUPERADMIN, so a fresh installation needs no manual import.

* Additive and repeatable: an existing application keeps its URLs, nothing is deleted, the suite product and plan only gain capabilities.
* Not seeded: customers, tenants, users, OIDC clients and service accounts. Clients and service accounts carry secrets; register them with
  `ecosystem:register-oidc-client` and `ecosystem:register-service-account` (or `deploy/optiecosystem`).
* After a product release, replace its manifest file with the product's current output and seed again
  (`php artisan db:seed --class=EcosystemCatalogSeeder`, then the bootstrap job so the platform roles pick up new permissions).

* Seats: an application is seat-controlled only when its manifest declares `user_limit` in `entitlement_limit_keys`, and the Seats screen lists only those.
  OptiEntry and OptiRadar declare it; the seeder adds it for `optifleet` and `optideli` (`EcosystemCatalogSeeder::SEAT_CONTROLLED`) so their seats can be set
  per tenant. The suite plan starts unlimited, so nobody is blocked until an operator sets a number. Drop an entry once the product declares the key itself.
