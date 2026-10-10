# OptiNexus Platform Integration — Architecture

Status: design baseline for the OptiNexus ⇄ OptiFleet ⇄ OptiRadar integration
(branch `claude/project-thread-bc4ya2` in every repository).

## 1. Goals

1. OptiNexus is the authority for tenants, users, tenant memberships,
   application access and subscriptions (contracts).
2. Single sign-on: a user signs in once and can move between OptiFleet and
   OptiRadar without signing in again, **only if** their tenant is assigned
   (subscribed) to that application and the user has access to it.
3. Data exchange between platforms goes through the **OptiNexus API Gateway**.
   First flow: latest vehicle odometer, OptiRadar → OptiFleet.
4. A published API contract lets other platforms integrate in the same way.

## 2. Decisions

| # | Decision | Status |
|---|----------|--------|
| D1 | Odometer direction: OptiRadar (GPS) → OptiFleet. Manual odometer entry in OptiFleet (inspection, work order, release, tire operations) stays available, so tenants without OptiRadar are unaffected; sync only runs for tenants linked to OptiNexus with telematics. OptiFleet only moves `current_odometer` forward and stores every telematics reading with its source. | Confirmed by owner |
| D2 | Commercial ownership, phased: OptiNexus owns tenant, user, application access now. OptiFleet's own contract/billing modules keep working unchanged in this phase and move to OptiNexus later. | Confirmed by owner |
| D3 | OptiRadar tenant isolation: one OptiRadar group per OptiNexus tenant. | Confirmed by owner |
| D4 | SSO protocol: OpenID Connect (authorization code + PKCE), OptiNexus as the provider. | Default |
| D5 | `main` is the baseline of every repository. The first line of work (`claude/cgo-phase-1-governance-qtmik1`) became OptiNexus `main`; new development always starts from `main` on a new branch and reaches `main` by pull request. | Confirmed by owner |
| D6 | GPS distance versus the odometer already recorded in OptiFleet: GPS distance is never applied by itself. It is stored and held until someone calibrates the link manually (an offset, or the actual odometer at the time of the latest GPS reading). A device that reports a real odometer is applied directly. | Confirmed by owner |
| D7 | SSO for a user who has no OptiFleet account yet: sign-in is refused (existing users only). OptiFleet never creates accounts from SSO; an administrator adds the user first. | Confirmed by owner |
| D8 | Central logout and automatic deactivation: OIDC Back-Channel Logout. A logout anywhere ends the user's sessions in every application (all devices). Losing access (user suspended or disabled, removed from the tenant, application access removed, tenant suspended) also deactivates the application account so password sign-in stops too; the account is switched on again only by the next successful SSO sign-in and only if OptiNexus deactivated it. Every application keeps working with its own password login when OptiNexus is not used. | Confirmed by owner |
| D9 | OptiFleet reports invoice and memo events to OptiNexus (`POST /api/v1/events`) from its integration outbox, in the OptiNexus Event Catalog under `optifleet.*`. PostgreSQL in OptiFleet stays the source of truth; delivery is at-least-once and idempotent by `event_id`. | Confirmed by owner |
| D10 | OptiRadar reports device online/offline, geofence enter/exit and overspeed events to OptiNexus (`POST /api/v1/events`) from an outbox table of its own, in the Event Catalog under `optiradar.*`, for devices of a tenant group only. Same guarantees as D9 (at-least-once, idempotent by `event_id`); the OptiRadar database stays the source of truth. | Confirmed by owner |

## 3. Components

```
                 ┌────────────────────────── OptiNexus ───────────────────────────┐
 Browser ──SSO──▶│ OIDC Provider  /.well-known, /oidc/authorize|token|userinfo|jwks│
                 │ Governance API /api/v1/* (tenants, users, apps, subscriptions)  │
 Apps ──M2M────▶ │ API Gateway    /api/gateway/v1/*  (client-credentials tokens)   │
                 │   ├ Vehicle link registry (fleet vehicle ⇄ telematics device)  │
                 │   ├ Telematics odometer store (append-only, cursor feed)       │
                 │   └ Connectors: OptiRadar (REST pull)                          │
                 └────────────────────────────────────────────────────────────────┘
        ▲ OIDC + gateway client                      ▲ OIDC (built-in OpenID client)
   OptiFleet (Laravel)                         OptiRadar + OptiRadar-web
```

