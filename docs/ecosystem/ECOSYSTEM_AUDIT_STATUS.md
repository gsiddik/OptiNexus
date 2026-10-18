# Ecosystem audit fixes: checkpoint

Single source of status for A01-A29 / R01-R08 (matrix: `TRACEABILITY.md`; owner decisions: `DECISION_PACKAGE.md`; legacy installs:
`LEGACY_MIGRATION_PLAN.md`). Product repos keep their own `docs/status/ECOSYSTEM_AUDIT_STATUS.md` for detail. Updated 2026-10-18 (batch 10, pre-merge review of the three draft PRs).
Pushed does not mean tested: see section 4. Hard limits: no merge, no push to main, no force-push, no change to operational data.

**Overall status (ecosystem): PARTIALLY_COMPLETE.** Not READY because (1) Radar and everything depending on it is deferred by the owner's decision of
2026-10-17 (A10, R02, Radar live checks), (2) live runs cover only the flows listed in sections 5 to 5d; Radar, product-switcher round trip, Fleet-side
delegation beyond invitations and Entry-side invitations were not run live, (3) Windows Podman and Alpine image builds could not run here, (4) business
decisions are open (`DECISION_PACKAGE.md`), (5) financial treatment and the default of direct user creation are still open owner decisions (the first-Admin-Tenant gap of batch 7 is closed by batch 8, section 5c; the seat policy is decided and implemented by batch 9, section 5d).
Live-test coverage was limited on purpose by the owner's decisions (2026-10-17, 2026-10-18).

**Batch 7 status (onboarding by invitation, 2026-10-18): IMPLEMENTATION_COMPLETE; scoped live check PASSED (section 5b); two gaps reported, none silently decided.**
This is a batch status, not ecosystem readiness.

**Batch 8 status (first Admin Tenant without e-mail, owner decision 2026-10-18 06:29Z): IMPLEMENTATION_COMPLETE; scoped live check PASSED (section 5c).** Nexus only (Fleet and Entry
needed no change: Fleet keeps its verified-e-mail check). Ecosystem status stays PARTIALLY_COMPLETE.

**Batch 9 status (central seat limits, owner decision 2026-10-18 09:11Z, `DECISION_PACKAGE.md` item 4): IMPLEMENTATION_COMPLETE; scoped live check PASSED (section 5d).** Nexus counts and enforces for every adding path;
Entry and Fleet only show the figures and pass the refusals on. Fleet and Radar declare no `user_limit`: not controlled, nothing invented. Ecosystem status stays PARTIALLY_COMPLETE.

**Batch 10 status (final pre-merge review, owner request 2026-10-18 10:58Z): AI-assisted self-review, not an independent review.** Verdicts per PR in section 5e. Four read-only reviewers plus my own verification of every finding in the code; confirmed blockers fixed with tests (Entry 8f10478, Nexus 51773cc); Fleet needed no code change. Nothing merged, nothing deployed. Ecosystem status stays PARTIALLY_COMPLETE.

## 1. Repositories (branch `claude/project-thread-0cf20u` in all five)

| Repo | main baseline | HEAD (pushed) | Commits ahead | Working tree |
|---|---|---|---|---|
| OptiNexus | b4e77c7 | see `git log` (last code change 51773cc; later commits are docs) | 33 + docs | clean |
| OptiFleet-v2 | 47b766d | 8781ecd (bf86a23 = previous checkpoint) | 20 | clean |
| OptiAccounting (OptiEntry) | 20ee792 | 8f10478 (0d2f110 = previous checkpoint) | 20 | clean |
| optiradar | 06d6ae5 | cb9905f | 7 | clean; paused |
| optiradar-web | bf15a24 | 915903c | 6 | clean; paused |

Draft pull requests (never merged by me): OptiNexus, OptiFleet-v2, OptiAccounting. None for Radar (owner decision); its branches stay pushed.

## 2. Contracts and compatible SHA set

Set S6 (current, batch 10): N code 51773cc (docs after), F@8781ecd, E@8f10478, R@cb9905f, W@915903c; contracts unchanged since S5 (the Nexus fixes only tighten refusals: `IDENTITY_NOT_VERIFIED`, `INVITATION_PENDING`). Set S5 (previous): N 7f58e12, F@8781ecd, E@0d2f110. Set S4: N b25b747, F@bf86a23, E@b3855c2. Set S3: N 741552f. Set S2 (historical): N 4eda307, F@b9886e5, E@98d5932. Merge order for the three PRs: OptiNexus first
(provider of every contract), then OptiFleet-v2 and OptiAccounting (independent of each other; the feed needs both).

