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
| D3 | OptiRadar tenant isolation: one Traccar Group per OptiNexus tenant. | Confirmed by owner |
| D4 | SSO protocol: OpenID Connect (authorization code + PKCE), OptiNexus as the provider. | Default |
| D5 | OptiNexus has no `main` branch yet; this work branches from `claude/cgo-phase-1-governance-qtmik1`. | Default |

## 3. Components

```
                 ┌────────────────────────── OptiNexus ───────────────────────────┐
 Browser ──SSO──▶│ OIDC Provider  /.well-known, /oidc/authorize|token|userinfo|jwks│
                 │ Governance API /api/v1/* (tenants, users, apps, subscriptions)  │
 Apps ──M2M────▶ │ API Gateway    /api/gateway/v1/*  (client-credentials tokens)   │
                 │   ├ Vehicle link registry (fleet vehicle ⇄ telematics device)  │
                 │   ├ Telematics odometer store (append-only, cursor feed)       │
                 │   └ Connectors: OptiRadar (Traccar REST pull)                  │
                 └────────────────────────────────────────────────────────────────┘
        ▲ OIDC + gateway client                      ▲ OIDC (built-in Traccar client)
   OptiFleet (Laravel)                         OptiRadar (Traccar) + OptiRadar-web
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
users by `users.optinexus_subject`, falling back to e-mail on first login.
Unknown users are created just in time with a membership in the linked tenant.

**OptiRadar** uses Traccar's built-in OpenID client (`openid.*` keys) with
`openid.allowGroup=optiradar`. A small server change links the signed-in user
to the Traccar Group whose attribute `optinexusTenantId` matches the
`tenant_id` claim (D3), so they see only their tenant's devices.

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

**OptiRadar connector**: a scheduled job (`gateway:sync-telematics`) reads
Traccar `/api/groups`, `/api/devices` and `/api/positions` with a Traccar
service token stored as an integration credential. The tenant of a device is
the `optinexusTenantId` attribute of its group. Odometer is the position's
`odometer` attribute when the device reports it, otherwise `totalDistance`;
both are metres and converted to kilometres with decimal arithmetic.

**Auto-linking**: a device is linked to a fleet vehicle of the same tenant when
the normalized registration number (upper-case, no spaces or dashes) of the
vehicle equals the device's `registrationNumber` attribute or, failing that, its
name. Admins can override links; manual links win.

### 3.3 Odometer flow (D1)

1. Traccar computes distance per device.
2. OptiNexus connector stores a reading (`tenant`, `device`, `value_km`,
   `recorded_at`), idempotent on `(tenant, source, device, recorded_at)`.
3. OptiFleet `optinexus:sync` (scheduled) pushes its vehicle directory, then
   pulls readings after its saved cursor.
4. OptiFleet locks the vehicle row, stores the reading in
   `vehicle_odometer_readings` (unique per source reference) and sets
   `current_odometer = max(current, reading)`. Lower values are kept in history
   but never move the odometer back.

## 4. Security

* Tenant is never taken from client input without checking it against the
  caller's service account or the user's memberships.
* OIDC: exact redirect URI match, PKCE S256 supported (required for public
  clients), single-use codes valid 5 minutes, hashed secrets, codes and tokens.
* Gateway uses the existing SSRF-safe HTTP client for outbound connector calls.
* All credentials (Traccar token, client secrets) live in environment config or
  encrypted integration credentials, never in the repository.

## 5. Contract for new platforms

See `docs/integration/INTEGRATION_GUIDE.md` and `docs/openapi.yaml`
(tags `OIDC`, `Gateway`).
