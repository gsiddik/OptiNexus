# Integrating a platform with OptiNexus

This guide is the contract for any platform (OptiFleet, OptiRadar, OptiAccounting,
future apps) that wants to sign users in through OptiNexus and exchange data through
the API Gateway. The machine-readable contract is `docs/openapi.yaml`
(tags `SSO`, `SSO Clients`, `Gateway`, `Gateway Admin`).

## 1. Onboarding checklist (done once per platform, by an OptiNexus admin)

| Step | How | Result |
|------|-----|--------|
| 1. Register the application | `POST /api/v1/applications`, then submit, approve, publish | `application_code` (e.g. `optiradar`), status `PUBLISHED` |
| 2. Subscribe tenants | `POST /api/v1/tenants/{tenant}/applications/{application}` | Tenant may use the app. This is what "subscribed" means for SSO and the gateway. |
| 3. Give users access | `POST /api/v1/users/{user}/applications/{application}` (per tenant) | User may open the app in that tenant |
| 4. Register an SSO client | `POST /api/v1/oidc-clients` | `client_id` + one-time `client_secret` |
| 5. Create a service account | `POST /api/v1/service-accounts` with `application_id` (and `tenant_id` to bind it to one tenant) | OAuth client id + one-time secret |

Secrets are shown once. Store them in your secret manager, never in the repository.

For a fresh OptiNexus, `php artisan db:seed` already does step 1 for both platforms (`optifleet` through the demo
data, `optiradar` through `OptiRadarApplicationSeeder`) and, for the demo tenant, steps 2 and 3. An application that
exists is left unchanged, so seeding again never overwrites its launch URLs; set the real URLs through the API. SSO clients
(step 4) are never seeded because they produce secrets; the demo data only creates a demo OptiFleet service account
(step 5) and prints its secret once, so create real ones through the API. To add OptiRadar to an existing installation without reseeding
everything: `php artisan db:seed --class=OptiRadarApplicationSeeder`.

## 2. Single sign-on (OpenID Connect)

Discovery: `GET {issuer}/.well-known/openid-configuration`. Supported: authorization
code flow, PKCE `S256`, RS256 `id_token`, `userinfo`, JWKS, RP-initiated logout.

1. Redirect the browser to `authorize_endpoint` with `response_type=code`,
   `client_id`, an exact registered `redirect_uri`, `scope=openid profile email`,
   `state`, `nonce` (and `code_challenge` + `code_challenge_method=S256` for public
   clients). Optional `tenant_hint` (tenant id or code) skips the organization picker.
2. OptiNexus signs the user in (or reuses their session - that is the SSO hop) and
   redirects back with `code`, `state`, `iss`. Verify `state` and `iss`.
3. `POST {token_endpoint}` (`client_secret_basic` or `client_secret_post`) to get
   `id_token` and `access_token`.
4. Verify the `id_token`: signature against the JWKS (match `kid`), `iss`, `aud` =
   your `client_id`, `exp`, `nonce`.
5. Read the claims and sign the user in locally.

**Claims you can rely on**

| Claim | Meaning |
|-------|---------|
| `sub` | Stable OptiNexus user id. **Link accounts on `sub`**, not on email (email may change). |
| `email`, `name` | Profile (scopes `email`, `profile`) |
| `tenant_id`, `tenant_code`, `tenant_name` | The organization this sign-in is for. Always present. |
| `groups` | Application codes the user may open in this tenant |
| `apps` | `[{code, name, launch_url}]` for an app switcher |

**Rules for relying parties**

* Take the tenant only from `tenant_id` in the verified token. Never from a request parameter.
* Map `tenant_id` to your local tenant record (store it, e.g. `optinexus_tenant_id`). Refuse sign-in for an unmapped tenant.
* Create local users just-in-time on first sign-in if you allow it; otherwise refuse unknown `sub`.
* Keep password login only as a fallback for tenants not yet linked to OptiNexus.
* Call `userinfo` periodically (for example every 10 minutes) to learn about revoked access. A `401 invalid_token` means end the local session.
* An `error=access_denied` redirect means the user's organization is not subscribed to your application or the user lacks access. Show a clear message; do not retry.

## 2b. Central logout and automatic deactivation (Back-Channel Logout)

