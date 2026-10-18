# Opti ecosystem on one machine (Windows Podman Desktop, Docker, Linux, macOS)

`ecosystem.ps1` runs OptiNexus (always) plus any subset of OptiFleet, OptiRadar and OptiEntry. Each product keeps its own Compose project,
images and volumes, defined in **its own repository**; this folder only adds what they share. It is the one canonical place for cross-repo
deployment tooling (there is no sixth repository).

| Combination (R04) | Command |
| --- | --- |
| Fleet | `.\ecosystem.ps1 up -Products fleet` |
| Radar | `.\ecosystem.ps1 up -Products radar` |
| Entry | `.\ecosystem.ps1 up -Products entry` |
| Fleet + Radar | `.\ecosystem.ps1 up -Products fleet,radar` |
| Fleet + Entry | `.\ecosystem.ps1 up -Products fleet,entry` |
| Fleet + Radar + Entry | `.\ecosystem.ps1 up -Products fleet,radar,entry` |

Any subset works (the product list is the only catalogue; add a product by adding an overlay and an env template in the script). Products that
are not listed are not started. A product never waits for a peer: each one has its own bootstrap job and its own health checks, and the
integrations between products (Radar to Fleet, Fleet to Entry) are switched on per tenant inside the products, not here.

## What you need

* PowerShell 7 (`winget install Microsoft.PowerShell`; Windows PowerShell 5.1 also works), Git, and **Podman Desktop with a running machine** or Docker.
  Podman: `podman machine init --cpus 4 --memory 8192 --disk-size 60` then `podman machine start`. Rootless or rootful are both fine.
  A Compose provider is needed: Podman Desktop ships `docker-compose`/`podman compose`; plain `podman-compose` works with limits (it may
  ignore `service_completed_successfully`). `ecosystem.ps1 preflight` detects the machine, connection, provider, free ports and memory.
* Memory advice (machine total): Nexus 1 GB + Fleet 2.5 GB + Entry 1.3 GB + Radar 1.5 GB + 0.5 GB headroom, so about 7 GB for everything.
* Browser names: `nexus.localtest.me`, `fleet.localtest.me`, `entry.localtest.me`, `radar.localtest.me` resolve to 127.0.0.1 through public
  DNS. Offline, add them to the hosts file instead (Windows, as administrator: `C:\Windows\System32\drivers\etc\hosts`).

## Clone to first smoke test

```powershell
mkdir C:\opti; cd C:\opti
git clone https://github.com/gsiddik/OptiNexus.git
git clone https://github.com/gsiddik/OptiFleet-v2.git
git clone https://github.com/gsiddik/OptiAccounting.git        # product name OptiEntry
git clone https://github.com/gsiddik/OptiRadar.git
git clone https://github.com/gsiddik/OptiRadar-web.git
# check out the branch that carries the container package in each repository (see REVISIONS in the pull requests), then:
cd OptiNexus\deploy\ecosystem
.\ecosystem.ps1 init                                  # writes .state\*.env with generated secrets (never printed, never committed)
.\ecosystem.ps1 preflight -Products fleet,entry
.\ecosystem.ps1 build -Products fleet,entry           # first time only, or after pulling new code
.\ecosystem.ps1 up -Products fleet,entry              # Nexus first, then the products; waits for health
.\ecosystem.ps1 smoke -Products fleet,entry
```

Repositories are looked up next to OptiNexus (`OPTI_ROOT` overrides the parent folder; folder names are matched case-insensitively).
First sign-in to OptiNexus: `http://nexus-console.localtest.me:5180` with `CGO_SUPERADMIN_EMAIL` / `CGO_SUPERADMIN_PASSWORD` from `.state\nexus.env`.

URLs and ports (all published on 127.0.0.1 only; to change one, see Troubleshooting):

| Product | Console | API | Notes |
| --- | --- | --- | --- |
| OptiNexus | 5180 | 8100 (OIDC issuer) | `http://nexus.localtest.me:8100` |
| OptiFleet | 5173 (also serves `/api`, `/storage`) | 8000 | `http://fleet.localtest.me:5173` |
| OptiEntry | 5190 (also serves `/api`) | 8200 | `http://entry.localtest.me:5190` |
| OptiRadar + Web | 8082 | 8082 | `http://radar.localtest.me:8082`; device ports only through `docker-compose.devices.yml` |

