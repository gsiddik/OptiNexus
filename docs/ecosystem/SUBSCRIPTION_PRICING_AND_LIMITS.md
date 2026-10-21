# Selected subscription pricing and application limits

New subscription creation selects an effective, approved Pricing row. Choices show the Plan/Add-on name and price type. Exactly one base plan remains required; optional add-on prices must use the same product, currency and billing interval. Each owner can be selected once. Tenant-specific pricing overrides standard pricing for the same component.

FLAT purchases snapshot quantity 1. PER_USER, PER_DEVICE, PER_VEHICLE, PER_TRANSACTION and PER_API_CALL snapshot a positive whole quantity from the corresponding form field. The server selects unit amount from Pricing; client-supplied money is ignored. Subscription Items expose price type, owner name, quantity, unit price and total price. New selected-price billing uses those purchased snapshots instead of charging every current catalog component. Existing subscriptions retain their legacy billing path.

USAGE and TIERED are intentionally absent from the new selection flow pending owner decisions. Existing metered/tiered calculation code remains intact. Proposed decisions: USAGE should use measured consumption for a defined meter and billing period; TIERED should explicitly choose graduated blocks versus one volume rate. Neither new flow is enabled by this change.

Tenant provisioning requires a non-terminal subscription and an ACTIVE application assignment belonging to that tenant. Backend validation is authoritative; the UI explains the prerequisite. Assigning or removing an application reloads the Seats section. Secret dialogs try the secure Clipboard API and then a user-triggered browser copy fallback for HTTP development origins; failures are still reported if browser policy denies both.

Add-on limit removal uses the existing configure-limit permission, checks ownership, records an audit event and regenerates applicable subscription entitlements. Product names are displayed without exposing product IDs as labels.

## Fleet resource limits

OptiNexus commercial context adds `resource_limits`, scoped to the application bound to the service account and the relevant product subscriptions. Plan base limits and add-on deltas use the existing entitlement resolution, including manual overrides and validity windows.

Fleet CENTRAL account subscriptions map exact resource keys: `vehicle`, `user`, `branch`, `workshop`, `warehouse`. A missing key displays Unlimited. Aliases such as `vehicles`, `max_vehicles` and `vehicle_limit` do not match `vehicle`. Standalone LOCAL tenants keep their local capacity limits. Central read errors or malformed limits are reported; they do not silently fall back to Unlimited. This change affects the account subscription display, not resource creation enforcement.

## Release and acceptance

Nexus PR #16 must be deployed before Fleet PR #39. Both PRs target main and require explicit owner merge instruction. The PowerShell updater fetches main and checks that the required feature commits are present before building or running bootstrap; it does not deploy an unmerged feature branch.

Acceptance: assign an application and observe its Seats row without refreshing; check all provisioning prerequisite combinations; copy a temporary password on the HTTP local deployment; create one subscription for each supported pricing type and verify quantity/unit/total; remove an add-on limit and inspect the active subscriber; verify exact-key Fleet limits and a second customer's independent values.

Checkpoint validation: 575 Nexus backend tests / 4,330 assertions passed in CI before code-style adjustments; frontend tests/build passed locally. Final PR checks record the exact reviewed head. No application deployment or merge is performed by the development task.