OptiNexus tells every application when a user logs out anywhere, and when a user loses access.
It implements OIDC Back-Channel Logout 1.0 with one extra event. Register the endpoint on the
OIDC client (`backchannel_logout_uri`); without it the application is simply not notified.

**What triggers a call**

| Trigger | Type | Reason (`reason`) |
|---------|------|-------------------|
| The user logs out of OptiNexus or any application (`/oidc/logout`), or an admin calls `POST /users/{id}/force-logout` | logout | `logout`, `admin_logout` |
| User suspended / disabled | access revoked, all tenants | `user_suspended`, `user_disabled` |
| User removed from a tenant | access revoked, one tenant | `tenant_membership_removed` |
| User's access to the application removed | access revoked | `application_access_revoked` |
| Tenant suspended / terminated / archived | access revoked, one tenant | `tenant_suspended`, `tenant_terminated`, `tenant_archived` |
| Application unassigned from the tenant, or its subscription lapsed (caught within a minute by `oidc:reconcile-access`) | access revoked, one tenant | `application_not_assigned`, `application_unavailable` |

A *logout* ends sessions only. An *access revoked* call ends sessions **and** asks the application
to deactivate its account for the user, so password login stops too. Reactivation is not pushed:
when OptiNexus lets the user in again, the next successful SSO sign-in reactivates an account
that was deactivated this way (and only such an account).

**The call**: `POST <backchannel_logout_uri>`, `Content-Type: application/x-www-form-urlencoded`,
body `logout_token=<JWT>`. OptiNexus follows no redirects, waits 5 seconds, and retries
(10 s, 30 s, 2 min, 10 min, 30 min) on network errors, 5xx, 408 and 429. Answer `200` or `204`
when you accepted it (also when you do not know the user), or `400` when the token is invalid
(not retried). Every attempt is recorded in `oidc_logout_deliveries`.

If your endpoint was down for longer than the retries (about 45 minutes), the call ends as `FAILED`.
After you are back, the OptiNexus operator runs `php artisan oidc:requeue-logout-deliveries`
(`--dry-run` shows what would be sent). It sends a call again only while it is still true: not a
logout when the user signed in to your application again since or when it is more than
`--max-age` hours old (default 24), and not an access-revoked call once the user's access has been
restored. Keep answering `200` for a user you do not know, so a repeated call is harmless.

**The `logout_token`** is an RS256 JWT signed with the same key as the `id_token`, with header `typ: logout+jwt`:

| Claim | Value |
|-------|-------|
| `iss`, `aud` | the issuer and **your** `client_id` |
| `iat`, `exp` | issued now, valid 2 minutes (signed afresh on every retry) |
| `jti` | unique id, remember it for a few minutes and refuse a repeat |
| `sub` | the OptiNexus user id (same as in the `id_token`) |
| `email` | the user's e-mail (extra claim, for applications that match accounts by e-mail) |
| `events` | always `{"http://schemas.openid.net/event/backchannel-logout": {}}`; for access revoked also `"https://schemas.optinexus.io/event/access-revoked": {"reason": "...", "scope": "user" or "tenant", "tenant_id": "..."}` (`tenant_id` only when `scope` is `tenant`) |

There is never a `nonce`. Validate like an `id_token` (signature through the JWKS, `iss`, `aud`,
`exp`/`iat` with a small clock skew), require the `events` member, and require `sub`. Then:

1. End every session of that user in your application (all devices). A token issued earlier
   must stop working.
2. If the access-revoked event is present, also deactivate the local account: for `scope=tenant`
   only the user's membership of that `tenant_id` (map it to your local tenant), for
   `scope=user` the whole account. Mark it as deactivated by OptiNexus so that an SSO sign-in can
   undo exactly that, and nothing else.
3. Answer `200`.

## 3. API Gateway

Base: `{host}/api/gateway/v1`. Authenticate with OAuth2 client credentials
(`POST /api/v1/oauth/token`, `grant_type=client_credentials`, the scopes below).

* A service account **bound to a tenant** always acts for that tenant. An unbound
  (platform) account must send `X-Tenant-Id`. In both cases the tenant must be ACTIVE and
  have your application assigned; otherwise `403 TENANT_NOT_SUBSCRIBED`.
