# Nexus governance contracts (R01-R08, A02, A23-A25, A29)

Status: implemented in OptiNexus branch `claude/project-thread-0cf20u`. This is the contract the three products (OptiFleet,
OptiRadar, OptiEntry) code against. All calls are server to server with the product's own service account (OAuth2
client credentials, `POST /api/v1/oauth/token`); the browser never talks to these endpoints.

## 1. Portal and admin API are for operators only (R07, A24)

* Permission `cgo.ecosystem.access`, held only through a global (SYSTEM) role. Every human-authenticated route under
  `/api/v1` carries the `ecosystem` middleware, except `POST /auth/logout` (a token must always be revocable).
* `POST /auth/login` checks the credentials, then refuses with `403 ECOSYSTEM_ACCESS_REQUIRED` and issues no token.
  Any other admin route answers the same code. Tenant users and Admin Tenant therefore cannot use the portal or the admin API
  by UI, direct URL or API.
* Not blocked: OIDC endpoints (`/oidc/*`, `/.well-known/*`, token, userinfo, JWKS, back-channel logout), `/oauth/token`, every
  `service_account:` route (authorization, delegation, events, gateway, ...).

## 2. Delegation API (R08, A25) - scope `tenant.delegate`

Base `/api/v1/delegation/tenants/{tenant_id}`. The service account must be bound to ONE application (never `cgo`) and, if bound
to a tenant, to this tenant. Every request carries `acting_user_id` (the Nexus user id = OIDC `sub` of the signed-in
Admin Tenant; query for GET/DELETE, body otherwise). Nexus verifies on every call: tenant usable; application assigned and
ACTIVE for the tenant; acting user is an active member with the Admin Tenant flag (set only in the portal) and has access to the
application. Any failure of the acting user answers `403 ACTOR_NOT_TENANT_ADMIN` (one code, no enumeration).

| Method | Path | Purpose |
|---|---|---|
| GET | `/catalog` | delegable permissions of the application for this tenant + assignable roles |
| GET | `/users?search=&per_page=` | tenant users that have access to the application (with their application roles) |
| POST | `/users` | create or link a user: `email`, `name`, optional `initial_password` (>= 12 chars, new identities only), optional `role_ids[]`. Outcome `created` (201) / `linked` / `existing` (200) |
| PUT | `/users/{user}/roles` | replace the delegated application roles of the user: `role_ids[]` |
| DELETE | `/users/{user}` | remove the application (and its roles) from the user; the identity and membership stay; back-channel logout is queued |
| POST | `/roles` | create a custom role: `name`, `description?`, `permissions[]` (permission keys) |
| PUT | `/roles/{role}` | rename / replace permissions (only roles created through delegation, for this tenant and application) |
| DELETE | `/roles/{role}` | delete such a role (409 `ROLE_IN_USE` while assigned) |

The delegable set is explicit: `permissions.delegable = true` AND status ACTIVE AND (no capability, or the capability is ACTIVE
and the tenant holds an active CAPABILITY entitlement for it). It is never derived from what the Admin Tenant holds
personally. Roles created here are APPLICATION roles bound to the tenant and the application (`origin = DELEGATION`).

Refusals: `PERMISSION_NOT_DELEGABLE` (422, with `details.permissions`), `ROLE_NOT_ASSIGNABLE` (422), `ROLE_NOT_EDITABLE` (404),
`ROLE_IN_USE` (409), `USER_NOT_IN_TENANT` (404), `TENANT_ADMIN_PROTECTED` (409), `IDENTITY_NOT_LINKABLE` (409: operator,
disabled/suspended/deleted identity), `MEMBERSHIP_NOT_ACTIVE` (409), `SELF_REVOCATION_DENIED` (409), `TENANT_MISMATCH`,
`SERVICE_ACCOUNT_NOT_BOUND`, `APPLICATION_NOT_DELEGABLE`, `APPLICATION_NOT_SUBSCRIBED`, `TENANT_NOT_ACTIVE`,
`APPLICATION_NOT_ACTIVE` (403). Not reachable by design: other tenants, other applications, `cgo.*`, ecosystem roles,
plans/entitlements/subscriptions, the Admin Tenant flag, tenant-wide membership of other products. Every change is audited
(`delegation.*`, actor = the Admin Tenant, metadata = service account).

A product keeps no second copy of identity or roles: it renders the answers above, sends changes through this API, and reads
permissions with `POST /authorization/effective-permissions` (bulk, schema 1.0).

## 3. Product context for switchers (A29) - scope `authorization.check`

`POST /api/v1/authorization/product-context` `{user_id, tenant_id}` -> `{schema_version, user_id, tenant{id,code,name}|null,
application_code, allowed, deny_reason, products[{code,name,launch_url,current}], ttl_seconds}`. The application is the
service account's. `allowed=false` means the caller's own session must end (`deny_reason` uses the logout vocabulary:
`user_suspended`, `tenant_membership_removed`, `application_access_revoked`, `application_not_assigned`, ...). Only products the
tenant subscribes to AND the user was assigned appear; the governance portal never does. Cache at most `ttl_seconds` (300) and
revalidate on every switcher render and every navigation to another product.

Switching: link to the target's `launch_url` + `?tenant_hint=<tenant id>`. The target's SSO start forwards `tenant_hint` to
`/oidc/authorize` (code + PKCE, existing SSO session at Nexus: no new credentials, same tenant). No token, code or
secret ever appears in a URL other than the standard `code`/`state` of the authorization-code flow.

## 4. Manifest (A02)

Each product exposes its catalog as a versioned manifest (`manifest_version`, `revision`): capabilities (module/feature), permissions
with `capability_code` and `delegable`. Nexus imports it additively (never deletes); see `MANIFEST_IMPORT.md`.
