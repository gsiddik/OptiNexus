# Ecosystem audit fixes: checkpoint

Single source of status for A01-A29 / R01-R08 (matrix: `TRACEABILITY.md`). Product repos keep their own
`docs/status/ECOSYSTEM_AUDIT_STATUS.md` for detail. Updated 2026-10-17 16:30Z. Pushed does not mean tested: see Validation.
Hard limits unchanged: no merge, no push to main, no force-push, no change to operational data. Final status cannot be READY
(Radar-dependent items are deferred by the owner): expected PARTIALLY_COMPLETE.

## 1. Repositories (branch `claude/project-thread-0cf20u` in all five)

| Repo | main baseline | HEAD (pushed) | Commits ahead | Working tree at snapshot |
|---|---|---|---|---|
| OptiNexus | b4e77c7 | 4eda307 | 16 | clean |
| OptiFleet-v2 | 47b766d | 31357af | 9 | 46 uncommitted paths (worker mid-unit: A10 odometer, not part of the active batch; not discarded) |
| OptiAccounting (OptiEntry) | 20ee792 | 88fea73 | 7 | 29 uncommitted paths (worker mid-unit: OA6 UI/i18n, feed consumer) |
| optiradar | 06d6ae5 | 83aa90f | 6 | clean; paused |
| optiradar-web | bf15a24 | 5134a71 | 4 | 15 uncommitted paths (stopped by owner decision; the worker commits its last consistent state) |

No PR is open yet for any repo (drafts come last, never merged). Radar branches stay pushed, no Radar PR.

## 2. Contracts and compatible SHA sets

Set S1 (current): N@4eda307, F@31357af, E@88fea73, R@83aa90f, W@5134a71. Uncommitted work is not part of a set.

| Contract | Provider | Consumers | Notes |
|---|---|---|---|
| Bulk authorization `POST /authorization/effective-permissions` (+ additive `entitled_capabilities`, TTL <= 300 s) | N@9130581, 4eda307 | E@7bc779d, F@4a85f7b, R@608fc11 | 429 + `Retry-After` honoured; per-client quota, not per IP. `entitled_capabilities` needs N>=4eda307; consumers treat it as optional |
| Tenant delegation `/delegation/tenants/{id}/*`, scope `tenant.delegate`, delegable set from manifest v2 | N@0f7c744 (+ scope allow-list N@4eda307) | E@9595ddd,71a0ac1; F@38320a4,9105445; R@b3a031e,W@19cbac2 | service account bound to one application; acting user must be an active Admin Tenant; Admin Tenants/operators protected |
| Product switcher `POST /authorization/product-context` + `launch_url?tenant_hint=` | N@0f7c744 | E@9753a73; F@55a0334; R@d0ad51b,W@7b6b510 | cache <= 300 s, fail closed, `allowed=false` ends the session |
| Financial feed `GET /integration/accounting-feed` with `high_water`, `pairing{nexus_tenant_id}`, `review.required` | F@e262d3d (high_water), F@72b4541 (pairing, quarantine) | E@6215ec7 (high_water, strict pages); `pairing`/`review` consumption in progress | E must compare `pairing.nexus_tenant_id` and hold `review` events; pair F>=72b4541 with the E commit that adds it (to be recorded) |
| Manifest v2 + import | N@f0cf948 | E, F, R export their own manifest | additive; delegable widening needs `--allow-delegable-widening` |
| OIDC (code + PKCE required) | N existing; test N@6bc17c9 | R@5e98785; E, F existing clients | `require_pkce` always on |

Contract changes since the brief, with evidence: `entitled_capabilities` (Fleet gap #1: commercial-context is tenant-wide) and the
scope allow-list (Fleet gap #3: any account could request `tenant.delegate`). Both additive.

## 3. Active batch (one)

**Batch 6: Fleet -> Entry financial feed** (A01, A17-A22, A27, A28, A26 for the OA6 screens) to a testable, reviewable boundary:
Entry consumes `pairing`/`review`, OA6 UI for gaps/conflicts/outcomes in English and Indonesian, one focused live flow
(SSO login, bulk permissions, delegation, Fleet data reaching Entry), one full regression. Dependencies A03, A19, A23, A29 are done.
Backlog (not started): A12 route matrix + smoke for F/E/R, A15 for E/F, A14 server-side match state, `provision` command in
`ecosystem.ps1`. Deferred by owner: Radar and everything depending on it (A10, R02, Radar live checks).

## 4. Validation log (executed in this session)

| What | Result | SHA |
|---|---|---|
| Nexus full backend suite (`/tmp/claude-0/nx-test.sh --compact`) | 356 passed, 1 failed: `OrchestrationSecurityTest` SSRF DNS (also fails on baseline; sandbox DNS) | N@4eda307 |
| Nexus governance suite | 66 passed | N@4eda307 |
| Nexus frontend `tsc -b`, `npm test`, `npm run build`, smoke 156/156 (Chromium, mocked API) | PASS; one of five test runs failed once under load, 4 clean reruns | N@e8f164d |
| Entry: `tests/Feature/Optinexus` 218, Integration 94 + concurrency 4, frontend 1167 | PASS (worker report of its own session) | E 9595ddd..88fea73 |
| Entry full backend (standalone mode in sandbox) | 1373 passed, 1 skipped, 1 failed (`SeederAndBootstrapTest` needs `docs/` mount) | E 9595ddd |
| Fleet feed + governance + switcher tests | PASS against FakeNexus and real PostgreSQL/MongoDB | F@31357af |
| Bulk permissions against a real Nexus | PASS (136 -> 1 request) | E baseline + 7bc779d |

## 5. Environment blockers (VALIDATION_BLOCKED, design unchanged)

- Alpine/production image builds, Windows Podman Desktop run: NOT_RUN (no Alpine mirror, no Podman, no Windows). Command to run on a
  Windows host: `deploy/ecosystem/ecosystem.ps1 preflight; init; build; up; smoke` (see `deploy/ecosystem/README.md`).
- `pwsh` is not installed here: `ecosystem.ps1` is NOT_RUN end to end.
- Docker daemon in this sandbox restarts between turns: `dockerd --data-root /var/lib/docker-opti`, then `docker start nx-test-pg entry-agent-pg optifleet-agent-pg optifleet-agent-mongo`.
- Real-service tests: only one live flow by owner decision (2026-10-17); wider live coverage is intentionally NOT_RUN.

## 6. Owner decisions on record

R01-R08 (2026-10-12); FX revaluation not implemented; Radar paused and live-test scope reduced (2026-10-17). Open business decisions
(one package at the end): Fleet D1-D11 (`ACCOUNTING_SOURCE_COVERAGE.md`), delegable-set review, invitation vs initial password,
user-limit entitlement key for delegation, per-tenant LOCAL -> CENTRAL cutover timing, break-glass login flag defaults.