* Every call is logged per tenant (`GET /api/v1/gateway/tenants/{tenant}/request-logs`). Send `X-Correlation-Id` to trace a call end to end.
* Errors use the standard envelope `{ "success": false, "error": { "code", "message", "details" } }`.

| Endpoint | Scope | Use |
|----------|-------|-----|
| `PUT /fleet/vehicles` | `gateway.fleet.write` | Fleet app publishes `{id, registration_number, vin}` |
| `GET /vehicle-links` | `gateway.fleet.read` | Which of your vehicles have a telematics device |
| `POST /telematics/odometer-readings` | `gateway.telematics.write` | Telematics app pushes `{device_ref, odometer_km, recorded_at, registration_number?}` |
| `GET /telematics/odometer-readings?cursor=` | `gateway.telematics.read` | Fleet app pulls readings for its linked vehicles |

**Consuming the feed (what OptiFleet does):** persist `next_cursor`; be idempotent on
`reading_id`; treat `odometer_km` as a decimal string (never a float); `odometer_kind` is `DEVICE_ODOMETER` (real odometer) or `GPS_DISTANCE` (tracker distance since installation - calibrate it against your own odometer before use); a reading can be
delivered again when its device is linked later.

**Matching** vehicles to devices is automatic by registration number (case, spaces and
dashes ignored) when exactly one vehicle matches. Anything else stays `UNMATCHED` until
an admin links it (`PUT /api/v1/gateway/tenants/{tenant}/vehicle-links/{link}`).

### Adding a new gateway data type

1. Define the contract (request/response schema, scope, idempotency key) in `docs/openapi.yaml`.
2. Add a scope in `AppServiceProvider::boot()` and the routes in `routes/api_gateway.php` behind `service_account:<scope>` + `gateway_tenant`.
3. Put logic in a service under `app/Services/Gateway`, always tenant-scoped; never read a tenant from the payload.
4. Add tests for happy path, idempotency, tenant isolation and scope enforcement (see `tests/Feature/Integration/GatewayTest.php`).

## 4. Other OptiNexus services you can use

* `POST /api/v1/authorization/check`, `/entitlements/check`, `/access/evaluate` - server-side permission and module checks.
* `POST /api/v1/events` (`event.write`) - publish business events to the catalog for workflows and notifications.
* `POST /api/v1/audit-events` (`audit.write`), `POST /api/v1/usage-events` (`usage.write`).

## 5. OptiRadar connector (operators)

Set in `backend/.env`: `OPTIRADAR_SYNC_ENABLED=true`, `OPTIRADAR_BASE_URL`,
`OPTIRADAR_API_TOKEN` (a Traccar API token of a user that sees every tenant group).
In OptiRadar, give each tenant group the attribute `optinexusTenantId` = the OptiNexus
tenant id. `php artisan gateway:sync-optiradar` runs every minute when enabled.

## 6. OptiFleet (operators)

Backend env (`OptiFleet-v2/backend/.env`, all off by default):

| Key | Value |
|-----|-------|
| `OPTINEXUS_ENABLED` | `true` |
| `OPTINEXUS_BASE_URL` | OptiNexus base URL (the OIDC issuer) |
| `OPTINEXUS_SSO_CLIENT_ID` / `_SECRET` | the OIDC client of the OptiFleet application |
| `OPTINEXUS_SSO_REDIRECT_URI` | `https://<fleet-api>/api/v1/auth/sso/callback` (register it exactly) |
| `OPTINEXUS_SSO_FRONTEND_URL` | the SPA origin; the SPA route `/sso/callback` receives the ticket |
| `OPTINEXUS_GATEWAY_CLIENT_ID` / `_SECRET` | a service account of the OptiFleet application (unbound is fine; OptiFleet sends `X-Tenant-Id`) |
| `OPTINEXUS_EVENTS_ENABLED` | `true` to report invoice and memo events (see below); also needs the `event.write` scope on that service account |

Per tenant: set `tenants.optinexus_tenant_id` to the OptiNexus tenant id. Users must already
exist in OptiFleet (D7). Grant `telematics_link.view` / `telematics_link.manage` to the roles
that review and calibrate telematics links (module `VEHICLE`). Register
`<spa>/login` as a post-logout redirect URI of the OIDC client, and set the application's
launch URL to `https://<fleet-api>/api/v1/auth/sso/redirect`. `php artisan optinexus:sync`
runs on a schedule. Calibration: GPS-only devices appear under *Vehicle > Telematics* as
"Needs calibration"; enter the real odometer at the time of the latest GPS reading (or an offset).

