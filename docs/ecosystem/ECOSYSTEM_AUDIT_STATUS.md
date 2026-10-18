# Ecosystem audit fixes: checkpoint

Single source of status for A01-A29 / R01-R08 (matrix: `TRACEABILITY.md`; owner decisions: `DECISION_PACKAGE.md`; legacy installs:
`LEGACY_MIGRATION_PLAN.md`). Product repos keep their own `docs/status/ECOSYSTEM_AUDIT_STATUS.md` for detail. Updated 2026-10-18 (batch 7, onboarding by invitation).
Pushed does not mean tested: see section 4. Hard limits: no merge, no push to main, no force-push, no change to operational data.

**Overall status (ecosystem): PARTIALLY_COMPLETE.** Not READY because (1) Radar and everything depending on it is deferred by the owner's decision of
2026-10-17 (A10, R02, Radar live checks), (2) live runs cover only the flows listed in sections 5 and 5b; Radar, product-switcher round trip, Fleet-side
delegation beyond invitations and Entry-side invitations were not run live, (3) Windows Podman and Alpine image builds could not run here, (4) business
decisions are open (`DECISION_PACKAGE.md`), (5) the first Admin Tenant of a tenant still has no e-mail verification path (section 5b, gap 1).
Live-test coverage was limited on purpose by the owner's decisions (2026-10-17, 2026-10-18).

**Batch 7 status (onboarding by invitation, 2026-10-18): IMPLEMENTATION_COMPLETE; scoped live check PASSED (section 5b); two gaps reported, none silently decided.**
This is a batch status, not ecosystem readiness.

## 1. Repositories (branch `claude/project-thread-0cf20u` in all five)

| Repo | main baseline | HEAD (pushed) | Commits ahead | Working tree |
|---|---|---|---|---|
| OptiNexus | b4e77c7 | see `git log` (last code change 741552f; later commits are docs) | 24 | clean |
| OptiFleet-v2 | 47b766d | bf86a23 (b9886e5 = previous checkpoint) | 19 | clean |
| OptiAccounting (OptiEntry) | 20ee792 | b3855c2 (98d5932 = previous checkpoint) | 16 | clean |
| optiradar | 06d6ae5 | cb9905f | 7 | clean; paused |
| optiradar-web | bf15a24 | 915903c | 6 | clean; paused |

Draft pull requests (never merged by me): OptiNexus, OptiFleet-v2, OptiAccounting. None for Radar (owner decision); its branches stay pushed.

## 2. Contracts and compatible SHA set

Set S3 (current): N code 741552f (docs after), F@bf86a23, E@b3855c2, R@cb9905f, W@915903c. Set S2 (previous checkpoint, historical): N 4eda307, F@b9886e5, E@98d5932. Merge order for the three PRs: OptiNexus first
(provider of every contract), then OptiFleet-v2 and OptiAccounting (independent of each other; the feed needs both).

| Contract | Provider | Consumers | Notes |
|---|---|---|---|
| Bulk authorization `POST /authorization/effective-permissions` (+ additive `entitled_capabilities`, TTL <= 300 s) | N@9130581, 4eda307 | E, F, R | 429 + `Retry-After` honoured; per-client quota; consumers treat `entitled_capabilities` as optional |
| Tenant delegation `/delegation/tenants/{id}/*`, scope `tenant.delegate`, delegable set from manifest v2 | N@0f7c744, 4eda307 (scope allow-list) | E, F, R/W | service account bound to one application; acting user must be an active Admin Tenant |
| Product switcher `POST /authorization/product-context` + `launch_url?tenant_hint=` | N@0f7c744 | E, F, R/W | cache <= 300 s, fail closed |
| Financial feed `GET /integration/accounting-feed` (`high_water`, `pairing{nexus_tenant_id}`, `review.required`) | F@72b4541, e262d3d | E@6215ec7 and the E4 commits (pairing check, quarantine hold) | pair F >= 72b4541 with E >= the E4 commits |
| Manifest v2 + import (`manifest_version` is the **string** `"2.0"`; `application` block for `--create-application`) | N@f0cf948 | E@98d5932, F@2b43df8 (both fixed after the live import refused them) | additive; delegable widening needs `--allow-delegable-widening` |
| OIDC (code + PKCE required) | N existing | E, F, R | `require_pkce` always on |
| Invitations `/delegation/tenants/{id}/invitations` (+ resend, revoke) and the Nexus acceptance pages (`GOVERNANCE_CONTRACTS.md` 2b) | N@c8aeb52, 741552f | F@bf86a23, E@b3855c2 | additive; `POST /users` deprecated, still works; pair F >= bf86a23 and E >= b3855c2 with N >= c8aeb52 |

