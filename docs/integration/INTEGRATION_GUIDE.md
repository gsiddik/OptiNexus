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
`reading_id`; treat `odometer_km` as a decimal string (never a float); a reading can be
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