| Contract | Provider | Consumers | Notes |
|---|---|---|---|
| Bulk authorization `POST /authorization/effective-permissions` (+ additive `entitled_capabilities`, TTL <= 300 s) | N@9130581, 4eda307 | E, F, R | 429 + `Retry-After` honoured; per-client quota; consumers treat `entitled_capabilities` as optional |
| Tenant delegation `/delegation/tenants/{id}/*`, scope `tenant.delegate`, delegable set from manifest v2 | N@0f7c744, 4eda307 (scope allow-list) | E, F, R/W | service account bound to one application; acting user must be an active Admin Tenant |
| Product switcher `POST /authorization/product-context` + `launch_url?tenant_hint=` | N@0f7c744 | E, F, R/W | cache <= 300 s, fail closed |
| Financial feed `GET /integration/accounting-feed` (`high_water`, `pairing{nexus_tenant_id}`, `review.required`) | F@72b4541, e262d3d | E@6215ec7 and the E4 commits (pairing check, quarantine hold) | pair F >= 72b4541 with E >= the E4 commits |
| Manifest v2 + import (`manifest_version` is the **string** `"2.0"`; `application` block for `--create-application`) | N@f0cf948 | E@98d5932, F@2b43df8 (both fixed after the live import refused them) | additive; delegable widening needs `--allow-delegable-widening` |
| OIDC (code + PKCE required) | N existing | E, F, R | `require_pkce` always on |
| First Admin Tenant by an operator, no e-mail: `/ecosystem/tenants/{id}/admin-accounts` (+ `/email-verification`, `/temporary-credential`) and the Nexus pages `/oidc/activate` (`GOVERNANCE_CONTRACTS.md` 2c) | N@b25b747 | operator console only; products unchanged (Fleet reads `email_verified` as before) | additive migration `2026_10_18_200001/200002`; new permission `cgo.user.email.verify` (PLATFORM_SUPERADMIN only) |
| Seat limits: `GET /delegation/tenants/{id}/seats`, `GET /tenants/{id}/seats`, `meta.seats` on the delegation user and invitation lists, refusals `SEAT_LIMIT_REACHED`, `SEAT_OVER_LIMIT`, `SEAT_ENTITLEMENT_MISSING`, `SEAT_ENTITLEMENT_INVALID`, `SEAT_ENTITLEMENT_UNAVAILABLE`, `SEAT_CONTENTION` (`GOVERNANCE_CONTRACTS.md` 2d) | N@cdd7c7c | F@8781ecd (reads top-level `seats`), E@aaa71d2 (reads `meta.seats`) | additive; no new migration; a product without `user_limit` in its manifest is *not controlled* and an older consumer that ignores `seats` still gets the refusals as ordinary 409/503 answers; pair F >= 8781ecd and E >= aaa71d2 with N >= cdd7c7c |
| Invitations `/delegation/tenants/{id}/invitations` (+ resend, revoke) and the Nexus acceptance pages (`GOVERNANCE_CONTRACTS.md` 2b) | N@c8aeb52, 741552f | F@bf86a23, E@b3855c2 | additive; `POST /users` deprecated, still works; pair F >= bf86a23 and E >= b3855c2 with N >= c8aeb52 |

