# Ecosystem audit fixes: checkpoint

Single source of status for A01-A29 / R01-R08 (matrix: `TRACEABILITY.md`; owner decisions: `DECISION_PACKAGE.md`; legacy installs:
`LEGACY_MIGRATION_PLAN.md`). Product repos keep their own `docs/status/ECOSYSTEM_AUDIT_STATUS.md` for detail. Updated 2026-10-17 22:30Z.
Pushed does not mean tested: see section 4. Hard limits: no merge, no push to main, no force-push, no change to operational data.

**Overall status: PARTIALLY_COMPLETE.** Not READY because (1) Radar and everything depending on it is deferred by the owner's decision of
2026-10-17 (A10, R02, Radar live checks), (2) the one live flow ran only up to role composition and is waiting on one decision (section 5),
(3) Windows Podman and Alpine image builds could not run here, (4) business decisions are open (`DECISION_PACKAGE.md`).
Live-test coverage was limited on purpose by the owner's decision (2026-10-17): one short flow, no extra scenarios.

## 1. Repositories (branch `claude/project-thread-0cf20u` in all five)

| Repo | main baseline | HEAD (pushed) | Commits ahead | Working tree |
|---|---|---|---|---|
| OptiNexus | b4e77c7 | see `git log` (last code change 4eda307; later commits are docs) | 18 | clean |
| OptiFleet-v2 | 47b766d | b9886e5 | 15 | clean |
| OptiAccounting (OptiEntry) | 20ee792 | 98d5932 | 13 | clean |
| optiradar | 06d6ae5 | cb9905f | 7 | clean; paused |
| optiradar-web | bf15a24 | 915903c | 6 | clean; paused |

Draft pull requests (never merged by me): OptiNexus, OptiFleet-v2, OptiAccounting. None for Radar (owner decision); its branches stay pushed.

## 2. Contracts and compatible SHA set

Set S2 (current): N code 4eda307 (docs after), F@b9886e5, E@98d5932, R@cb9905f, W@915903c. Merge order for the three PRs: OptiNexus first
(provider of every contract), then OptiFleet-v2 and OptiAccounting (independent of each other; the feed needs both).

| Contract | Provider | Consumers | Notes |
|---|---|---|---|
| Bulk authorization `POST /authorization/effective-permissions` (+ additive `entitled_capabilities`, TTL <= 300 s) | N@9130581, 4eda307 | E, F, R | 429 + `Retry-After` honoured; per-client quota; consumers treat `entitled_capabilities` as optional |
| Tenant delegation `/delegation/tenants/{id}/*`, scope `tenant.delegate`, delegable set from manifest v2 | N@0f7c744, 4eda307 (scope allow-list) | E, F, R/W | service account bound to one application; acting user must be an active Admin Tenant |
| Product switcher `POST /authorization/product-context` + `launch_url?tenant_hint=` | N@0f7c744 | E, F, R/W | cache <= 300 s, fail closed |
| Financial feed `GET /integration/accounting-feed` (`high_water`, `pairing{nexus_tenant_id}`, `review.required`) | F@72b4541, e262d3d | E@6215ec7 and the E4 commits (pairing check, quarantine hold) | pair F >= 72b4541 with E >= the E4 commits |
| Manifest v2 + import (`manifest_version` is the **string** `"2.0"`; `application` block for `--create-application`) | N@f0cf948 | E@98d5932, F@2b43df8 (both fixed after the live import refused them) | additive; delegable widening needs `--allow-delegable-widening` |
| OIDC (code + PKCE required) | N existing | E, F, R | `require_pkce` always on |

