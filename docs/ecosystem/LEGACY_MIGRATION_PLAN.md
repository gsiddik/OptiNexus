# Legacy local authority -> OptiNexus as the authority (cross-product plan)

Decisions R01-R08 (2026-10-12): OptiNexus is the governance and identity authority of the whole ecosystem. An installation that still
keeps local tenants, users, roles or subscriptions (OptiEntry `standalone`, OptiFleet tenants in `governance_mode = LOCAL`) keeps working,
is labelled legacy and does **not** meet R01-R08. Nothing migrates automatically: no user, role, tenant or subscription is copied, deleted
or re-keyed without an operator running the steps below, per tenant, with a reviewed mapping. Posted accounting history is never rewritten.

Per-product detail (read first, the steps there are the executable ones):

| Product | Plan | Read-only inventory | Cutover |
|---|---|---|---|
| OptiEntry | `OptiAccounting/docs/architecture/LEGACY_IDENTITY_MIGRATION.md` | `optientry:*` read commands (step 1) | link `tenants.optinexus_tenant_id` and `users.optinexus_subject` on the **existing** rows, then switch the installation to `OPTIENTRY_IDENTITY_MODE=optinexus` (step 5-6) |
| OptiFleet | `OptiFleet-v2/docs/integration/CENTRAL_GOVERNANCE.md` (section "Legacy installs") | `optinexus:governance:inventory [--tenant=CODE] [--json]` | `optinexus:governance:enable CODE --check`, then `--by=<operator e-mail>` (per tenant, row-locked, audited) |
| OptiRadar | paused by owner decision (2026-10-17) | n/a | n/a |

## Order for one tenant that uses several products

1. **OptiNexus first.** Create or find the tenant, assign the applications, subscribe the capabilities, mark the Admin Tenant. Import each
   product's manifest (`ecosystem:import-manifest`, dry run first) and run the **bootstrap job again** so the platform roles hold the new
   permissions (see `MANIFEST_IMPORT.md`, "Order of operations").
2. **Inventory and mapping per product** (no writes): `local user -> OptiNexus user | NEW | EXCLUDE`, `local role -> role template | custom
   role | drop`. Shared mailboxes and service identities need an explicit decision. Platform operators stay local break-glass accounts.
3. **Stage people and roles in OptiNexus.** Only the delegable permission set can be given by an Admin Tenant; anything else is given by
   an operator through a role template. Verify with `POST /authorization/effective-permissions`: the effective set must equal the inventory
   or the documented difference.
4. **Cut over product by product**, OptiFleet and OptiEntry independently (R04): each cutover is one tenant at a time and can be rolled
   back by configuration until the first central sign-in is accepted.
5. **Connect Fleet -> Entry** only after both tenants are central: pair the feed with the OptiNexus tenant id (`pairing.nexus_tenant_id`),
   cut over from a stated date, backfill only the history that was reviewed (`review.required` events stay held until a person decides).
6. **Retire local artefacts** after an observation period agreed with the owner: deactivate (never delete) local passwords of mapped people.

## What the owner still decides (listed in the decision package)

- Timing per tenant of the LOCAL -> CENTRAL cutover and the observation period.
- Invitation e-mail versus initial password for users created through delegation.
- Which tenants keep a break-glass local operator login and the default of the break-glass flag.