Contract changes since the brief, with evidence: `entitled_capabilities` and the scope allow-list (Fleet gaps #1/#3, additive); manifest
fixes above (found by the live import 2026-10-17: Entry sent the integer 2, Fleet had no `application` block).

## 3. Batches

Batch 6 (Fleet -> Entry feed, A01/A17-A22/A27/A28/A26 OA6 screens): implementation done and pushed; validated by unit, fake and
real-database tests and by the one live flow (section 5). Batch 7 (onboarding by invitation, owner decision 2026-10-18): implemented in the three repositories, validated by unit, fake, real-database and
scoped live evidence (section 5b). Batch 8 (first Admin Tenant without e-mail, owner decision 2026-10-18 06:29Z): Nexus backend, operator console and activation pages, validated by real-database tests, vitest and a scoped live check (section 5c). Batch 9 (central seat limits, owner decision 2026-10-18 09:11Z): Nexus service, every adding path, API, console card, read-only inventory; consumers show the figures; validated by real-database tests (including parallel processes), consumer suites against fakes, and a scoped live check on a sandbox (section 5d). Backlog (not started, one batch at a time): A12 route matrix + smoke for F/E/R, A15 for E/F,
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
| Bulk permissions against a real Nexus (136 calls -> 1 request) | PASS (earlier, Entry baseline vs fix); again on the live flow: Admin Tenant sign-in to Entry = exactly 1 bulk request | E baseline + 7bc779d; N@656caa8, E@98d5932 |
| Live flow on real Nexus + Fleet + Entry (section 5) | PASS for the steps listed there | N@656caa8, F@b9886e5, E@98d5932 |
| Batch 7, Nexus (real PostgreSQL): governance suite incl. 19 invitation API, 21 acceptance and 2 concurrency tests (6 parallel acceptances -> exactly one identity), preflight 11, Pint on changed files | PASS: 119 passed (governance + preflight, concurrency included) | N@741552f |
| Batch 7, Nexus full backend | 399 passed, 1 failed: `OrchestrationSecurityTest` SSRF DNS (sandbox DNS; fails on the baseline too). The first run failed wholesale because the sandbox container had written `bootstrap/cache/config.php` (DB_HOST=postgres) into the mounted working tree; removed, then rerun | N@741552f |
| Batch 7, Fleet consumer against a fake Nexus built from the contract (not the real one): Governance suite 126, focused 44, regression 58, `node:test` 80, `tsc -b`, `npm run build`, `i18n:check`; screenshots at 1440/820/390 in EN and ID with a mocked API | PASS (worker report, spot-checked: branch clean and in sync) | F@bf86a23 |
| Batch 7, Entry consumer against the repo's faked Nexus: `tests/Feature/Optinexus` 253, Security + Rename 16, vitest 1222, `tsc -b`, `oxlint`, build; screenshots at 1440/820/390 with a mocked API | PASS (worker report, spot-checked: branch clean and in sync) | E@b3855c2 |
| Batch 7, the repositories' own CI on the new heads (historical rows above stay as evidence for b9886e5 / 98d5932 only) | PASS: Fleet PR #36 backend (full PHPUnit, PostgreSQL + MongoDB) and frontend green on bf86a23 (run 39096168646); Entry PR #10 backend and frontend green on b3855c2 (run 39096230224). OptiNexus has no CI | F@bf86a23, E@b3855c2 |
| Batch 7, live on real Nexus + real Fleet with a local mail sink | PASS for the steps in section 5b | N@741552f, F@bf86a23 |
| Batch 8, Nexus focused (real PostgreSQL): `TenantAdminBootstrapApiTest` 28, `AccountActivationFlowTest` 19 (browser-level OIDC sign-in, activation pages, EN/ID), `ManagedActivationDoorsTest` 2, `ActivationConcurrencyTest` 2 (6 parallel processes; exactly one wins) | PASS: 51 passed (526 assertions) | N working tree before b25b747 |
| Batch 8, Nexus full backend (`php artisan test`, clean tree, sandbox stopped) | PASS except the existing sandbox-DNS failure: 450 passed, 1 failed (`OrchestrationSecurityTest` SSRF, "integration host could not be resolved"; same failure on the baseline), 3307 assertions, 212 s | N@b25b747 |
| Batch 8, Nexus frontend: `tsc -b`, `oxlint` (one existing warning in `useApi.ts`), `vitest` 196 (11 new for the panel, one of them for the bug found live), `vite build`; screenshots at 1440/820/390 in EN and ID against the real stack (section 5c) | PASS | N@b25b747 |
| Batch 9, Nexus focused (real PostgreSQL): `SeatLimitApiTest`, `SeatLimitPathsTest` (every adding path, rollback, bypass, seeding), `SeatConcurrencyTest` (parallel processes: the last seat goes to exactly one) | PASS: 39 passed (24 + 12 + 3), part of the full run in the next row | N@7f58e12 |
| Batch 9, Nexus full backend (`php artisan test`, clean tree, sandbox stopped) | PASS except the existing sandbox-DNS failure: 489 passed, 1 failed (`OrchestrationSecurityTest` SSRF, same failure on the baseline), 3830 assertions, 215 s | N@7f58e12 |
| Batch 9, Nexus frontend: `tsc -b`, `oxlint`, `vitest`, `vite build`; temporary-password dialog test (item 8 of the batch) | PASS: `tsc -b` clean, `oxlint` (the one existing warning in `useApi.ts`), `vitest` 204 passed in 16 files, `vite build` | N@7f58e12 |
| Batch 9, Fleet consumer against a fake Nexus built from the contract: Governance suite incl. `SeatsDelegationTest` 8; full backend | Local: Governance suite 133 passed (2026 assertions, 213 s, incl. `SeatsDelegationTest` 8). Local full backend NOT_RUN: stopped after about 370 of 1,670 tests because it would take over an hour here; the full run is the repository's CI (PHPUnit with PostgreSQL and MongoDB, run 39117633269, backend job completed green in 43 min on this head) | F@8781ecd |
| Batch 9, Fleet frontend: `node:test`, `tsc -b`, `i18n:check`, `npm run build` | CI frontend job (lint, unit tests, i18n check, build; run 39117633269): PASS | F@8781ecd |
| Batch 9, Entry consumer against the repo's faked Nexus: full backend (standalone mode, Postgres 16) | Local: `tests/Feature/Optinexus` 260 passed (4016 assertions, 100 s); neighbouring suites (capacity, entitlement, service principal, security, rename, tenant isolation, subscription, seeder) 117 passed (1572 assertions, 182 s). Local full backend NOT_RUN for the same reason as Fleet; the full run is the repository's CI (run 39117640157, backend job completed green in 34 min on this head) | E@0d2f110 |
| Batch 9, Entry frontend: vitest, `tsc -b`, `oxlint`, build | Local: `tsc -b` clean, vitest 1237 passed (57 files); CI frontend job (run 39117640157): PASS | E@0d2f110 |
| Batch 9, live on a real Nexus + real Entry with a local mail sink (section 5d) | PASS for the steps in section 5d; one forced POST answered 419 (CSRF) instead of reaching the seat check, see there | N@7f58e12, E@0d2f110 |
| Batch 10, SSRF/DNS failure (Nexus): cause = the one allow test needs public DNS; the sandbox resolves nothing. Not a regression of this PR (same on the baseline). Fix: the client's lookup is a seam (`resolveHost`), new deterministic `SsrfSafeHttpClientTest` (private, loopback, link-local, 0/8, IPv6 ranges, mixed names, port by scheme, pinned `CURLOPT_RESOLVE`, redirect not followed, nothing sent when refused); the one real-network check stays as `#[Group('network')]` and is skipped here | PASS deterministic (41 passed, 1 skipped in the focused run); the real-network test is **NOT_RUN (VALIDATION_BLOCKED)**: run `php artisan test --group=network` on a host with public DNS | N@92126a9 |
| Batch 10, Nexus focused after the review fixes: `SeatLimitApiTest`, `SeatLimitPathsTest`, `TenantDelegationApiTest`, `InvitationApiTest` (incl. the 5 new cases) | PASS: 85 passed (930 assertions) | N@51773cc code |
| Batch 10, Nexus full backend (`php artisan test`, sandbox stopped, cache files removed; run on the working tree that became 51773cc, only docs differ) | PASS: 523 passed, 1 skipped (the network-group test above), 3939 assertions, 270 s | N@51773cc code |
| Batch 10, Entry: `tests/Feature/Integration` (141, incl. the 2 new ordering tests), frontend `vitest` integration + i18n (82), `tsc -b`, Pint on changed files | PASS (local). Full Entry backend NOT re-run locally on the new SHA: see the CI row | E@8f10478 |
| Batch 10, the repositories' own CI | Fleet PR #36 on 8781ecd: backend and frontend green (no Fleet change in this batch). Entry PR #10 on 8f10478: backend (full suite, 40 min) and frontend green, run 39127107115 | F@8781ecd, E@8f10478 |
| Batch 10, upgrade and fresh-install rehearsal on throwaway databases (`UPGRADE_AND_COMPATIBILITY.md` section 1): base to PR head for Nexus, Entry, Fleet | PASS: migrations additive, no existing row changed by the migrations, seeders idempotent and no grant widened beyond the intended permissions, fresh installs create no tenant/user/entitlement | N@92126a9 migrations (same set at 51773cc), F@8781ecd, E@0d2f110 migrations (same set at 8f10478) |
| Sandbox compose stacks (Ubuntu php-fpm substitute images): Nexus, Fleet, Entry bootstrap, healthy, additive migrations applied (batch 8: the two new migrations ran on the existing sandbox database) | PASS | N, F, E heads above |

## 5. The one live flow (executed 2026-10-18, sandbox, real services)

Real OptiNexus + OptiFleet-v2 + OptiEntry stacks (compose, php-fpm substitute images, throwaway databases; owner allowed re-running the
Nexus bootstrap in the sandbox). Scope kept short on purpose (owner decision 2026-10-17): no other scenarios.

| Step | Result |
|---|---|
| Import both manifests, register OIDC clients and service accounts, bootstrap, tenant + subscription + roles + users + Admin Tenant (Nexus API) | PASS (after the two manifest fixes; users created through the API are INVITED and must be activated) |
| Admin Tenant SSO sign-in to Entry and to Fleet: OIDC code + PKCE S256, one-time ticket exchange, no token in the URL | PASS; Entry sign-in made exactly 1 bulk effective-permissions request |
| Delegation: Admin Tenant creates a user from Entry; the user exists in Nexus with an audit entry | PASS |
| Entry `nexus:check` (clients, service principal, scopes) | PASS, all OK |
| Fleet tenant LOCAL -> CENTRAL (`optinexus:governance:enable`), pairing ready, feed credential issued | PASS |
| Real workshop invoice recorded in Fleet through its API (INV-LIVE-0001, 999000.0000 IDR incl. 99000.0000 tax) | PASS |
| Entry connection to Fleet (create, activate with pairing probe), `integration:pull`, `integration:process`, `integration:health --json` | PASS: 2 events received, `high_water` 2, lag 0, health OK |
| Entry result: memo event INFORMATIONAL (no journal); invoice event JOURNAL_POSTED -> AP invoice + posted journal, Dr 6900 900000.0000 + Dr 1150 99000.0000, Cr 2110 999000.0000, source link to the Fleet invoice | PASS |

Received is not in the ledger: the first pull ran before the Entry accounting profile existed, so the invoice stayed `NEEDS_REVIEW`
(`ACCOUNTING_NOT_CONFIGURED`) with `books.IN_LEDGER = 0`; after saving/activating the profile a manual retry (reason required) posted it
(`books.IN_LEDGER = 1`). A repeated pull returned 0 new events, but only because the pull interval was not due: re-pull idempotency was not
forced live (covered by tests). **NOT_RUN live:** product-switcher round trip, Fleet-side delegation action, Radar (deferred), Windows Podman.
Nothing is inferred from unit tests.

## 5b. Batch 7 live check: onboarding by invitation (executed 2026-10-18, sandbox, real Nexus + real Fleet)

Scope as authorized by the owner (2026-10-18): Admin Tenant invites a new user from ONE product, accept via the mail sink, Nexus verifies the e-mail,
the user signs in to Fleet by OIDC code + PKCE with **no database edit of verification**, plus negative checks. Sandbox: fresh throwaway databases,
local SMTP sink (no real mail), Nexus backend at N@741552f (+ the nginx template of df76375, applied mid-run), Fleet at F@bf86a23, Fleet console rebuilt from
F@bf86a23 with `VITE_API_BASE_URL=/api/v1`. Entry was not part of the live scope.

| Step | Kind of evidence | Result |
|---|---|---|
| Bootstrap: Fleet manifest imported (16 capabilities + 259 permissions), OIDC client and service account registered, tenant + subscription + Admin Tenant (operator API), Fleet tenant CENTRAL | scripts + artisan on the sandbox stacks | PASS |
| An operator-created Admin Tenant invites the first real owner (direct call of the delegation API with Fleet's service account and `acting_user_id`; see gap 1 for why not through Fleet) | HTTP script | PASS: 201, mail in the sink (id), `delivery` SENT, view without token |
| Owner opens the e-mailed link, sets a password, Nexus records `email_verified_at` (no DB write by me), replay of the same link answers 410 "already used" | HTTP script | PASS |
| Operator makes the owner Admin Tenant with a Fleet role; owner signs in to Fleet (OIDC code + PKCE S256, one-time ticket, no token in the URL) | HTTP script | PASS: Fleet's verified-e-mail check unchanged and satisfied |
| **Owner (Admin Tenant) creates a delegated role and invites a NEW user from Fleet** (`POST /api/v1/app/delegation/invitations` with the Fleet session) | HTTP script on the real Fleet API | PASS: 201; Fleet answer holds no token or password; list shows PENDING, SENT, role |
| Invitee accepts through the mail link, sets a password; Nexus marks the invitation ACCEPTED and the user verified; Fleet's list shows ACCEPTED, `accepted_at` | HTTP script | PASS |
| New user signs in to Fleet through Nexus OIDC (code + PKCE); gets the invited role's 3 permissions; Fleet's admin-only delegation list answers 403 for them | HTTP script | PASS |
| **Fleet console in Chromium against the real stack:** owner signs in through Nexus, Users > Invite user (dialog lists the delegated role from Nexus), sends an invitation in Indonesian; the Invitations tab shows PENDING; the new person accepts on the Nexus page in Chromium ("Akses Anda sudah siap"), continues to Fleet, signs in with OptiNexus and lands on the dashboard with only the invited role's menu; Invitations tab screenshots in EN and ID at 1440, 820, 390 without page-level horizontal overflow | Chromium (real browser) end to end for this one path | PASS |
| Acceptance page in a real browser (Chromium): form filled and submitted on the real page at 1440, 820 and 390, "Your access is ready"; screenshots of form (EN, ID), done, used, revoked, invalid at three widths, no horizontal overflow | Chromium against the real Nexus | PASS (`/tmp/claude-0/shots/nexus-invite`, sandbox only) |
| Token hygiene: the live run found the invitation token (64 hex) 28 times in the Nexus nginx access log (it is part of the e-mailed link path). Fixed in `docker/nginx/api.conf.template` (request line logged as `/invitations/[redacted]`); after re-creating the web container: 0 occurrences in the web log; 0 in the Nexus api, worker, scheduler and Fleet api, worker logs; 0 in the audit rows (read-only query) | log and read-only SQL checks | PASS after the fix (the nginx template is not unit-tested; checked live only) |
| Negative: revoked invitation: link answers 410 "withdrawn", resend answers 409 `INVITATION_NOT_PENDING`, no identity created | HTTP script | PASS |
| Negative: resend: inside the cooldown 429 `INVITATION_RESEND_COOLDOWN` with `Retry-After` through Fleet; after it 200, old link dead (404 invalid), new link accepts | HTTP script | PASS |
| Negative: assignment revoked, delegated role deleted before acceptance: 409 "invited access changed", nothing granted, no identity | HTTP script | PASS |
| Negative: assignment revoked, inviter loses the Admin Tenant flag before acceptance: 409 "can no longer be used", no identity; flag restored by the operator | HTTP script | PASS |
| Negative: expiry. TTL 0 (sandbox setting): created invitation is reported EXPIRED and no mail is sent; replaced by a new one. TTL 1 h invitation opened after expiry | HTTP script | PASS: with the TTL-1 h invitation, after expiry the Fleet list showed EXPIRED with `can_resend`, the e-mailed link answered 410 "This invitation has expired" with the recovery text (screenshots at three widths); resend through Fleet (200) made a new link, the old one answered 404 "not valid", the new one was accepted. The TTL was set back to the default (72 h) afterwards |

Sandbox facts and deviations (disclosed): the bootstrap Admin Tenant (operator-created) is a sandbox fixture that never signs in, because no
operator-side verification exists; a database write that would have marked it verified was refused by the environment's permission check and was
**not** worked around (the chain above needs no such write). `INVITATION_TTL_HOURS` was set to 0 and then 1 in the sandbox env for the expiry check only.
The Fleet tenant and master data come from the same fixture script as in section 5; a sandbox Fleet platform admin was created with
`platform:create-admin`. `localtest.me` names were mapped in `/etc/hosts`.

Screenshots (sandbox data only): project files `audit/onboarding-screenshots/` (real Chromium against the real stack, and the workers' mocked-API screens, labelled).

**Gaps reported (not decided here):**
1. **Closed by batch 8 (section 5c, `DECISION_PACKAGE.md` item 10).** First Admin Tenant of a tenant: an operator-created user had no way to verify its e-mail, so it could not sign in to Fleet
   (verified e-mail required) without a database edit. The owner chose an operator-side manual verification instead of an operator-side invitation e-mail.
2. **Closed by batch 9 (section 5d, `DECISION_PACKAGE.md` item 4).** Seat limit (`user_limit`): was not enforced by Nexus (invitations or `POST /users`) or Fleet, and Entry pre-checked with its own rule.
   The owner decided the policy; Nexus now enforces it for every adding path and Entry's own counter is gone (legacy `standalone` mode keeps its local capacity check).
3. Not run live: Entry consumer, Fleet screens other than the invitation path, existing-identity linking by password, mail-transport failure (unit and real-database
   tests only); switcher round trip, Fleet delegation beyond invitations, Radar (out of scope by instruction).
4. Compatibility: `POST /users` (with `initial_password`) still works and is deprecated by header; switching it off by default is an owner decision.

## 5c. Batch 8 live check: first Admin Tenant without e-mail (executed 2026-10-18, sandbox, real Nexus + real Fleet)

Scope as authorized by the owner and nothing more: operator creates a new Admin Tenant in the Nexus console; verifies the e-mail in the console without a database edit; the Admin Tenant signs in
through Fleet, replaces the temporary password on Nexus, and enters Fleet through OIDC code + PKCE; that Admin Tenant sends ONE next-user invitation to the local mail sink. Sandbox: the existing
throwaway databases (the two new migrations ran on them), compose stacks of Nexus and Fleet with the working copy mounted (N code b25b747, F@bf86a23), local SMTP sink, `*.test` addresses, a fresh tenant
(`acme4`) created through the operator API as a fixture and its Fleet tenant switched to CENTRAL with the repository's command. Evidence kinds are kept apart: **browser** = real Chromium against the real stack,
**API** = HTTP script, **DB** = read-only `pg_dump` piped to a counter (nothing stored), **unit** = test suites (sections 4).

| Step | Kind | Result |
|---|---|---|
| Operator signs in to the console, opens tenant > Admins, creates "Acme Empat Owner" (new account, Fleet, a Fleet role). Nexus shows the temporary password once (23 characters, groups of five) in a dialog that asks before closing an uncopied password | browser | PASS; no password field exists in the form or the request |
| Operator records the manual e-mail verification: a wrong address and a 5-character reason are refused in the form; then the right address and a reason are accepted | browser | PASS; row shows "E-mail verified, password change required", "Verified manually by <operator>" |
| Admin Tenant opens the Fleet login, "Sign in with OptiNexus" (authorize request: `response_type=code`, `code_challenge_method=S256`, `state`), enters the temporary password on the Nexus sign-in page | browser | PASS: sent to `/oidc/activate` on Nexus; the product is not opened |
| Activation page: mismatching repeat refused (stays on the page); then a new password is saved. The page also renders in Indonesian | browser | PASS |
| After saving, the browser returns to Fleet (`/app/dashboard`) with an empty query string; callback carries `code`, `state`, `iss` only; no browser request URL contains either password | browser | PASS |
| The operator list shows the account `ACTIVATED`, e-mail `OPERATOR_MANUAL` verified by the operator, password changed; no credential field anywhere in the list answer | API | PASS |
| Admin Tenant at the Nexus operator portal with the new password: `403 ECOSYSTEM_ACCESS_REQUIRED`, no token; with the used temporary password: 401 | API | PASS |
| OIDC sign-in with the used temporary password goes back to the sign-in page; with the new password it continues to Fleet's callback | API | PASS |
| That Admin Tenant sends one invitation from the Fleet console (Users > Invite user); Fleet answers 201, the Invitations tab lists it PENDING, the mail reaches the sink | browser + API | PASS: 1 mail in the sink, to `staff@acme4.test` only |
| No e-mail for create, reissue, verify, sign-in, password change and activation: the sink held 0 mails until the invitation | API (sink count) | PASS; also asserted by tests with `Mail::fake()`, `Notification::fake()`, `Queue::fake()` |
| Audit trail: `tenant_admin.bootstrap_created`, `user.temporary_password_reissued`, `user.email_verified_manually` (operator id, time, verified address, reason in metadata), `activation.started`, `activation.password_changed`, `activation.completed`, `sso.login` | API (audit read) | PASS; no password value in any entry |
| Passwords (display form, canonical form, new password) searched in the logs of Nexus api, web, worker, scheduler and Fleet api, web, worker, and in full dumps of both databases | logs + DB | PASS: 0 occurrences everywhere |

**Found by the live run, fixed, covered by a regression test:** after "Create account" the operator console lost the one-time password dialog: the tab reloaded its list, the parent screen replaced the tab with a spinner and
unmounted the panel and its dialog. The vitest mocks answered instantly, so unit tests had missed it. The password of the first sandbox account (`admin@acme4.test`) was never seen and the account was left pending
(state "Account created") as a fixture; its dialog loss was the finding. Fix: only the first load of the tab shows the spinner; test `keeps the temporary password on screen while the rest of the tab reloads slowly` fails without it.
Also corrected after the run: the actions column of the list stays in view on narrow screens (sticky).

NOT_RUN live (covered by tests only): temporary-password expiry, wrong-attempt lock-out, concurrent submissions, reissue, existing-identity linking, e-mail change voiding verification, cross-tenant and privilege-escalation
denials, a tenant user or Admin Tenant attempting to verify. Screenshots (sandbox data only; the one-time dialog was masked in the page before capture): project files `audit/admin-bootstrap-screenshots/`.

## 5d. Batch 9 live check: central seat limits (executed 2026-10-18, sandbox, real Nexus + real Entry)

Scope as authorized by the owner and nothing more: a small entitlement fixture for one test tenant, the first Admin Tenant through the existing operator flow, invitations to fill the seats, rejection of the
next one, release by revoke and expiry, acceptance turning a reservation into use, and a lowered limit. Sandbox: new throwaway databases, compose stacks of Nexus (N@7f58e12 working copy) and Entry (E@0d2f110),
local SMTP sink, `*.test` addresses, tenant `seat1` (product and plan created through the operator API with `user_limit` 3; no quota number comes from a seeder). Entry was registered in Nexus by manifest import with the OIDC
client and service account scripts of the earlier batches; `optientry:nexus:check` all OK. Evidence kinds: **API** = HTTP scripts against the real services (Entry numbers are always compared with Nexus's own
`/tenants/{id}/seats`; they matched at every one of 28 checkpoints), **browser** = real Chromium, **DB** = sandbox rows, **unit** = section 4.

| Step | Kind | Result |
|---|---|---|
| Fixture: plan `user_limit` 3, subscription; first Admin Tenant created by the operator (no e-mail), e-mail verified by the audited action, password replaced on Nexus, entry to Entry by OIDC code + PKCE | API + browser | PASS: Entry shows `limit 3, used 1, reserved 0, available 2` = Nexus |
| 3. Invite two people (a, b): reserved 2, available 0. Invite c: **409 `SEAT_LIMIT_REACHED`**; direct add of d: **409**; no mail for c or d (2 mails in the sink, one each for a and b). Resend of a: 429 (the resend cooldown; reservations unchanged); duplicate invitation of a: 409 | API | PASS |
| 4. Revoke a: reserved 1, available 1. Invite c again: reserved 2. Expire c (sandbox row `expires_at` moved into the past, the only database write): reserved 1, available 1; list shows EXPIRED, PENDING, REVOKED | API + DB | PASS (`ecosystem:seats:inventory` then reports `expired_unstamped_invitations=1`, as designed for a row the sweeper has not stamped yet) |
| 5. b accepts through the mail link: used 2, reserved 0, available 1 (before: used 1, reserved 1): **no double count** | API (browser-less) | PASS |
| 6. Limit lowered to 1 (audited tenant entitlement override; a plan edit does not rewrite a running subscription): `over_limit true`, `used 2, reserved 1, available 0`; invite and direct add: **409 `SEAT_OVER_LIMIT`**; the Admin Tenant still signs in and both people stay listed ACTIVE; the pending invitation is kept, its link shows "There is no free seat for this access", no password form, no user row created | API + DB | PASS |
| Reducing usage is allowed: the Admin Tenant removes d (HTTP 200; used 1, reserved 1, still over the limit, available 0); ending the override returns the limit to 3 and the pending invitation is then accepted (used 2, reserved 0) | API | PASS |
| Found live: two active overrides with different values read as `ENTITLEMENT_INVALID`: every add is refused with `SEAT_ENTITLEMENT_INVALID`, nothing becomes unlimited; suspending one restores the other | API | PASS (documented in the runbook: suspend the old override first) |
| Audit trail: 7 `seat.denied` entries (read-only query); none contains a password, token, secret or hash word | DB | PASS |
| Screens in Chromium, EN and ID, at 1440, 820 and 390, no page-level horizontal overflow: Nexus tenant > Applications > Seats (full and over-limit states), Entry Users and Invitations banners (full and over-limit states) | browser | PASS; one finding fixed (the "over the limit" badge wrapped into a tall pill on narrow screens, commit 7f58e12); the wide table scrolls inside its card on 390 as the other console tables do |

Deviations and findings (disclosed): (a) the forced acceptance POST in the over-limit check carried no CSRF token and answered 419 before reaching the seat check; the seat refusal itself is shown by the page
(409 text) and the no-user-row check, and by the real-database test "a refused acceptance rolls everything back". (b) Entry's side menu stays in Indonesian when English is chosen (existing, outside this batch).
(c) The sandbox Entry web container answered 502 after its api container was recreated until restarted (section 6). (d) A compound command that deleted three temporary credential files inside the sandbox Nexus container
was refused by the environment's safety check and was not worked around; the files lived in the container's own filesystem and went with the container when the sandbox was torn down at the end. Screenshots (26 files, EN and ID, three widths):
sandbox data only, project files `audit/seat-screenshots/`, not in the repositories.

NOT_RUN live (covered by tests only): the last seat contested by parallel requests on the real stack (real-database parallel-process test), first Admin Tenant refused for lack of a seat, Fleet consumer (no `user_limit`),
unreadable entitlement source, mail-transport failure.

## 5e. Batch 10: final pre-merge review (2026-10-18; AI-assisted self-review, no independent reviewer)

Method: diffs of the three PRs against their real bases read by four reviewers (Nexus security/governance, Nexus seat paths, Entry accounting feed, Fleet feed and consumers with the mixed-version table) without running anything; I
then checked every reported finding in the code myself before acting. Kinds: **C** confirmed bug (code traced), **I** inference, **T** test limitation, **O** optional improvement. A design preference is not listed as a blocker.

| ID | Repo | Severity / kind | Finding | Status |
|---|---|---|---|---|
| B1 | E | HIGH / C | `corrected`/`cancelled` had no ordering per source invoice: a cancellation processed while the earlier correction was FAILED or in review, then the correction retried, booked the cancelled invoice again as a posted liability (`WorkshopInvoiceBooking`, `EventProcessor`, `IntegrationRunner`, `GapService`) | **FIXED** E@8f10478 (`EARLIER_EVENT_UNRESOLVED`, 2 tests, docs, EN+ID label) |
| B2 | N | MEDIUM / C | Deprecated `POST /delegation/.../users` pre-registers an unverified identity with a password the Admin Tenant knows; another tenant's Admin Tenant adding that e-mail linked it by e-mail alone (`linkExisting`), handing it that tenant's roles | **FIXED** N@51773cc (`IDENTITY_NOT_VERIFIED` unless the tenant already holds the identity; test). The default of direct creation is untouched (owner item 3) |
| B3 | N | MEDIUM / C | A general `PUT /applications/{id}` with metadata replaced `metadata.manifest`, silently turning a seat-controlled product into an uncontrolled one (fail open on configuration loss) | **FIXED** N@51773cc (manifest block preserved; test) |
| B4 | N | LOW / C | A seat limit of `1e999` (overflow to INF) was read as unlimited; huge numbers and padded words were not rejected | **FIXED** N@51773cc (vetting; 3 new cases) |
| B5 | N | LOW / C | Resend of an expired invitation while a newer one is open returned 500 | **FIXED** N@51773cc (409 `INVITATION_PENDING`; test) |
| B6 | N | LOW / C | `seat.denied` audit wrote an unvalidated `acting_user_id` | **FIXED** N@51773cc (uuid only) |
| B7 | N | MEDIUM / I | No trusted-proxy configuration: behind the shipped nginx chain every IP-keyed limit shares one bucket, audit rows show the proxy, secure cookie/redirects wrong behind TLS | **Mitigated in code** (optional `TRUSTED_PROXIES`, default unchanged, behaviour checked by hand for unset, matching, non-matching and `*`) and **deployment precondition** (runbook) |
| B8 | N | LOW / C | `MAIL_MAILER=log` default writes the invitation link (a credential) to the container log; the invitation is still marked sent | **Deployment precondition** (runbook; `ecosystem:preflight` warns). Not changed |
| B9 | N | MEDIUM / I | Plan/add-on `user_limit` rows carry no application, so two subscriptions that both define `user_limit` would add up across products. Needs a plan-level `user_limit` on a second product, which the owner has not approved (Fleet/Radar get none) | **Not changed**; known limit; set seat limits as application-scoped overrides (runbook). Optional improvement |
| B10 | N | LOW / I | The delegable ceiling is enforced when roles are written/assigned, not re-applied to existing roles | Documented (`GOVERNANCE_CONTRACTS.md`); products gate on `entitled_capabilities` |
| B11 | E | MEDIUM-LOW / C | Entry creates a local user from an unverified SSO e-mail (Fleet refuses) | **Decision item 11** (policy), no code change; not a blocker |
| O | all | LOW / O | Optional, not fixed: Entry duplicate-source guard for `recorded`/`payment` (unreachable with the current producer), lenient `review.required` reading (test pins it), composite FK on conflicts, a duplicate `cancelled` counted as a journal, no alert for a generic NEEDS_REVIEW backlog, cutover compares `occurred_at`, `base_url` has no private-address guard; Fleet no negative cache/circuit breaker toward Nexus (fail closed, break-glass exists), Entry permission schema strictly `1.0`, Entry/Fleet URL validation and token-lifetime differences, readiness without a bulk probe, shared feed throttle, `rotate-credential` on a revoked credential; Nexus reserve rule "already holds a seat" not applied on create/resend of an expired invitation (fails closed), per-account temporary-password lock, `verifyEmail` not limited to managed accounts, `/authorization/check` not bound to the caller's tenant (existing) | Recorded here |
| T | all | T | No test for `SEAT_CONTENTION`; parallel-process seat tests cover four doors, not the multi-application first Admin Tenant, resend-expired or operator-vs-delegation races; the "no code writes an assignment outside the seat service" guard is a text match; 419 on the public POSTs not testable under the framework test mode; token redaction in nginx logs verified live only; Fleet/Entry projector savepoint path not in the concurrency tests | Recorded; the live gaps stay NOT_RUN |

**Verdicts (self-review; label: `self-review berbantuan AI`).** Code-merge blockers = none open. Deployment/production blockers are separate and listed below.

| PR | Reviewed SHA | Verdict | Code-merge blockers | Deployment/production blockers | Deliberately deferred | Optional |
|---|---|---|---|---|---|---|
| Nexus #10 | 51773cc (code; docs after) | **MERGE_RECOMMENDED** (first in order). The real-network SSRF check is VALIDATION_BLOCKED, not PASS | none | real mail transport (not `log`), `TRUSTED_PROXIES` and TLS edge, manifest re-import and preflight after deploy, Alpine/Podman builds NOT_RUN, owner items still OPEN (financial treatment, direct-creation default, items 5-9, 11) | Radar/A10, product pricing and seat numbers | B9, B10, test items |
| Fleet #36 | 8781ecd (unchanged) | **MERGE_RECOMMENDED**; CI green on this SHA; local full backend not rerun (CI is the evidence) | none | pairs with Nexus >= 51773cc for CENTRAL tenants (default LOCAL); backfill only after Entry is upgraded; Alpine/Podman NOT_RUN | Radar-dependent items | no negative cache toward Nexus |
| Entry #10 | 8f10478 | **MERGE_RECOMMENDED**; CI green on this SHA (run 39127107115: backend and frontend) | none | `optientry:sync-permissions`, per-tenant connection setup; Nexus must be upgraded first in `optinexus` mode | FX revaluation (decided), unapproved financial treatments stay inactive | the Entry items in row O |

Merge order and a partial-merge note: Nexus first (provider of every contract), then Fleet and Entry in either order; merging only Nexus is safe (fail-closed table in `UPGRADE_AND_COMPATIBILITY.md` section 3); merging Fleet or Entry without Nexus
leaves their new governance features unusable (CENTRAL/optinexus modes answer 503) while the default LOCAL/standalone paths keep working. Whether a merge to `main` triggers a deployment cannot be seen from the repositories: no workflow
deploys (Fleet `regression.yml` and Entry `ci.yml` only test; Nexus has no CI), but a hosting integration outside the repositories would not show here: **the owner confirms this before any merge**.

## 6. Environment blockers (VALIDATION_BLOCKED, design unchanged)

- Alpine/production image builds, Windows Podman Desktop run: NOT_RUN (no Alpine mirror, no Podman, no Windows). Run on a Windows host:
  `deploy/ecosystem/ecosystem.ps1 preflight; init; build; up; smoke` (`deploy/ecosystem/README.md`).
- `pwsh` is not installed here: `ecosystem.ps1` is NOT_RUN end to end.
- Sandbox: the Docker daemon restarts between turns (`dockerd --data-root /var/lib/docker-opti`); after `compose up -d` recreates an `api`
  container the `web` container keeps the old upstream address and answers 502 until restarted (nginx resolves it once). Not changed.
- Sandbox shortcuts (not product behaviour; no production data touched): Entry `APP_ENV=local` so an http `base_url` is accepted (production requires https);
  in the first live flow (section 5) the sandbox admin's e-mail was marked verified by a database write because Nexus had no verification path
  (historical: batch 7 added one for invited users and batch 8 one for the operator-created first Admin Tenant; neither needs such a write, sections 5b and 5c); Entry accounting setup (COA template, fiscal year, rule/category defaults, bank account, profile) done through its API.
- Wider live coverage (six product combinations, Radar, browser round trips on real services): intentionally NOT_RUN (owner decision).
- Real-network SSRF allow check (`php artisan test --group=network`): NOT_RUN here (no public DNS in the sandbox). Exact step: run that command on a host with public DNS; baseline fails the same way, which does not make it a pass.
- Batch 10 live scenarios: none added beyond the earlier authorization; if wanted, propose exactly: (a) pre-registration/link refusal over HTTP between two tenants, (b) Entry ordering with a forced failed correction. Both are covered by tests only.

## 7. Owner decisions on record

R01-R08 (2026-10-12); FX revaluation not implemented; Radar paused and live-test scope reduced (2026-10-17). 2026-10-18: onboarding of new users is by Nexus invitation link with Nexus e-mail verification (approved for this policy only; the rest of the decision package, including the financial recommendations, is not approved). 2026-10-18 09:11Z: central seat limits (`DECISION_PACKAGE.md` item 4, scope and limits written there; not an approval of pricing, seat numbers, unlimited defaults, `user_limit` for Fleet or Radar, or the `POST /users` default). 2026-10-18 06:29Z: the first Admin Tenant of a tenant is created by an operator and activated without any e-mail: manual out-of-band verification recorded in an audited Nexus action (`cgo.user.email.verify`), one-time temporary password handed over by the operator (approved for this bootstrap only: seat policy, financial treatment, Radar and the direct-user-creation default are NOT decided by it). Open business and policy
decisions are in one package: `DECISION_PACKAGE.md`.
