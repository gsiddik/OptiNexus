# Plan details and Fleet access checkpoint

- Branch: `fix/plan-limits-fleet-menu-capabilities`.
- Baseline: `main` / `78e67252760ad9363fcec253e863886dd1ca8d45`.
- Owner scope: plan product name, removable limits, full purchased Fleet capability access, permission-specific matrix help. PR only; no merge authorized.

## Investigation checkpoint

- Plan API already exposes `product_name` and an authorized limit deletion endpoint; the detail UI still displays `product_id` and has no delete control.
- Fleet sidebar checks BOTH exact permission keys and active modules. All reported menus are still present in Fleet source; no menu resurrection is required.
- Nexus generates subscription entitlements only at subscription lifecycle transitions. Editing a plan's capabilities/limits does not refresh existing subscription entitlements. A role may hold permissions while Fleet's module gate still uses an older purchased-capability snapshot.
- Nexus authorization already includes core and descendant capability permissions from PR #14. Preserve subscription gates, manual overrides, explicitly customized roles, and Fleet's local role restrictions.
- The exact live Windows tenant/catalog state is unavailable here. Regression must cover the real menu permission names and module codes, rather than synthetic `module.view` examples only.
- Runtime PHP/Composer are missing; prerequisite installation attempted as required by backend/AGENTS.md. Existing Composer dependencies already include Laravel Boost. Backend validation will use repository CI if local runtime setup remains unavailable.

## Next actions

Implement plan UI regression coverage, reconcile purchased plan entitlements without granting unpurchased modules, add existing-install repair/diagnostics, then permission-specific tooltip coverage. Commit/push checkpoints and create PR after validation.

## Implementation checkpoint

- Plan detail now uses `product_name` and exposes authorized limit removal with confirmation, retryable errors and list refresh. Three focused frontend tests pass.
- Plan/add-on capability and limit changes reconcile existing live subscription entitlements transactionally. Suspended subscription snapshots remain suspended; draft/expired/cancelled subscriptions are excluded. Regeneration only revokes PLAN/ADDON source rows, preserving manual overrides and custom-role grants.
- Added `ecosystem:refresh-entitlements <tenant UUID/code>` (preview) and `--apply` (explicit repair) for pre-existing stale installations. No role, price or subscription status is changed.
- Added regression using the actual Fleet menu permission keys: maintenance policies/schedules/requests, part requests, maintenance history, product/UOM, inventory/movements, returns, stock opname, used parts, PO and vendor.
- PHP 8.5 and Composer are now available; dependencies/Boost setup completed. SQLite cannot run the existing PostgreSQL-only migration constraints. Full backend checks will run on PostgreSQL 16 in CI.
- Matrix help now uses each permission's description with its readable name as fallback. Accessibility/read-only behavior is being checked.

## Validation checkpoint

- Local frontend: 228/228 tests passed; focused Plan/Matrix tests 4/4 passed after test-harness corrections; TypeScript/production build passed. Lint completes with one pre-existing `useApi.ts` hooks warning.
- PHP syntax and Pint checks pass locally. PostgreSQL CI is in progress. The initial draft CI exposed a tooltip test that focused outside React's test wrapper; corrected the test event and removed unsupported Testing Library role-query options flagged by TypeScript.
- No browser binary is installed locally; Windows/Podman browser acceptance remains untested. No Fleet source, schema, price snapshot, or seeder change is required.

## Existing installation acceptance / repair

After this PR is approved, merged and deployed, inspect the tenant's actual Plan/Add-on selections. Marking a capability ACTIVE in the application catalog, or assigning all role permissions, does not itself purchase a module. Include the desired modules in the subscribed Plan/Add-on; a newly changed Plan/Add-on now synchronizes its existing subscriptions. Product capability selection defines available options rather than granting every option to every plan.

For subscriptions changed before this fix, run inside the Nexus API container (replace `TENANT_CODE`):

```sh
php artisan ecosystem:refresh-entitlements TENANT_CODE
php artisan ecosystem:refresh-entitlements TENANT_CODE --apply
```

