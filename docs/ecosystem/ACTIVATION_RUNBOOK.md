# Activation runbook: OptiNexus + OptiDeli + OptiEntry + OptiRadar

What an operator does, in order, to switch on the ecosystem integration (Feed v1 Deli → Entry, OptiRadar embed in Deli). Every command
below was run against live servers on 2026-10-24 (see "Verified run" at the end), except the OptiRadar Java service, which was replaced by a
stub because the fork cannot be built in the test sandbox (CI builds it).

On a workstation with Podman Desktop the same steps run inside the OptiEcosystem containers: `deploy/optiecosystem/PANDUAN_PODMAN_DESKTOP.md`
(which `exec` command, which `.state` env file, and the browser check of the OptiRadar iframe in OptiDeli).

## 1. OptiNexus

1. Register each product's catalog (additive, safe to repeat):
   ```
   php artisan ecosystem:import-manifest optideli     <(cd OptiDeli/backend && php artisan optideli:nexus:manifest) --create-application
   php artisan ecosystem:import-manifest optiaccounting <(cd OptiAccounting/backend && php artisan optientry:nexus:manifest) --create-application
   php artisan ecosystem:import-manifest optiradar     docs/ecosystem/manifests/optiradar.manifest.json
   ```
   Then re-run the bootstrap seeder so system roles pick up the new permissions.
2. Service accounts (secret goes to a 0600 file, shown once):
   ```
   php artisan ecosystem:register-service-account optideli --scopes=authorization.check,commercial.read,embed.create --secret-file=deli.sa
   php artisan ecosystem:register-service-account optiaccounting --secret-file=entry.sa
   ```
   `commercial.read` is what lets OptiDeli verify pairing for the feed; `embed.create` (a gateway scope, never granted by default) lets it ask
   for an OptiRadar embed session.
3. Environment: `OPTIRADAR_BASE_URL` (the OptiRadar server) and `OPTIRADAR_EMBED_TOKEN` (an API token of an OptiRadar *administrator*; used
   only to mint short-lived read-only tokens). Set `frontend_url` of the `optiradar` application to the public OptiRadar web address.
4. Per tenant: the tenant must have the applications attached **and** a subscription whose plan contains at least one capability of each
   application. Application entitlements are generated from the plan's capabilities; a plan without any capability of an application yields
   no `applications_enabled` entry for it (the feed then answers `FEED_PRODUCER_NOT_ENTITLED`). After changing a plan:
   `php artisan ecosystem:refresh-entitlements <tenant> --apply`. Consumers cache the commercial context for up to 300 seconds.

## 2. OptiDeli

1. Link the tenant: `tenants.optinexus_tenant_id` = the OptiNexus tenant id (platform console: tenant → link OptiNexus).
2. Environment: `OPTINEXUS_BASE_URL`, `OPTINEXUS_SERVICE_CLIENT_ID/SECRET` (from `deli.sa`), `OPTIDELI_DEPLOYMENT_MODE=saas`,
   `OPTINEXUS_FEED_PAIRING=required` (or identity mode `optinexus`, where `auto` means required).
3. Issue the feed credential: `php artisan optideli:feed:issue-credential <tenant-slug> --consumer=optientry --output=feed.token` (0600 file, shown once).
4. OptiFleet availability: `OPTIFLEET_FAIL_OPEN` is `false` by default (dispatch is refused when OptiFleet is unreachable and no state is
   known). Set `true` only if the business accepts dispatching without a readiness answer.
5. The map page `/radar` needs the tenant linked (step 1) and a subscription that includes OptiRadar; otherwise it shows a clear message.

## 3. OptiEntry

1. In `optinexus` mode (or `OPTIENTRY_FEED_PAIRING=required`) the organization must be linked to the same OptiNexus tenant (sign-in/provisioning does this).
2. Create the integration connection: source `optideli`, base URL of OptiDeli, the feed credential, the OptiDeli tenant id as source tenant
   reference, cutover date, and the settings (receipts bank account, payments cash account, expense category, optional tax codes). Issue the
   `OPTIDELI_INTEGRATION` service principal once per tenant. Activate (the activation probe makes one tiny feed request).
3. `php artisan integration:pull` (scheduled every minute in production).

## 4. OptiRadar

1. `web.frameAncestors` = the origin(s) of OptiDeli (space separated). Without it the browser refuses to frame the map.
2. Create the administrator API token used as `OPTIRADAR_EMBED_TOKEN` in OptiNexus; each tenant's group carries attribute `optinexusTenantId`.

## Verified run (2026-10-24, throw-away servers)

Real OptiNexus, OptiDeli and OptiEntry processes on separate PostgreSQL databases; OptiRadar replaced by a 20-line stub of
`POST /api/optinexus/embed`.

| Check | Result |
|---|---|
| Deli lifecycle (order → trip → POD → freight invoice → AR invoice, AP bill, receipt, disbursement) | 4 feed events, sequence 1..4 |
| Feed read with live pairing against OptiNexus | 200, `pairing.nexus_tenant_id` = the Nexus tenant |
| Plan without capability of an app | `403 FEED_PRODUCER_NOT_ENTITLED` (see 1.4) |
| Subscription cancelled / tenant suspended / OptiNexus stopped | `403 FEED_SUBSCRIPTION_INACTIVE` / `403 FEED_TENANT_NOT_ACTIVE` / `503 FEED_GOVERNANCE_UNAVAILABLE` + `Retry-After` |
| OptiEntry `PullService` over real HTTP, pairing required | received 4, posted 4, 4 journals; AR 20,000,000 / AP 16,000,000 posted; all journals balanced (debit = credit = 57,000,000) |
| Deli `/telematics/radar-embed` → Nexus → Radar stub | 200 with `?embed=1&token=...`; Radar saw the administrator token and the tenant id |
| Embed with OptiRadar down / tenant not linked | `503 RADAR_EMBED_UNAVAILABLE` / `409 RADAR_EMBED_NOT_AVAILABLE` |

Not covered by that run: the real OptiRadar Java service and OptiRadar-web inside a browser iframe, OptiFleet → OptiEntry against a live
OptiNexus, and foreign-currency or tax-split documents.

Gotchas for repeating it: start servers with `php -S` and env overrides (not `artisan serve`), `PHP_CLI_SERVER_WORKERS=4` (Deli calls
Nexus while a request is open), run Deli seeding tests with `APP_ENV=testing` (otherwise CSRF returns 419), clear Deli's cache between
governance scenarios (`php artisan cache:clear`), and kill servers by PID.
