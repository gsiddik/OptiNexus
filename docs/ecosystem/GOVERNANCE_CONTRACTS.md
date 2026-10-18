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
The ceiling is enforced when a role is written or assigned; it is not re-applied to roles that already exist. Clearing a permission's `delegable`
flag, or a capability lapsing, stops new grants at once but does not strip the permission from existing delegated roles (known limit, reviewed
2026-10-18): a product that must honour a lapsed capability gates on `entitled_capabilities` in the effective-permissions answer.

Refusals: `PERMISSION_NOT_DELEGABLE` (422, with `details.permissions`), `ROLE_NOT_ASSIGNABLE` (422), `ROLE_NOT_EDITABLE` (404),
`ROLE_IN_USE` (409), `USER_NOT_IN_TENANT` (404), `TENANT_ADMIN_PROTECTED` (409), `IDENTITY_NOT_LINKABLE` (409: operator,
disabled/suspended/deleted identity), `IDENTITY_NOT_VERIFIED` (409: an identity of another tenant whose e-mail was never verified is not attached
by e-mail alone; invite it instead), `INVITATION_PENDING` (409: a newer invitation for the e-mail is open), `MEMBERSHIP_NOT_ACTIVE` (409), `SELF_REVOCATION_DENIED` (409), `TENANT_MISMATCH`,
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
its hash are never part of any API answer. The list answers `{data: [view...], meta: {pagination}}` like `/users`. `delivery.error_code` is
`MAIL_TRANSPORT_FAILED` or null (no other value exists). `can_resend` is false once the invitation is accepted or revoked or the resend limit
is reached (the 60 s cooldown is not part of it: it answers `INVITATION_RESEND_COOLDOWN`); `can_revoke` is true for `PENDING` and `EXPIRED`.
An `EXPIRED` invitation does not block a new invitation for the same e-mail: creating again replaces it (the old row stays as history).

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
Seat limits (`user_limit`) are enforced centrally for every way of adding access, invitations and `POST /users` included (section 2d).

## 2c. First Admin Tenant created by an operator, without e-mail (owner decision 2026-10-18, second batch) - operator API

For the FIRST administrator of a tenant (nobody exists yet to invite them). Not available to products, tenant users or Admin Tenant: the routes sit
under `/api/v1/ecosystem` (human operator token, `ecosystem` gate of section 1). **No e-mail of any kind is sent by this flow** (no invitation, OTP,
verification or notification; asserted by a test with `Mail::fake()` and `Queue::fake()`). Later users still come in by invitation (section 2b).
Nexus owns the identity; the e-mail address is a login/profile attribute, the authoritative identity is the stable central subject (`sub`).

Base `/api/v1/ecosystem/tenants/{tenant}/admin-accounts` (answers use the usual `{success, data}` / `{success:false, error:{code,message,details}}` envelope).