The first command is a read-only preview of purchased and currently effective capabilities. The second regenerates only that tenant's eligible subscription snapshots; suspended ones stay suspended, terminal/draft ones are excluded, and manual overrides/role permissions stay intact. Audit rows identify each refreshed subscription. Fleet governance cache may retain the previous answer for at most 300 seconds; renew the session after that window. These commands have not been run against the owner's installation.

| Fleet menu | Exact Fleet permission | Purchased module capability |
| --- | --- | --- |
| Maintenance Packages | `optifleet.maintenance_policy.view` | `optifleet.maintenance` |
| Planning & Schedule | `optifleet.maintenance_schedule.view` | `optifleet.maintenance` |
| Maintenance Request | `optifleet.maintenance_request.view` | `optifleet.maintenance` |
| Part Requests | `optifleet.part_request.view` | `optifleet.work_order` |
| Maintenance History | `optifleet.maintenance_history.view` | `optifleet.vehicle` (current Fleet manifest/route ownership) |
| Product / Units of Measure | `optifleet.product.view` | `optifleet.inventory` |
| Warehouse Stock / Stock Movement | `optifleet.inventory.view` | `optifleet.inventory` |
| Stock Return | `optifleet.part_return.view` | `optifleet.inventory` |
| Stock Opname | `optifleet.inventory.stock_opname` | `optifleet.inventory` |
| Used Sparepart Processing | `optifleet.used_part.view` | `optifleet.inventory` |
| Purchase Order | `optifleet.purchase_order.view` | `optifleet.procurement` |
| Vendor | `optifleet.partner.view` | `optifleet.partner` |

Use Fleet's exported manifest as the authority for permission-to-capability ownership, especially permissions shared by multiple routes. If Nexus's imported catalog predates Fleet's current code, export `php artisan optinexus:manifest --output=/tmp/fleet-manifest.json` from Fleet and import it in Nexus with `ecosystem:import-manifest optifleet <copied-file>`; preview using `--dry-run` first. The importer preserves operator-disabled capabilities/permissions. Do not widen delegable permissions, remove local role restrictions, or grant unpurchased modules merely to show menus.

## Pre-review validation

- Draft run 39531608484: backend passed **561 tests / 4218 assertions**, PHP 8.4.26 + PostgreSQL 16; changed-file Pint also passed.
- Added final regression cases for add-on capability changes, expired-subscription exclusion, CLI tenant isolation, actual commercial-context API output and unauthorized limit deletion. These additions await the next CI run.
- Verified Fleet routes and corrected Maintenance History's manifest ownership to VEHICLE (the current Fleet API places the history route inside `module:VEHICLE`, even though its sidebar group is History). No menu-label inference is used to grant access.

## Review checkpoint

- Re-fetched both baselines: Nexus main remains `78e67252760ad9363fcec253e863886dd1ca8d45`; Fleet main remains `e9b2c85f7e7e6dff193d0781753e94b06b6af0b6`. Fleet catalog/router/sidebar files match the inspected checkout; no changes were made in Fleet.
- Reviewed transactional catalog edits, subscription locking, source-scoped history revocation, suspension preservation, CLI tenant selection, permissions on deletion and accessible permission help. Added explicit fixture types during cleanup.
- All requested changes are in PR #15. Awaiting final-head CI before marking the PR ready; merge remains unauthorized.

## Owner-authorized merge review (2026-10-21)

- Owner authorized merge only after successful review. Fresh remote head was `54958be24f4a15260f157a85a72e35c28581367a`; CI 39532832124 passed and there were no unresolved review threads.
- Review found two access regressions to resolve before merge: rebuilding a live subscription could discard an individual entitlement suspension; generation also consumed ended/future add-on items.
- Preserve non-expired individual suspensions by commercial source/key when replacing history rows. Apply a shared `SubscriptionItem.inForce()` time-window scope to generation, affected-subscriber selection and CLI preview.
- Added HTTP/CLI regression coverage for an operator-suspended capability, plus start/end boundary cases for add-ons across separate tenants. Await new-head PostgreSQL CI before merging.