### 3.1 Identity and SSO (OIDC)

* Each integrated application registers an **OIDC client** linked to its
  `applications` row (`client_id`, hashed secret, exact redirect URIs).
* `GET /oidc/authorize` keeps an OptiNexus browser session (web guard). A user
  who already signed in to OptiNexus for one app is sent straight back to the
  next app: that is the SSO hop.
* OptiNexus authorizes the login for the requesting application only if all of
  these hold: the user is ACTIVE, the tenant membership is ACTIVE, the tenant is
  ACTIVE, the application is assigned to the tenant (ACTIVE), and the user has
  ACTIVE `user_application_access` for that application in that tenant. If not,
  the app receives `access_denied`. This is how "only if subscribed to
  telematics" is enforced server-side.
* With several eligible tenants the user picks one. Apps may pass
  `tenant_hint` (tenant id or code) to skip the picker.
* Tokens: RS256 `id_token` (signed with the OIDC key, published in JWKS) and an
  opaque access token for `/oidc/userinfo`.
* Claims: `sub` (OptiNexus user id), `email`, `email_verified`, `name`,
  `tenant_id`, `tenant_code`, `tenant_name`, `groups` (application codes the
  user may open in that tenant) and `apps` (`code`, `name`, `launch_url`) for
  app switchers.

**OptiFleet** adds "Sign in with OptiNexus" next to password login (kept for
backward compatibility). Tenants are linked by `tenants.optinexus_tenant_id`;
users by `users.optinexus_subject`, falling back to a *verified* e-mail on first
login. Unknown users are refused (D7), a bound identity is never rebound, and the
browser only ever receives a one-time ticket (60 s), never a token. The SPA offers
an app switcher built from the `apps` claim; signing out also ends the OptiNexus
session.

**OptiRadar** uses its built-in OpenID client (`openid.*` keys) with
`openid.allowGroup=optiradar`. With `openid.tenantClaim=tenant_id` it requires a
verified e-mail, finds the one OptiRadar group whose attribute `optinexusTenantId`
matches the claim (D3, no group or several groups rejects the login before any
account is touched) and links the user to that group only, so they see just their
tenant's devices. The `apps` claim is kept in a user attribute for the web app's
account menu. See `OptiRadar/docs/optinexus-sso.md`.

**Launch URLs** (set per OIDC client, they feed the `apps` claim): OptiRadar
`https://<radar>/api/session/openid/auth`, OptiFleet
`https://<fleet-api>/api/v1/auth/sso/redirect`. Opening either one from the other
app signs the user in without a second login prompt, because OptiNexus reuses its
session.

### 3.1b Central logout and deactivation (D8)

* OptiNexus records every application sign-in as an `oidc_sessions` row (its id is the `sid` claim).
* Logout (OptiNexus, or an application that redirects to the end-session endpoint) and every access loss
  (user, tenant membership, application access, tenant or application changes; a periodic
  `oidc:reconcile-access` run catches changes made without a hook) queue one **Back-Channel Logout** call per
  application in `oidc_logout_deliveries`. A queued job posts a signed `logout+jwt` to the client's
  `backchannel_logout_uri` with retries. Access loss adds the custom event
  `https://schemas.optinexus.io/event/access-revoked` (reason, scope `user` or `tenant`, `tenant_id`).
* **OptiFleet** ends the user's API tokens (all, or the tenant's) and, on access loss, marks the account as
  deactivated by OptiNexus so password login is refused; the next SSO sign-in reactivates only such accounts.
* **OptiRadar** stores the time of the event in the user attribute `optinexusSessionsNotBefore` (older web
  sessions stop being accepted), disables the account on access loss and marks it with `optinexusDeactivated`
  (same reactivation rule), or removes the tenant group link for a tenant-level loss.
* The user-level action is "log out everywhere"; there is no per-device logout.

