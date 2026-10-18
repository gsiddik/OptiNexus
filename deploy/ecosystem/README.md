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

## Mail for invitations (OptiNexus)

New people are added from a product by an invitation link that OptiNexus e-mails (`docs/ecosystem/GOVERNANCE_CONTRACTS.md` section 2b). Set the
mail transport in `.state\nexus.env` (never in Git): `MAIL_MAILER=smtp`, `MAIL_HOST`, `MAIL_PORT`, `MAIL_USERNAME`, `MAIL_PASSWORD`,
`MAIL_SCHEME` (empty, `smtp` or `smtps`), `MAIL_FROM_ADDRESS`, `MAIL_FROM_NAME`; `INVITATION_TTL_HOURS` (default 72) is optional. The default
`MAIL_MAILER=log` delivers nothing: `ecosystem:preflight` reports it as a warning. For a local trial point `MAIL_HOST` at a local SMTP sink
(no real mail is sent); the worker container sends the mail, so the queue worker must be running. `NEXUS_DELEGATION_DIRECT_USERS=false`
switches off the deprecated direct user creation (`POST .../users`) once every product uses invitations.

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