Contract changes since the brief, with evidence: `entitled_capabilities` and the scope allow-list (Fleet gaps #1/#3, additive); manifest
fixes above (found by the live import 2026-10-17: Entry sent the integer 2, Fleet had no `application` block).

## 3. Batches

Batch 6 (Fleet -> Entry feed, A01/A17-A22/A27/A28/A26 OA6 screens): implementation done and pushed; validated by unit, fake and
real-database tests and by the one live flow (section 5). Batch 7 (onboarding by invitation, owner decision 2026-10-18): implemented in the three repositories, validated by unit, fake, real-database and
scoped live evidence (section 5b). Backlog (not started, one batch at a time): A12 route matrix + smoke for F/E/R, A15 for E/F,
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
| Sandbox compose stacks (Ubuntu php-fpm substitute images): Nexus, Fleet, Entry bootstrap, healthy, additive migrations applied | PASS | N, F, E heads above |

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
1. First Admin Tenant of a tenant: an operator-created user has no way to verify its e-mail, so it cannot sign in to Fleet (verified e-mail required)
   without a database edit. Delegated onboarding is covered; this operator path is not. Suggested follow-up (needs the owner): an operator action that
   sends the Admin Tenant an invitation of the same kind. `DECISION_PACKAGE.md` item 3 is approved for delegated onboarding only.
2. Seat limit (`user_limit`): not enforced by Nexus (invitations or `POST /users`) or Fleet. Entry pre-checks seats at invite time with its own existing rule.
   The policy stays open (`DECISION_PACKAGE.md` item 4); nothing was set silently.
3. Not run live: Entry consumer, Fleet screens other than the invitation path, existing-identity linking by password, mail-transport failure (unit and real-database
   tests only); switcher round trip, Fleet delegation beyond invitations, Radar (out of scope by instruction).
4. Compatibility: `POST /users` (with `initial_password`) still works and is deprecated by header; switching it off by default is an owner decision.

## 6. Environment blockers (VALIDATION_BLOCKED, design unchanged)

- Alpine/production image builds, Windows Podman Desktop run: NOT_RUN (no Alpine mirror, no Podman, no Windows). Run on a Windows host:
  `deploy/ecosystem/ecosystem.ps1 preflight; init; build; up; smoke` (`deploy/ecosystem/README.md`).
- `pwsh` is not installed here: `ecosystem.ps1` is NOT_RUN end to end.
- Sandbox: the Docker daemon restarts between turns (`dockerd --data-root /var/lib/docker-opti`); after `compose up -d` recreates an `api`
  container the `web` container keeps the old upstream address and answers 502 until restarted (nginx resolves it once). Not changed.
- Sandbox shortcuts (not product behaviour; no production data touched): Entry `APP_ENV=local` so an http `base_url` is accepted (production requires https);
  in the first live flow (section 5) the sandbox admin's e-mail was marked verified by a database write because Nexus had no verification path
  (historical: batch 7 added one for invited users and needs no such write, section 5b; the operator-created first Admin Tenant still has none); Entry accounting setup (COA template, fiscal year, rule/category defaults, bank account, profile) done through its API.
- Wider live coverage (six product combinations, Radar, browser round trips on real services): intentionally NOT_RUN (owner decision).

## 7. Owner decisions on record

R01-R08 (2026-10-12); FX revaluation not implemented; Radar paused and live-test scope reduced (2026-10-17). 2026-10-18: onboarding of new users is by Nexus invitation link with Nexus e-mail verification (approved for this policy only; the rest of the decision package, including the financial recommendations, is not approved). Open business and policy
decisions are in one package: `DECISION_PACKAGE.md`.