| Method | Path | Permission | Purpose |
|---|---|---|---|
| GET | `/` | `cgo.tenant.view` (tenant) | Admin Tenant accounts of the tenant with their activation view (never a credential) |
| POST | `/` | `cgo.tenant.admin.assign` (tenant); the service also needs `cgo.user.create` (new account), `cgo.user.tenant.assign`, `cgo.user.application.assign`, and `cgo.user.role.assign` when roles are sent | Create a new identity or link an existing one as Admin Tenant, with application access and optional roles. 201 |
| POST | `/{user}/email-verification` | `cgo.user.email.verify` (own permission, PLATFORM_SUPERADMIN only; not in PLATFORM_ADMIN's `*`) | Record the manual e-mail verification. 200 |
| POST | `/{user}/temporary-credential` | `cgo.tenant.admin.assign` (tenant) | Issue a new temporary password for an account that has not chosen a password yet. 200 |

`POST /` body: either a **new** account (`name`, `email`) or an **existing** one (`user_id` + `confirm_email`; never both, an existing account is never found by
e-mail), plus `application_ids[]` (required, subscribed ACTIVE applications of the tenant, never `cgo`) and `role_ids[]?` (TENANT roles of this tenant or APPLICATION roles
of the selected applications; platform roles are refused). **No password field exists**: Nexus generates the temporary password. The tenant must be usable.
The privilege-escalation guard applies: the operator can hand over only what they could grant one step at a time in this tenant. Nothing is seeded to widen it.

Response `data`: `user{id,name,email,status,created_at}`, `membership{status,is_tenant_admin}`, `applications[]`, `roles[]`, `activation{managed, state, email{verified,
verified_at, method, verified_by}, password{change_required, issued_at, expires_at, expired, locked_seconds, changed_at}, activated_at, next_steps[]}`; on create also
`outcome` (`created`|`linked`) and `temporary_credential{password, expires_at, shown_once:true}`. **`temporary_credential.password` appears only in the answer of the
create (new identity) and of the reissue; never in a list, a later read, the audit trail, the logs or a frontend store.** These answers carry
`Cache-Control: no-store, private`. A linked existing identity gets `temporary_credential: null`: its password is not shown, replaced or reset.

`activation.state`: `null` (never in this flow), `PASSWORD_CHANGE_REQUIRED`, `CREDENTIAL_EXPIRED`, `EMAIL_VERIFICATION_PENDING` (password chosen, address not verified yet),
`ACTIVATED`. The account stays `INVITED` (which every access check already refuses) until BOTH the address is verified AND the holder replaced the temporary password; whichever
comes last completes the activation (`INVITED` -> `ACTIVE`) in the same transaction under a row lock. `next_steps[]` is a subset of `verify_email`, `reissue_credential`,
`hand_over_credential`.

`POST /{user}/email-verification` body: `email` (must equal the account's current address, case-insensitive) and `reason` (method and reason of the out-of-band check, 10-500
characters; no identity documents are collected). Refusals: `SELF_VERIFICATION_DENIED` 403 (operator verifying their own address; audited `user.email_verification_denied`),
`USER_NOT_IN_TENANT` 404, `ACCOUNT_NOT_ELIGIBLE` 409 (suspended/disabled), `EMAIL_MISMATCH` 422, `EMAIL_ALREADY_VERIFIED` 409, validation 422. It sets `email_verified_at`,
`email_verification_method = OPERATOR_MANUAL` and `email_verified_by` and audits `user.email_verified_manually` (target user, verified address, operator, time, reason). It never
changes a password, subject or membership. **A later change of the e-mail address clears the verification** (model hook; audited `user.email_verification_revoked`), so the new
address needs an explicit re-verification; an invitation sets `email_verification_method = INVITATION`.

`POST /{user}/temporary-credential`: only for an `INVITED` account in this flow that has not chosen a password (`ACTIVATION_NOT_PENDING` 409, `PASSWORD_ALREADY_CHOSEN` 409); the
previous temporary password stops working at once; audited `user.temporary_password_reissued` (expiry, never the value).

Other refusals of `POST /`: `TENANT_NOT_ACTIVE` 409, `APPLICATION_NOT_SUBSCRIBED` 422, `ROLE_NOT_ASSIGNABLE` 422, `PRIVILEGE_ESCALATION_DENIED` 403, `IDENTITY_CONFLICT` 409 (a
new-account request whose e-mail exists; nothing is adopted by e-mail), `IDENTITY_NOT_FOUND` 404, `EMAIL_CONFIRMATION_MISMATCH` 422, `IDENTITY_NOT_LINKABLE` 409 (deleted, not
active, an operator, or still in its own activation), `MEMBERSHIP_NOT_ACTIVE` 409. Audit: `tenant_admin.bootstrap_created|bootstrap_linked`.

**Temporary password.** 20 characters from `ABCDEFGHJKLMNPQRSTUVWXYZ23456789` (about 100 bits; shown in groups of five; typed case-insensitively without dashes), stored only as the
account's normal password hash, single use (replacing it consumes it), expires after `NEXUS_TEMP_PASSWORD_TTL_HOURS` (default 48; an expired one is replaced, never extended),
at most `NEXUS_TEMP_PASSWORD_MAX_ATTEMPTS` wrong attempts (default 5) per account, then locked for `NEXUS_TEMP_PASSWORD_LOCKOUT_SECONDS` (default 900), plus the per-address
throttle on the sign-in route (10/min) and on the activation route (10/min). The operator delivers it out of band.

**First sign-in (browser, on Nexus).** The product starts the normal OIDC authorization (code + PKCE S256). `/oidc/login` accepts the temporary password for an account in this
flow only to open a short **bootstrap session**; it does not authorize the product. The visitor is sent to `/oidc/activate` (EN or ID, `?lang=` or `Accept-Language`; pages are
`no-store`, no-referrer, not framable), where they set a new password (min 12 characters, not equal to the temporary one). One transaction under a row lock re-checks state,
expiry, issue time (a reissue cuts the session off) and the temporary password, so of several concurrent submissions exactly one succeeds. On success the temporary
password is consumed, all sessions of the account (including the bootstrap session) are ended, and: if the address is already verified the account becomes `ACTIVE` and the
visitor signs in with the NEW password and continues the pending authorization (code + PKCE) to the product; if not, the page says that activation waits for the operator and the
product is not opened. Wrong/used/expired/locked states are separate pages. The change gives **no Nexus portal access**: the ecosystem gate (section 1) is unchanged for tenant users.
Doors that would bypass this flow are closed for an account in it: the delegation API (`POST /users`, link-existing) and invitation acceptance refuse it
(`IDENTITY_NOT_LINKABLE`), and `PUT /users/{id}/activate` answers `409 ACTIVATION_NOT_COMPLETE`.

Fleet still requires `email_verified` in the ID token (no global bypass); it receives the claim from Nexus exactly as before. The onboarding report (`/ecosystem/onboarding`)
reports a pending Admin Tenant as `tenant_admin_activation_pending`.

## 2d. Seat limits (owner decision 2026-10-18 09:11Z, item 4 of `DECISION_PACKAGE.md`) - central, in OptiNexus

**One authority.** OptiNexus counts and enforces seats per tenant and application; a product shows the figures and passes the refusals on, it keeps no counter.
Computed on demand from the source rows, never stored (nothing to drift): **used** = distinct, non-deleted people holding an ACTIVE `user_application_access`
(once, whatever their roles; suspended people still count while the assignment exists; an Admin Tenant counts; service principals are not people and are not
counted: they are identified by the immutable identity type, never by an e-mail pattern); **reserved** = open, unexpired invitations whose recipient (lower-cased
e-mail) holds no assignment (and so a resend adds nothing, an accepted invitation turns its reservation into usage without a double count, an expired or revoked
one releases it, and an invitation past `expires_at` stops reserving at once, before the sweeper marks it). Revoking the assignment of one product releases that
product's seat only; identity, membership, history and other products' access stay.

**Which products.** An application is seat-controlled only when its manifest declares `user_limit` in `entitlement_limit_keys` (Entry does; Fleet and Radar declare
none, so their state is `NOT_CONTROLLED`: no limit is applied and none is invented). The limit is the tenant's `user_limit` entitlement; there is no number in code,
config or a seeder. State of the limit: `LIMITED` (explicit number), `UNLIMITED` (explicit), `NOT_CONTROLLED`, `ENTITLEMENT_MISSING`, `ENTITLEMENT_INVALID`
(non-numeric, negative, fractional, conflicting overrides), `ENTITLEMENT_UNAVAILABLE` (the source could not be read). Missing, invalid or unreadable never means
unlimited: additions are refused (existing access is never touched).

**Rule.** Reserve (invitation create, reopening an expired one): nothing if the person already holds a seat or a reservation, else `used + reserved + 1 <= limit`.
Take (direct add, delegation add, operator or first-Admin-Tenant bootstrap, acceptance): nothing if the person already holds the seat; with a reservation of
their own `used + 1 <= limit`, otherwise `used + reserved + 1 <= limit`. A limit lowered below usage: nothing is removed or cancelled, `available` stays 0 (never negative),
`over_limit` is true with `over_by`, additions stay refused until usage and reservations fit again; reducing usage is always allowed. A pending invitation that no longer
fits when it is accepted is refused at acceptance (the page says so, the invitation stays PENDING until it expires or is revoked) with the recovery below.

**Paths.** Every code path that grants access goes through `SeatService::grantAccess` / `authorize` (a static test fails when code writes `user_application_access`
elsewhere): delegation add (create or link a person), invitation create / resend / accept, operator user and access grants, first Admin Tenant bootstrap, seeders. No operator bypass.
**Atomicity.** A transaction-scoped advisory lock per tenant and application (`seat:<tenant>:<app>`), taken first, polled up to `SEATS_LOCK_WAIT_SECONDS` (5);
several pairs lock in sorted order. No lock is held across a network or mail call. If the lock stays busy the answer is `SEAT_CONTENTION` (retry), never a half change.

`GET /api/v1/delegation/tenants/{tenant}/seats` (scope `tenant.delegate`, acting user as in section 2) and `GET /api/v1/tenants/{tenant}/seats` (operator, `cgo.tenant.view`) answer the
picture of one product: `{application_code, controlled, state, limit, unlimited, used, reserved, available, over_limit, over_by, can_add, blocked_reason, limit_source}`.
The same object is `meta.seats` of the delegation users and invitations lists. `available` is null unless `LIMITED`; `can_add` is the server's verdict now (UX only).

Refusals (envelope of section 1; `details` carries `application_code`, `limit`, `used`, `reserved`, `available`, `over_limit`, `recovery`; this tenant's numbers only, never another
tenant's or product's; audit `seat.denied` with the code and numbers, never a credential or token):

| Code | HTTP | Meaning | `details.recovery` |
| --- | --- | --- | --- |
| `SEAT_LIMIT_REACHED` | 409 | no seat left for this addition | `FREE_SEAT_OR_RAISE_LIMIT` |
| `SEAT_OVER_LIMIT` | 409 | usage + reservations already exceed the limit | `REDUCE_USAGE_OR_RAISE_LIMIT` |
| `SEAT_ENTITLEMENT_MISSING` | 409 | the controlled product has no `user_limit` entitlement for this tenant | `SET_ENTITLEMENT` |
| `SEAT_ENTITLEMENT_INVALID` | 409 | the entitlement value cannot be used | `FIX_ENTITLEMENT` |
| `SEAT_ENTITLEMENT_UNAVAILABLE` | 503 + `Retry-After` | the limit could not be read now (nothing was added) | `RETRY` |
| `SEAT_CONTENTION` | 503 + `Retry-After` | the seat lock was busy (nothing was added) | `RETRY` |

Consumers: the 503 pair is a refusal, not an outage (the change was not made). Versions: additive to contract 1.x (new codes, new `meta.seats`, new `/seats`); a product that does
not read them keeps working. Compatible SHAs are in `ECOSYSTEM_AUDIT_STATUS.md` section 5d. Existing data: nothing is deleted, backfilled or corrected to fit a quota;
`php artisan ecosystem:seats:inventory [--tenant= --application= --only-problems --json --strict]` is the read-only preview (used, reserved, limit, over-limit, anomalies such as
an ACTIVE assignment of a deleted user), also the migration plan input. After deploying, re-register the manifest of products that declare `user_limit` so Nexus knows the key.

## 3. Product context for switchers (A29) - scope `authorization.check`

Owner decision 11 follow-up (2026-10-18): Entry, like Fleet, requires a verified email on every Nexus SSO login.
For scope `email`, existing `OidcTokenService::userClaims` emits boolean `email_verified` from Nexus verification state.
Invitation acceptance and audited manual operator verification both produce true. Entry requires signed ID-token boolean true
before local provisioning/linking/ticket issuance; missing or other types deny with `email_not_verified`.
This is a next-login requirement, not a new live-session revocation policy. See `SSO_EMAIL_VERIFICATION_REVIEW.md`.

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