**One issuer URL.** Every container listens on the port it publishes and joins the `opti-ecosystem` network under the product's public
name, so `http://nexus.localtest.me:8100` works from the browser (published port) and from inside any product container (network alias). Nothing
sends a browser to an internal container name, and `OIDC_REQUIRE_PUBLIC_ISSUER=true` makes OptiNexus refuse a loopback issuer.

## Connecting the products to OptiNexus (onboarding)

`init` creates every product in "OptiNexus is the authority" mode with empty client credentials. Register each product in OptiNexus (application,
OIDC client with the exact redirect URIs `<console URL>/api/v1/auth/sso/callback` for Fleet/Entry and `<radar URL>/api/session/openid/callback`
for Radar, service account), put the resulting client ids and secrets into `.state\<product>.env`, and run `ecosystem.ps1 up` again (containers are re-created,
nothing is rebuilt). Per-product steps: OptiEntry `docs/integration/OPTINEXUS_ONBOARDING.md`, OptiFleet `docs/status/OPTINEXUS_INTEGRATION_STATUS.md`, OptiRadar `docs/optinexus-sso.md`.

## Behind a proxy or a TLS edge (OptiNexus)

PHP only sees the address of the proxy in front of it unless it is told which proxies to trust. Without that, every IP-keyed control (login and
invitation throttles, the failed machine-authentication counter) shares one bucket for all callers, audit rows record the proxy, and behind a TLS edge
redirects and the session cookie are built for `http`. Set `TRUSTED_PROXIES` in `.state\nexus.env` (never in Git) to the proxy addresses or CIDRs
(comma separated; `*` only when the PHP container cannot be reached except through them). With it set, `X-Forwarded-For` and `X-Forwarded-Proto` are
honoured from those proxies only, and the session cookie becomes `Secure` on its own for `https` requests. The shipped UI nginx forwards both headers;
when a TLS edge sits in front of it, the UI nginx must pass the edge's `X-Forwarded-Proto` on instead of its own `$scheme`. This is a precondition for
exposing OptiNexus beyond a local trial; the default (unset) keeps the previous behaviour.

## Mail for invitations (OptiNexus)

Upgrade/backfill boundary: merging or deploying these changes never activates historical backfill or approves new financial posting.
Entry must be upgraded before backfill can be considered, and the applicable cutover, quarantine, review/reconciliation and owner approvals
must still be satisfied. See `docs/ecosystem/UPGRADE_AND_COMPATIBILITY.md`. Keep automatic activation out of bootstrap/seed/smoke steps.

New people are added from a product by an invitation link that OptiNexus e-mails (`docs/ecosystem/GOVERNANCE_CONTRACTS.md` section 2b). Set the
mail transport in `.state\nexus.env` (never in Git): `MAIL_MAILER=smtp`, `MAIL_HOST`, `MAIL_PORT`, `MAIL_USERNAME`, `MAIL_PASSWORD`,
`MAIL_SCHEME` (empty, `smtp` or `smtps`), `MAIL_FROM_ADDRESS`, `MAIL_FROM_NAME`; `INVITATION_TTL_HOURS` (default 72) is optional. The default
`MAIL_MAILER=log` delivers nothing and writes the invitation link (a credential) to the container log: `ecosystem:preflight` reports it as a warning, and it is acceptable for a local trial only. For a local trial point `MAIL_HOST` at a local SMTP sink
(no real mail is sent); the worker container sends the mail, so the queue worker must be running. `NEXUS_DELEGATION_DIRECT_USERS=false`
switches off the deprecated direct user creation (`POST .../users`) once every product uses invitations.

## First Admin Tenant of a tenant (operator runbook, no e-mail)

