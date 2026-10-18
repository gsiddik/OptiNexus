# Upgrade, compatibility and recovery (draft PRs Nexus #10, Fleet #36, Entry #10)

Status of this page: written during the AI-assisted pre-merge review of 2026-10-18. Everything marked *rehearsed* ran on throwaway databases in the
sandbox, never on an operational one. Nothing here has been run on production. Radar is out of scope (paused).

## 1. What was rehearsed

Method: base code (`git archive` of the base commit) installed and seeded on an empty scratch PostgreSQL database, then the PR head's migrations
and seeders run on that same database; every table hashed row by row (without `updated_at`) before and after. Fresh installs of the PR head too.

| Repo | Base to head | Migrations | Result of the upgrade (*rehearsed*) | Seeders after the upgrade |
|---|---|---|---|---|
| Nexus | `b4e77c7` to PR head | 6 added, none edited | one new table (`user_invitations`), new nullable/defaulted columns, no table or column dropped, no existing row changed. Two new permissions; `cgo.ecosystem.access` attached to the 20 global operator roles, `cgo.user.email.verify` to PLATFORM_SUPERADMIN only. No `user_limit` entitlement exists afterwards (seat inventory: every row NOT_CONTROLLED) | `ProductionSeeder` (container bootstrap): only a description and a permission name changed, platform-role wildcard re-attached; second run changes nothing. The developer path (`db:seed`) re-hashes the documented demo password of the demo tenant admin, as before this PR; the operator account's password is never touched |
| Entry | `20ee792` to PR head | 3 added | three new tables (`integration_gaps`, `external_event_conflicts`, `service_principals`), new nullable columns. The existing OptiFleet integration user was registered as a service principal with an audit row (`BACKFILL`); old events keep `accounting_outcome` NULL and are read as before | `DatabaseSeeder`: 2 platform permissions added (`platform.service_principal.view|manage`) and granted to platform roles only; idempotent on a second run |
| Fleet | `47b766d` to PR head | 3 added | one new table, new nullable/defaulted columns (`tenants.governance_mode` defaults `LOCAL`, so no tenant changes governance by upgrading); nothing dropped or changed | `BootstrapSeeder` creates no tenant or user. Known and not new: every run of the bootstrap seeder appends commercial bundle versions (the PR touches neither seeder nor bundle service) |
| all | PR head, empty database | | Nexus: operator account only, 0 tenants/entitlements. Entry: 0 users/tenants/connections. Fleet: 0 tenants/users | |

Not rehearsed: a real data volume, a rolling or zero-downtime upgrade, an upgrade through the production container images, Windows Podman and
the Alpine image build (see `ECOSYSTEM_AUDIT_STATUS.md` section 6).

## 2. Order

0. Backup first: `pg_dump` of the Nexus, Entry and Fleet databases, the Nexus Passport key volume and the untracked env files. Write down the three SHAs.
1. **Nexus** (provider of every contract): deploy, `php artisan migrate --force`, catalog seed (the container does it in `catalog` mode). Re-import each product's
   manifest (`ecosystem:import-manifest`): until then nothing is delegable (`permissions.delegable` defaults to false) and no product is seat-controlled. Existing service
   accounts keep `allowed_scopes = NULL` (unrestricted, as before); narrow them deliberately. Start the queue worker (invitation mail) and the scheduler
   (`invitations:expire`, `oidc:reconcile-access`). Run `ecosystem:preflight`; set a real mail transport and `TRUSTED_PROXIES` (`deploy/ecosystem/README.md`).
2. **Entry** and **Fleet** in either order, after Nexus: `php artisan migrate --force`; Entry also `optientry:sync-permissions`. Enable Fleet's accounting feed backfill
   only once Entry is on the new version (an old Entry would auto-process quarantined backfill events).
3. Switch features on per tenant, never globally: Fleet `governance_mode` stays `LOCAL` until `optinexus:governance:enable <tenant> --check` passes; an Entry connection to Fleet is created, mapped
   and activated on one tenant first; seat limits exist only where an operator sets a `user_limit` override.

## 3. One side upgraded, the other not

Every row fails closed (deny or 503), none grants access, none returns a 500.

| Situation | Behaviour |
|---|---|
| Entry or Fleet on the old version, Nexus new | No break: Entry keeps using `/authorization/check`, Fleet makes no authorization calls; new endpoints are unused |
| Fleet new, Nexus old | `LOCAL` tenants unaffected. `CENTRAL`: 503 `GOVERNANCE_UNAVAILABLE`. Product switcher unavailable, delegation answers 404 |
| Entry new, `optinexus` mode, Nexus old | Every permission check 503 and SSO `sso_unavailable` (safe full outage): deploy Nexus first. Standalone mode unaffected. Switcher hidden, seats not shown |
| Entry old, Fleet new feed | Entry ignores `review`, `pairing`, `high_water`; backfill events would be processed: upgrade Entry before enabling backfill |
| Entry new, Fleet old feed | `FEED_PAIRING_MISSING`, no `high_water`: pull refuses (fail closed) |

## 4. Recovery

* Every migration of the three PRs is additive, but **running the old code against the new schema is not proven**. Do not do it as a rollback.
* Do not run `migrate:rollback` on a live database: the `down()` methods drop the new columns and tables (and the data in them).
* A failed upgrade before any user traffic: restore the backups taken in step 0 and redeploy the recorded SHAs together. After traffic: forward-fix on the new schema.
* Switching a feature off is a configuration step, not a rollback: pause or disconnect the Entry connection, `optinexus:governance:disable <tenant> --confirm` on Fleet (break glass,
  `OPERATIONS_BREAK_GLASS.md`), `NEXUS_DELEGATION_DIRECT_USERS=false`; invitation mail stops when the queue worker is stopped.
* Posted accounting history is never edited by recovery: a wrong booking is corrected by reversal in Entry, not by restoring rows.

## 5. After a merge (only when the owner has authorised the merge)

Record the merge commit of each repo as the new baseline in `ECOSYSTEM_AUDIT_STATUS.md` and the shared checkpoint; run the smoke combination once on the sandbox
with the merged SHAs (SSO login, delegation, one Fleet invoice into Entry as a balanced journal, one invitation, seat refusal); then the steps of section 2 per environment.
