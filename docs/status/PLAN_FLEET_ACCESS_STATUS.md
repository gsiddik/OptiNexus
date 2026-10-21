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
