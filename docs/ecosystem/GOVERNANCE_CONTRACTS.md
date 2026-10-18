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

## 2b. Invitations (onboarding by invitation link; owner decision 2026-10-18) - scope `tenant.delegate`

The normal way to add a NEW person from a product. Nexus owns identity, e-mail verification, membership and application access; the
product only renders and sends. Nothing is granted when an invitation is created: identity, membership, application access and roles
are granted when the invitee accepts, after Nexus re-validates everything. No password and no token ever passes through the Admin
Tenant or the product backend: the link is e-mailed by Nexus and the invitee sets their own password on a Nexus page.

Same base, authentication and `acting_user_id` rule as section 2 (Admin Tenant of this tenant with access to this application).

| Method | Path | Purpose |
|---|---|---|
| POST | `/invitations` | `email`, `name`, `role_ids[]?` (delegable, assignable roles of this application), `locale?` (`en`\|`id`, default `en`; language of the e-mail and of the Nexus page). 201 with the invitation view; the e-mail is queued |
| GET | `/invitations?status=&search=&per_page=` | invitations of this tenant and application, newest first. `status` = `PENDING`\|`ACCEPTED`\|`REVOKED`\|`EXPIRED` |
| POST | `/invitations/{id}/resend` | new token (the old link stops working at once), new expiry, e-mail queued again. Allowed for `PENDING` and `EXPIRED` |
| POST | `/invitations/{id}/revoke` | `reason?`. `PENDING`/`EXPIRED` -> `REVOKED`; already revoked answers 200 unchanged |

Invitation view (`data`): `id`, `email`, `name`, `status` (an unaccepted invitation past `expires_at` is reported as `EXPIRED`),
`locale`, `role_ids[]`, `roles[]{id,code,name}`, `invited_by{id,name}`, `created_at`, `expires_at`, `last_sent_at`, `send_count`,
`delivery{status: QUEUED\|SENT\|FAILED, attempted_at, error_code}`, `accepted_at`, `revoked_at`, `can_resend`, `can_revoke`. The token and
its hash are never part of any API answer.

Refusals (error envelope as in section 2): `INVITATION_PENDING` (409, one open invitation per e-mail, tenant and application; use
resend; `details.invitation_id`), `ALREADY_MEMBER` (409, active member with access to this application), `ROLE_NOT_ASSIGNABLE` (422),
`INVITATION_NOT_FOUND` (404, also for another tenant/application), `INVITATION_NOT_PENDING` (409, `details.status`),
`INVITATION_RESEND_COOLDOWN` (429, `Retry-After`), `INVITATION_RESEND_LIMIT` (409), `INVITATION_RATE_LIMITED` (429), validation 422.
Creating never reveals whether the e-mail belongs to an identity elsewhere; conflicts are decided at acceptance.

Acceptance (browser, on Nexus, not in the API): `GET /invitations/{token}` only validates and hands the visitor to
`/invitations/accept` (token leaves the URL, nothing is consumed, so mail scanners cannot burn it); a new identity sets name and
password; an existing identity must prove itself with its current password (never linked by e-mail alone). In one transaction with a
row lock Nexus re-checks: invitation still open and unexpired; tenant and application usable and subscribed; inviter still an active
Admin Tenant with access; every assigned role still assignable (fail closed, nothing is dropped silently); identity not disabled,
suspended, deleted or an operator; membership not suspended. Then it creates or links the identity, records `email_verified_at`
(possession of the mailbox proven by the single-use token), grants membership, application access and exactly the invited roles
(never more, and existing roles of a linked user are kept), and marks the invitation `ACCEPTED`. The user then continues to the
product, which starts the normal OIDC code + PKCE sign-in; no session is opened by the invitation itself.

Token: 256-bit random, only its SHA-256 is stored, valid until `expires_at` (default 72 h, `INVITATION_TTL_HOURS`), single use,
replaced by resend, dead after revoke. Audit: `invitation.created|sent|send_failed|resent|revoked|expired|accepted|email_verified|accept_denied`
(never the token). Mail is configured at runtime (`MAIL_*`, no secret in Git); a deployment on the `log`/`array` mailer is reported by
`ecosystem:preflight` as not deliverable.

Compatibility: `POST /users` keeps working (including `initial_password`) but is deprecated: the response carries
`Deprecation: true` and `Link: <.../invitations>; rel="successor-version"`, `NEXUS_DELEGATION_DIRECT_USERS=false` switches it off per
deployment (`410 DIRECT_USER_CREATION_DISABLED`). Users created that way have no verified e-mail and cannot sign in to products that
require one (Fleet). Products should stop calling it; switching the default off is an owner decision (`DECISION_PACKAGE.md`, item 3).
Seat limits (`user_limit`) are not enforced by invitations or by `POST /users`; the policy is an open owner decision (item 4).

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

## 5. Service-account scope allow-list and entitled capabilities (A23, A02)

* `service_accounts.allowed_scopes` (JSON, nullable). A token may only use a scope its own account allows: otherwise
  `403 SCOPE_NOT_ALLOWED`, even when the token carries the scope. `NULL` = accounts registered before the list existed (any
  registered scope, unchanged). `ecosystem:register-service-account` gives a new account `ServiceAccount::PRODUCT_SCOPES`
  (authorization, governance read, audit, introspect, entitlement, commercial read, usage, events, `tenant.delegate`,
  `access.evaluate`); gateway/telematics scopes are never in the default and must be named with `--scopes=`. `--scopes=` on an
  existing account replaces the list (audit `service_account.scopes_updated`); `PUT /service-accounts/{id}/scopes` does the same
  for an operator. Unknown scopes are refused.
* `POST /authorization/effective-permissions` also returns `entitled_capabilities: [{code, type}]` (additive, schema 1.0): the
  ACTIVE modules/features of the caller's application the tenant is entitled to now; empty when access is denied. Products enforce
  their module/feature gates from this one answer (same TTL as the permissions).
* Not done (documented, no correctness impact): a bulk "who holds permission X" query (approver resolution still scans linked
  members), `subscriptions[].application_codes` in `commercial-context`, and a user-limit entitlement in the delegation API (no user-limit
  key exists in the catalog yet: business decision, see the decision list).

