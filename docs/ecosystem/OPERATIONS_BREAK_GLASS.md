# Operators and break-glass (R07, A02, A24)

## Who is an operator

An operator is a user who holds `cgo.ecosystem.access` through a **global** (SYSTEM) role, for example `PLATFORM_SUPERADMIN`
or a narrower ecosystem role. Nobody becomes one through a tenant role, a tenant-admin flag, an application role or a request
payload. Only operators sign in to the Nexus portal or call the admin API; tenant users and Admin Tenant use their product, which
signs them in through the Nexus identity provider (OIDC code + PKCE) and manages their users through the delegation API.

## Break-glass: the only local credential

The only password-based path left is the bootstrap operator. There is no password fallback for tenant users in any product.

| Situation | Action | Audit record |
|---|---|---|
| First install | `CGO_SUPERADMIN_EMAIL` / `CGO_SUPERADMIN_PASSWORD` are read by the seeder once; an existing account keeps its password | `operator.bootstrap_created` |
| Lost operator password | run the seeder with `CGO_SUPERADMIN_RESET_PASSWORD=true` and the new password in the environment (deployment access required, so the person doing it already controls the server) | `operator.break_glass_password_reset` |
| Operator signs in | normal `POST /auth/login` or the portal | `auth.login` / `auth.login_failed` |
| Tenant user or Admin Tenant tries the portal or admin API | refused with `403 ECOSYSTEM_ACCESS_REQUIRED` | `authorization.ecosystem_denied`, `auth.login_failed` (`reason: ecosystem_access_required`) |
| Admin Tenant changes users or roles from a product | delegation API, inside the delegable set | `delegation.*` with the acting user |

Rules: operators are named people (no shared account), remove `CGO_SUPERADMIN_PASSWORD` from the environment after bootstrap, and
review `operator.*` and `authorization.ecosystem_denied` entries in the audit log regularly.

## When Nexus is unreachable

Products must fail closed. Authorization and product-context answers are reused for at most `ttl_seconds` (300 s); after that, or
when Nexus cannot confirm a user, the product ends the session or denies the action. Existing local data stays; new sign-ins wait
for Nexus. Restoring Nexus restores access without any product-side change; do not create local accounts to work around an outage.

## Legacy local authority

Installations that still hold local users, roles or tenants do not meet R01-R08. See `LEGACY_MIGRATION_PLAN.md`: the plan inventories
identities and grants, links them to Nexus accounts one by one, and never migrates a user database automatically.
