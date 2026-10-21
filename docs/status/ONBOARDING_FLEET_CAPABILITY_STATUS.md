# Onboarding and Fleet capability access checkpoint

- Branch: `fix/onboarding-fleet-capability-access`; baseline `origin/main` `086cf8b0162e`.
- Scope: active-tenant onboarding report and Nexus capability-derived permissions consumed by Fleet.
- Owner requires periodic commits/pushes and PR only; no merge is authorized.

## Findings

1. `OnboardingReportService` formats `ServiceAccount.last_used_at` as Carbon, but the model casts only `allowed_scopes`. Persisted non-null timestamps can cause the report API to return 500.
2. Automatic roles select permission rows only for exact raw capability entitlement IDs. Core Fleet permissions have null capability IDs and are omitted; raw rows can also ignore a false manual override. Inspecting hierarchy and subscription/application boundaries before changing this resolver.
3. Fleet already uses Nexus effective permissions and commercial module entitlements; local restrictions may narrow central grants. Do not grant platform permissions or bypass subscription gates to imitate Tenant Admin.

## Validation / next actions

- PHP/Composer are absent from this runtime. Runtime setup is in progress; do not report backend tests as passed until executed.
- Add regression coverage for used service accounts and capability/core permission resolution, implement fixes, then run affected frontend/backend checks.
- Publish subsequent checkpoints and open a PR after validation. Windows browser acceptance remains external to this environment.