Contract changes since the brief, with evidence: `entitled_capabilities` and the scope allow-list (Fleet gaps #1/#3, additive); manifest
fixes above (found by the live import 2026-10-17: Entry sent the integer 2, Fleet had no `application` block).

## 3. Batches

Batch 6 (Fleet -> Entry feed, A01/A17-A22/A27/A28/A26 OA6 screens): implementation done and pushed; validated by unit, fake and
real-database tests; the live flow is the open part. Backlog (not started, one batch at a time): A12 route matrix + smoke for F/E/R, A15 for E/F,
A14 server-side match state, `provision` command in `ecosystem.ps1`. Deferred by owner: Radar and A10.

## 4. Validation log (executed in this session)

| What | Result | SHA |
|---|---|---|
| Entry full backend (`entry-test.sh`, standalone mode, repo root mounted, Postgres 16) | 1485 passed, 0 failed (121,472 assertions, 1827 s) | E@98d5932 |
| Fleet full backend (`ptest.sh`, PostgreSQL + MongoDB) | 1664 passed, 1 failed: `AccountingFeedConcurrencyTest` late-commit sample (test timing race while two suites ran in parallel). Test hardened at F@b9886e5; the file passed 3 of 3 times alone afterwards. The full suite was re-run by the repository's CI on b9886e5 (green, next row) | F@2b43df8 (run), b9886e5 (fix) |
| Nexus full backend | 356 passed, 1 failed: `OrchestrationSecurityTest` SSRF DNS (also fails on baseline; sandbox DNS). Code unchanged since; later commits are docs | N@4eda307 |
| Nexus governance suite | 66 passed | N@4eda307 |
| Nexus frontend `tsc -b`, `npm test` 184, `npm run build`, smoke 156/156 (Chromium, mocked API, 1440/820/390) | PASS; one of five test runs failed once under load, four clean | N@e8f164d |
| GitHub Actions on the PR heads (the repositories' own CI): Fleet PR #36 backend (full PHPUnit, PostgreSQL + MongoDB) and frontend green; Entry PR #10 backend and frontend green. This also covers the full Fleet suite after the test hardening. OptiNexus has no CI configured | PASS | F@b9886e5, E@98d5932 |
| Entry focused after the manifest fix: `ManifestV2Test` | 7 passed | E@98d5932 |
| Fleet focused: `FleetManifestTest` 7, `AccountingFeedConcurrencyTest` 3 (x3), Pint on changed files | PASS | F@b9886e5 |
| Radar server: `org.traccar.governance` 178; whole suite 894 passed / 0 failed / 26 skipped / 1 aborted (pre-existing assumption); checkstyle clean; web `npm test` 25, browser 80 checks | PASS (workers' reports of their own runs; Gradle itself NOT_RUN, scratch runner with the same sources) | R@cb9905f, W@915903c |
| Real Nexus, import of both manifests (`ecosystem:import-manifest --create-application`): Entry 44 capabilities + 136 permissions, Fleet 16 + 259 created | PASS after the two manifest fixes (first attempt refused both) | N@4eda307 |
| Real Nexus, `ecosystem:register-oidc-client` and `register-service-account` for both products (PKCE, secrets to 0600 files) | PASS | N@4eda307 |
| Bulk permissions against a real Nexus (136 calls -> 1 request) | PASS (earlier, Entry baseline vs fix) | E baseline + 7bc779d |
| Sandbox compose stacks (Ubuntu php-fpm substitute images): Nexus, Fleet, Entry bootstrap, healthy, additive migrations applied | PASS | N, F, E heads above |

## 5. The one live flow: where it stands

Done on real services: stacks up, additive migrations, manifests imported, OIDC clients and service accounts registered, two contract
bugs found and fixed. **Stopped at**: creating the tenant roles. Nexus answers `403 PRIVILEGE_ESCALATION_DENIED` because the platform
super admin role only holds a newly imported product's permissions after the bootstrap seeder runs again (documented in
`MANIFEST_IMPORT.md`, "Order of operations"). Re-running the bootstrap in the sandbox, and an automatic grant at import time, were both
blocked by the permission checker as privilege grants, so I stopped and asked the owner (decision card). Until answered:
SSO login of an Admin Tenant, bulk check on the live stack, delegation action and Fleet invoice -> Entry journal are **NOT_RUN**.
Nothing is faked or inferred from unit tests.

## 6. Environment blockers (VALIDATION_BLOCKED, design unchanged)

- Alpine/production image builds, Windows Podman Desktop run: NOT_RUN (no Alpine mirror, no Podman, no Windows). Run on a Windows host:
  `deploy/ecosystem/ecosystem.ps1 preflight; init; build; up; smoke` (`deploy/ecosystem/README.md`).
- `pwsh` is not installed here: `ecosystem.ps1` is NOT_RUN end to end.
- Sandbox: the Docker daemon restarts between turns (`dockerd --data-root /var/lib/docker-opti`); after `compose up -d` recreates an `api`
  container the `web` container keeps the old upstream address and answers 502 until restarted (nginx resolves it once). Not changed.
- Wider live coverage (six product combinations, Radar, browser round trips on real services): intentionally NOT_RUN (owner decision).

## 7. Owner decisions on record

R01-R08 (2026-10-12); FX revaluation not implemented; Radar paused and live-test scope reduced (2026-10-17). Open business and policy
decisions are in one package: `DECISION_PACKAGE.md`.