### OptiFleet events

OptiFleet reports six events to `POST /api/v1/events` (key prefix `optifleet.`):
`workshop_invoice.recorded`, `.corrected`, `.cancelled`, `.payment_recorded`, `maintenance_memo.billed`,
`maintenance_memo.paid`. Each one carries `data.aggregate_type`, `data.aggregate_id`, `data.correlation`
(work order, partner) and `data.payload`; money is a decimal string. OptiFleet sends the id of its outbox row as
`event_id`, so a retry never makes a second event. To switch it on:

1. Register the six events: `php artisan db:seed --class=OptiFleetEventCatalogSeeder` (the application with code
   `optifleet` must exist; running it again only updates the entries).
2. Give the OptiFleet service account the `event.write` scope and assign the OptiFleet application to the tenants.
3. Set `OPTINEXUS_EVENTS_ENABLED=true` in OptiFleet. `php artisan optinexus:relay-events` then runs every minute.

Only tenants linked to OptiNexus are relayed. Network errors, 5xx and an event type that is not in the catalog yet
are retried with backoff (2 minutes doubling to 1 hour, 20 attempts); a refusal that retrying cannot fix (payload
does not match, application not assigned) parks the event as `FAILED`; fix the cause and run
`php artisan optinexus:relay-events --retry-failed`.

### Central logout in OptiFleet

Register `https://<fleet-api>/api/v1/auth/sso/backchannel-logout` as the back-channel logout URI of the OIDC client
(§2b). A logout in any application ends the user's OptiFleet API sessions; when access is revoked the account is also
deactivated, and it is switched on again only by the next SSO sign-in. Password sign-in for users OptiNexus has not
touched is unchanged, and OptiFleet works without OptiNexus.

## 7. OptiRadar (operators)

See `OptiRadar/docs/optinexus-sso.md` (settings `openid.*`, `openid.tenantClaim`,
`openid.tenantGroupAttribute`, one group per tenant with the `optinexusTenantId` attribute).
Set the application's launch URL to `https://<radar>/api/session/openid/auth` and the OIDC client's back-channel
logout URI to `https://<radar>/api/session/openid/backchannel-logout`. `OptiRadar/setup/traccar-optinexus.xml` is a
sample configuration (creates tenant users on the first SSO login with `users.defaultDeviceLimit=0`). OptiRadar and
OptiRadar-web are forks that must be built and deployed (server `./gradlew assemble`, web `npm ci && npm run build`);
the stock Traccar image has none of this.

### OptiRadar events

OptiRadar reports five events to `POST /api/v1/events` (key prefix `optiradar.`): `device.online`, `device.offline`,
`geofence.entered`, `geofence.exited` and `device.overspeed`. Each one carries `data.aggregate_type` (`device`),
`data.aggregate_id` (the OptiRadar device id), `data.correlation` (the device's unique id) and `data.payload`
(device name, unique id, position, speed in km/h, geofence name, depending on the event). Only devices that belong to
a tenant group (`optinexusTenantId`) are reported, and the group's tenant is the event's `tenant_id`. OptiRadar writes
each event to its own outbox table first and sends it later under the outbox `event_id`, so OptiNexus being down loses
nothing and a retry never makes a second event. To switch it on:

1. Register the five events: `php artisan db:seed --class=OptiRadarEventCatalogSeeder` (the application with code
   `optiradar` must exist, see `OptiRadarApplicationSeeder`; running it again only updates the entries).
2. Create a service account for the OptiRadar application with the `event.write` scope and assign the OptiRadar
   application to the tenants.
3. In OptiRadar set `optinexus.events.enable`, `optinexus.baseUrl`, `optinexus.clientId` and
   `optinexus.clientSecret`; online/offline events also need `event.status.enable=true`. See
   `OptiRadar/docs/optinexus-sso.md`.

Network errors, 5xx and an event type that is not in the catalog yet are retried with backoff (2 minutes doubling to
1 hour, 20 attempts); a refusal that retrying cannot fix parks the event as `FAILED`.