### 3.1c OptiFleet events (D9)

`optifleet.*` events travel from OptiFleet's `integration_outbox_events` to `POST /api/v1/events` with the outbox
row id as `event_id`. OptiNexus validates them against the Event Catalog (`OptiFleetEventCatalogSeeder` registers
six events), stores each once per `event_id` (a replay with the same content is answered idempotently) and the
workflow and notification engine can react. See the integration guide, §6.

### 3.1d OptiRadar events (D10)

`optiradar.*` events (device online/offline, geofence entered/exited, overspeed) travel from the `tc_optinexus_events`
outbox table of the OptiRadar fork to `POST /api/v1/events` with the same envelope and guarantees as D9.
`OptiRadarEventCatalogSeeder` registers the five events, `OptiRadarApplicationSeeder` the application. The tenant of an
event is the `optinexusTenantId` of the device's group (or nearest ancestor group); devices outside a tenant group are
not reported. See the integration guide, §7 (OptiRadar events).

### 3.1e Event key ownership

An event key belongs to the application it is registered to in the Event Catalog (`event_catalog.application_id`).
`POST /api/v1/events` accepts it only from a service account of that application, so one application can never emit
another's events (OptiRadar's token cannot send `optifleet.*`). A key without an application is a platform key and only
a service account without an application may send it. The check runs right after the key is looked up, before the
status and payload checks, so a stranger learns nothing about a key it does not own; the answer is `403
EVENT_SOURCE_DENIED`, which the OptiFleet and OptiRadar relays already treat as a refusal retrying cannot fix. The owner
of a platform key can be set once through the catalog update (`application_id`); changing an owner is refused, because
the new owner could then speak for the old one. This closes the gap noted in OptiNexus PR #4. The service accounts
used by OptiFleet and OptiRadar must therefore be created for their own application.

### 3.2 API Gateway

Base path `/api/gateway/v1`. Every call uses an OAuth2 client-credentials token
of an OptiNexus **service account** bound to an application. Tenant comes from
the service account's tenant or the `X-Tenant-Id` header; it is accepted only
if the caller's application is assigned (ACTIVE) to that tenant. Every call is
written to `gateway_request_logs` with a correlation id.

| Endpoint | Scope | Purpose |
|----------|-------|---------|
| `PUT /fleet/vehicles` | `gateway.fleet.write` | Fleet app publishes its vehicle directory (id, registration number, VIN). |
| `GET /vehicle-links` | `gateway.fleet.read` | Current fleet vehicle ⇄ telematics device links. |
| `POST /telematics/odometer-readings` | `gateway.telematics.write` | Telematics producers push readings. |
| `GET /telematics/odometer-readings?cursor=` | `gateway.telematics.read` | Consumers pull readings in order (cursor feed), already mapped to the fleet vehicle id. |

Admin (Sanctum + RBAC `cgo.gateway.*`): manage vehicle links, view logs, run the
OptiRadar connector.

**OptiRadar connector**: a scheduled job (`gateway:sync-optiradar`) reads
OptiRadar `/api/groups`, `/api/devices` and `/api/positions` with an OptiRadar
service token stored as an integration credential. The tenant of a device is
the `optinexusTenantId` attribute of its group. Odometer is the position's
`odometer` attribute when the device reports it, otherwise `totalDistance`;
both are metres and converted to kilometres with decimal arithmetic.

**Auto-linking**: a device is linked to a fleet vehicle of the same tenant when
the normalized registration number (upper-case, no spaces or dashes) of the
vehicle equals the device's `registrationNumber` attribute or, failing that, its
name. Admins can override links; manual links win.

### 3.3 Odometer flow (D1, D6)

1. OptiRadar reports the position's `odometer` attribute when the device sends one,
   otherwise it computes `totalDistance` (both metres).
2. The OptiNexus connector stores a reading (`tenant`, `device`, `value_km`,
   `odometer_kind` = `DEVICE_ODOMETER` or `GPS_DISTANCE`, `recorded_at`),
   idempotent on `(tenant, source, device, recorded_at)`.