Contract: `docs/ecosystem/GOVERNANCE_CONTRACTS.md` section 2c. Done by a Nexus operator in the portal (tenant > Admins > "First Admin Tenant account"), **no e-mail is sent
at any step**. The tenant must be active and have its applications assigned. Verifying an address needs the permission `cgo.user.email.verify` (held by PLATFORM_SUPERADMIN only; it is
not part of PLATFORM_ADMIN's wildcard) and an operator can never verify their own address.

1. **Create** ("Create admin account"): name, e-mail, applications (and optional roles). Nexus shows a one-time temporary password. Copy it now: it is not stored, not e-mailed and cannot
   be shown again (if lost, use "New temporary password"). Status: *Account created*. An existing Nexus account is linked by its ID instead (it keeps its password; nothing is shown).
2. **Verify the e-mail address outside OptiNexus** (for example call the customer's known contact and have them confirm the address). Then "Record e-mail verification": retype the address,
   write the method and reason (10+ characters; no passwords or identity documents). Status: *E-mail verified, password change required*. It is audited with your name, the time and the reason.
3. **Hand the temporary password over personally**, not by e-mail or chat, and not through a ticket. It expires after `NEXUS_TEMP_PASSWORD_TTL_HOURS` (48 h by default); *Temporary password
   expired* means issue a new one. Five wrong attempts lock the account for 15 minutes.
4. The Admin Tenant opens the product (for example Fleet), is sent to OptiNexus, signs in with the temporary password and **must choose a new password** on the OptiNexus page. Then they
   return to the product through the normal sign-in (code + PKCE). Status: *Activation complete*. If step 2 is still open the page says so and the product stays closed.
5. If the address on the account changes later, the verification is cleared; record it again (step 2). The product (Fleet) keeps requiring a verified address.

Settings (`.state\nexus.env`, never in Git): `NEXUS_TEMP_PASSWORD_TTL_HOURS`, `NEXUS_TEMP_PASSWORD_MAX_ATTEMPTS`, `NEXUS_TEMP_PASSWORD_LOCKOUT_SECONDS`. The temporary password itself is never configured.
Existing deployments: `php artisan migrate` (additive) adds the columns and attaches `cgo.user.email.verify` to PLATFORM_SUPERADMIN; giving it to another role is a deliberate, audited choice.

## Seat limits (operator runbook)

Contract: `docs/ecosystem/GOVERNANCE_CONTRACTS.md` section 2d; owner decision 2026-10-18 (`DECISION_PACKAGE.md` item 4). OptiNexus is the only place that counts and enforces seats; a product only shows
the figures it is given. A seat is one person in one tenant for one product (several roles still count once; suspended people count; service accounts do not). A pending invitation reserves a seat until it
is accepted, revoked or expired. Every way of adding someone (invitation, delegation, direct creation, first Admin Tenant, operator assignment) takes the same decision.

* **Which products.** Only products whose manifest declares `user_limit` in `entitlement_limit_keys` (today OptiEntry). OptiFleet and OptiRadar declare none: they stay *not controlled*, nothing is invented
  for them. After upgrading OptiEntry, re-import its manifest (`MANIFEST_IMPORT.md`) so OptiNexus learns the contract; until then nothing is controlled.
* **Setting the limit.** The tenant's `user_limit` entitlement comes from its plan (and add-ons) at subscription activation. Seeders never create or widen one. For a running subscription a plan edit does
  not change what the tenant already holds (seen in the live check): record a tenant entitlement override instead (type `LIMIT`, key `user_limit`, the application, a reason; audited). **To change an
  override, suspend the old one first**: two active overrides with different values read as invalid and nobody can be added (`SEAT_ENTITLEMENT_INVALID`) until one is suspended. A seat-controlled
  product with no `user_limit` at all refuses additions (`SEAT_ENTITLEMENT_MISSING`); a missing, unreadable or invalid value is never read as unlimited. Unlimited is an explicit value.
* **Look before and after an upgrade** (read-only, changes nothing): `php artisan ecosystem:seats:inventory [--tenant=CODE] [--application=CODE] [--only-problems] [--json] [--strict]` lists used, reserved,
  limit, over-limit and anomalies per tenant and product (`--strict` exits non-zero when something needs attention).
* **Existing data.** Nothing is deleted, revoked or backfilled. A tenant already above its limit is reported *over the limit*: its people keep access, additions are refused
  (`SEAT_OVER_LIMIT`) until usage and reservations fit again, and reducing usage is always allowed. Revoking a product assignment frees that seat only; the identity, membership and history stay.
* **For support.** A refusal carries `application_code`, `limit`, `used`, `reserved`, `available`, `over_limit` and a `recovery` word (`FREE_SEAT_OR_RAISE_LIMIT`, `REDUCE_USAGE_OR_RAISE_LIMIT`,
  `SET_ENTITLEMENT`, `FIX_ENTITLEMENT`, `RETRY`); every refusal is audited as `seat.denied` (no credentials, tokens or links in it). `SEAT_CONTENTION` (503 with `Retry-After`) means another change
  held the tenant's seat lock; retry. There is no operator bypass: raise the limit through an override instead.

## Day-two operations

| Task | Command | Notes |
| --- | --- | --- |
| Status | `.\ecosystem.ps1 status` | which products run, health of each endpoint |
| Stop / start | `.\ecosystem.ps1 stop -Products entry`, `start -Products entry` | OptiNexus is touched only when named (`-Products all` for everything); data and keys persist |
| Remove containers | `.\ecosystem.ps1 down -Products all` | containers and network only; **volumes and `.state` are never deleted** |
| Backup | `.\ecosystem.ps1 backup -Products fleet,entry` | PostgreSQL dumps (all products incl. Nexus), Nexus signing keys, Fleet MongoDB and uploads, Entry uploads, with SHA-256 manifest, in `backups\` |
| Restore | `.\ecosystem.ps1 restore -Products fleet,entry -Backup <folder> -ConfirmReplace` | without `-ConfirmReplace` it only prints what would be replaced; it verifies checksums and takes a `pre-restore` backup first |
| Upgrade | `git pull` in each repository, then `.\ecosystem.ps1 upgrade -Products fleet,entry` | backup, rebuild, re-create; migrations are additive and run once in each product's bootstrap job |
| Roll back a settings change | `.\ecosystem.ps1 rollback-config` (list) / `-To <name>` | every `init` snapshots `.state\*.env`; then `up` |
| Roll back code | `git checkout <previous commit>` in the repository, `build`, `up` | additive migrations are not reversed; restore a backup if data must go back |
| Record revisions | `.\ecosystem.ps1 manifest` | writes `REVISIONS.lock.json` (branch + commit of every repository) |

Site-specific Compose tweaks (registry mirrors, memory limits) go in `.state\<product>.override.yaml`; it is appended automatically.
`COMPOSE_PROJECT_NAME` in a product env file renames its Compose project (a disposable test copy; the default names keep existing volumes).

## Troubleshooting

* `preflight` FAIL "Engine reachable": Podman: `podman machine start`; Docker: start Docker Desktop.
* Port in use: change the port in `.state\ecosystem.env` and the same value in that product's `.state\<product>.env` (and the matching `*_PUBLIC_URL`s), then `up`.
* `smoke` "reaches the issuer" FAIL: the container cannot read `http://nexus.<domain>:8100`; check that OptiNexus is up and both sit on `opti-ecosystem`.
* A bootstrap that fails prints its reason and the compose command stops; fix the setting and run `up` again. Waits are bounded (90 s database, 300 s schema).
* No autoheal and no Docker socket mount is used anywhere; `restart: unless-stopped` plus health checks are enough on a workstation.

## What was and was not exercised

See `docs/ecosystem/ECOSYSTEM_AUDIT_STATUS.md`. In short: the PowerShell script was run with PowerShell 7 against Docker for OptiNexus alone and with
Fleet, Entry and Fleet + Entry (preflight, up, smoke, stop/start, down/up, backup, restore, key persistence). **Not run:** Windows, Podman Desktop and
`podman compose`/`podman-compose`; the Alpine production images (the build sandbox blocked the Alpine mirror, an Ubuntu php-fpm image was used
with the same compose files, entrypoints and nginx templates); anything with OptiRadar (its image needs Maven Central and npm from inside the
build, unavailable there).