3. OptiFleet `optinexus:sync` (scheduled) pushes its vehicle directory, then
   pulls readings after its saved cursor.
4. OptiFleet locks the vehicle row and stores every reading
   (`vehicle_odometer_readings`, unique per source reference):
   * `DEVICE_ODOMETER` raises `current_odometer` when higher.
   * `GPS_DISTANCE` is held (`effective_km` empty) until the link is calibrated
     (`PUT /api/v1/app/telematics-links/{link}/calibration` with `odometer_offset_km`
     or `actual_odometer_km`); afterwards `effective = reported + offset` raises the
     odometer when higher. Calibrating re-evaluates the held readings.
   * A lower value is kept in history but never moves the odometer back.
   * Manual odometer entries in OptiFleet keep working as before.

## 4. Security

* Tenant is never taken from client input without checking it against the
  caller's service account or the user's memberships.
* OIDC: exact redirect URI match, PKCE S256 supported (required for public
  clients), single-use codes valid 5 minutes, hashed secrets, codes and tokens.
* Gateway uses the existing SSRF-safe HTTP client for outbound connector calls.
* Logout tokens are RS256 `logout+jwt` (never carry a `nonce`, short-lived, unique `jti`); receivers verify
  signature, issuer, audience, age and type, accept each token once, and the call is the only thing they trust.
  OptiNexus delivers with a 5 second timeout and no redirects.
* All credentials (OptiRadar token, client secrets) live in environment config or
  encrypted integration credentials, never in the repository.

## 5. Contract for new platforms

See `docs/integration/INTEGRATION_GUIDE.md` and `docs/openapi.yaml`
(tags `OIDC`, `Gateway`).

## 6. Verified end to end

Run against live instances (OptiNexus, OptiRadar, OptiFleet backend + SPA, throw-away
databases, scripted browser; not part of the automated suites):

* OptiFleet sign-in through OptiNexus, then OptiRadar without a login prompt, and the reverse.
* A user whose organization is not subscribed to OptiRadar is refused by OptiNexus; a tenant
  with no OptiRadar group, or a user whose e-mail is not verified, is refused by OptiRadar and no
  account is created; in OptiFleet a user without an account is refused with
  `user_not_provisioned` (D7, nothing is created) and an unlinked tenant with `tenant_not_linked`.
* A signed-in OptiRadar user sees only the devices of their tenant's group.
* Device odometer reaches the OptiFleet vehicle through the gateway; a GPS-only device is held,
  then applied after calibration done in the OptiFleet UI/API with an SSO token.

## 7. Known limits

* Logout is user-level ("everywhere"); a plain logout does not revoke OptiRadar long-lived API tokens (a deactivation
  does, because the account is disabled).
* Reactivation happens only at the next successful SSO sign-in; nothing switches an account on by itself when
  access is restored. An account an administrator disabled by hand in an application is never reactivated.
* The reconciler and the hooks only know about SSO sessions and access rows; an OptiFleet account that never
  signed in through SSO is reached through its access row (by e-mail match). OptiFleet API tokens have no expiry of
  their own; they end through these events.
* A delivery that keeps failing (application down) is retried with backoff and then left as `FAILED` in
  `oidc_logout_deliveries`. The reconciler only looks at sessions that are still active, so it never repeats a
  failed call. An administrator sends them again with `php artisan oidc:requeue-logout-deliveries` once the
  application is back (`--dry-run` first, `--client=`, `--user=`, `--max-age=` to narrow). The command repeats a call
  only while it is still true, because an application acts on it at once: a *logout* is skipped when the user signed
  in to that application again after the call was created (it would end the new session) or when it is older than
  `--max-age` hours (default 24; the user may have signed in to the application directly since, which OptiNexus does
  not see); an *access revoked* call is skipped when access has been restored (it would deactivate a reinstated
  user), whatever its age. Nothing runs it automatically: a person decides when the application is healthy again.
* OptiRadar's built-in OpenID client does not verify the authorization `state` or the ID token
  separately (upstream behavior, unchanged). The user is identified through `userinfo`.
* OptiAccounting is an empty repository; it can onboard using the guide without changes here.
