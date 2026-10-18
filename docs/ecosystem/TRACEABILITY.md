# Traceability A01-A29 and R01-R08

Snapshot 2026-10-17 22:30Z, SHA set S2 (checkpoint, validation log and live-flow status: `ECOSYSTEM_AUDIT_STATUS.md`). Columns are separate on purpose: **Impl** = code exists on the
branch, **Valid** = what was actually executed (pushed is not tested), **Open** = blocker or remaining work.
Codes: DONE / PARTIAL / DEFERRED (owner decision, Radar paused 2026-10-17) / BACKLOG. Valid: PASS-unit (own repo tests), PASS-fake (against a
Nexus built from the documented JSON), PASS-real (against a running OptiNexus), NOT_RUN. Repos: N=OptiNexus, F=OptiFleet-v2, E=OptiEntry
(OptiAccounting), R=optiradar, W=optiradar-web. Investigation = done for all rows (audit text + code reading); "reproduced" is stated where done.

| ID | Impl (repo@sha) | Valid | Open |
|---|---|---|---|
| A01 OA6 Fleet->Entry | DONE (code) F@72b4541,e262d3d,31357af; E@6215ec7 + E4 commits to 98d5932 (pairing, quarantine, health) | PASS-unit F feed 42 (parallel-process cursor proof on PostgreSQL), E full 1485 incl. integration + concurrency; PASS-real (live flow 2026-10-18, sandbox): workshop invoice INV-LIVE-0001 recorded in Fleet, feed pulled by Entry (pairing verified, `high_water` 2, lag 0), memo event INFORMATIONAL (no journal), invoice -> AP invoice + balanced journal (Dr 6900 900000 + Dr 1150 99000, Cr 2110 999000 IDR), received 2 / processed 2 / posted 2, books IN_LEDGER 1; first pull before the accounting profile existed held the invoice in NEEDS_REVIEW (`ACCOUNTING_NOT_CONFIGURED`) until retry with a reason, i.e. received and in-ledger stay separate; re-pull idempotency NOT_RUN live (interval not due; covered by tests) | owner decisions D1-D11 (source coverage) |
| A02 central governance | PARTIAL N@f0cf948,4eda307; F@4a85f7b,7555b9a; E@9595ddd; R@608fc11 | PASS-unit N governance suite (66 tests); PASS-fake F/E/R; PASS-real (sandbox): Fleet tenant LOCAL -> CENTRAL, Fleet and Entry sign-in resolved by Nexus, Entry `nexus:check` all OK; Radar NOT_RUN | Fleet tenants stay LOCAL until an operator runs the per-tenant enable; legacy local mode does not satisfy R01-R08 (plans: E `LEGACY_IDENTITY_MIGRATION.md`, F `CENTRAL_GOVERNANCE.md`) |
| A03 bulk permissions | DONE N@9130581; E@7bc779d; F@4a85f7b; R@608fc11 | N PASS-unit; E 21 focused + baseline reproduced on a real Nexus (136 calls = 119 x 200 + 17 x 429; after: 1 request, 60-110 ms); F/R PASS-fake; PASS-real (sandbox): Admin Tenant SSO sign-in to Entry caused exactly 1 bulk request | none |
| A04 env forwarded to roles | DONE E@24793de, F@43f3719, N@3bcffeb, R@7abcc29 | compose config + sandbox bring-up (Ubuntu base images); Alpine image builds NOT_RUN; Windows Podman NOT_RUN | Windows Podman rehearsal |
| A05 port clash | DONE same commits (8100/5180, 8000/5173, 8200/5190, 8082) | compose config; sandbox stacks of N and F ran side by side | none |
| A06 Nexus container package | DONE N@3bcffeb,6f7e8d2,dbaed5f | sandbox stack `optinexus-orch` ran; Alpine build NOT_RUN | Podman |
| A07 Nexus env / issuer | DONE N@3bcffeb | PASS in sandbox stack | none |
| A11 Fleet single migrator | DONE F@43f3719 | sandbox stack ran, bootstrap once | none |
| A13 Radar packaging | DONE R@7abcc29 (server + Web from source, ports opt-in) | image build NOT_RUN (no Gradle/registry here) | build on a real host |
| A16 Entry .dockerignore | DONE E@24793de | image content checked in sandbox | none |
| A08 Radar OIDC | DONE R@5e98785, W@6605f24 | PASS-unit R (state/nonce/PKCE/ID token/linker); N-side conversation test N@6bc17c9; PASS-real NOT_RUN | Radar paused |
| A09 stable subject | DONE R@5e98785 (identity by issuer+subject) | PASS-unit R | multi-tenant parallel-session decision (deferred) |
| A10 telematics | DEFERRED by owner (Radar side R@83aa90f FIXED_AND_TESTED; Fleet backend odometer ledger done, no UI; N gateway untouched) | PASS-unit R 28 + F backend; PASS-real NOT_RUN | Radar-dependent, deferred by Gunady's decision; D-T1..D-T3 |
| A12 blank pages | PARTIAL N@6ea431e..e8f164d; E delegation/switcher states; F delegation pages; W (boundaries, stale file recovery, route matrix) | N: 184 unit + smoke 156/156 at 1440/820/390 (mocked API); W: npm test 25 + browser 80 checks; F/E full-route smoke NOT_RUN | BACKLOG: route matrix + smoke for F/E |
| A14 Nexus onboarding UI | DONE N@f0fa6bf,b5c329a,8e37bb7 | PASS-unit + mocked-API smoke; real backend NOT_RUN | server-side `match_state`/paging for the gateway lists, `X-Request-Id` exposure |
| A15 bundles | PARTIAL N@6ea431e..e8f164d (initial JS 496.4 -> 394.8 kB raw, 126.8 -> 121.7 gzip, measured); W (map lib split, not measured) | N measured (9 runs); others NOT_RUN | BACKLOG: E (1,285 kB) and F (2.37 MB) not started |
| A17 backfill/projection | DONE F@72b4541; E@6215ec7 | PASS-unit + real-DB parallel proof (F); E concurrency 4; two-backend NOT_RUN | live flow |
| A18 gap lifecycle | DONE F@e262d3d (`high_water`); E@6215ec7; gap card and decision dialog in the E4 UI | PASS-unit E 35 hardening cases + UI tests; screens checked in a browser at 1440/820/390 against fixtures | none |
| A19 feed pairing | DONE F@72b4541 (producer); E4 consumer (REFUSED/UNAVAILABLE/INVALID, `FEED_PAIRING`) | PASS-fake F; E `FeedPairingTest` 32 | live pairing PASS-real (Entry `access.pairing` linked, verified Nexus tenant id matched; sandbox) |
| A20 pause/disconnect | DONE E@6215ec7 | PASS-unit + concurrency (pause waits for in-flight; retry during pause refused) | none |
| A21 conflicts | DONE E@6215ec7 + content-conflict diff UI | PASS-unit | none |
| A22 cursor validation | DONE E@6215ec7; F cursor proof | PASS-unit (18-case malformed-page matrix) | none |
| A23 service identity | DONE E@88fea73 (registry), N@4eda307 (scope allow-list) | E ServicePrincipalTest 29, Sso 41; N 4 tests | none |
| A24 portal gate | DONE N@c0bc909, UI N@6ea431e | PASS-unit EcosystemAccessGateTest + route inventory | none |
| A25 delegation | DONE N@0f7c744; E@9595ddd,71a0ac1; F@38320a4,9105445; R@b3a031e,W@19cbac2 | N PASS-unit; E ~50; F 16 (fake); PASS-real (sandbox): Entry created a user in Nexus through the delegation API with audit; Fleet/Radar delegation action NOT_RUN | delegable-set review by owner |
| A26 bilingual OA6 UI | DONE for Entry (i18n layer; delegation, switcher, integration screens EN + ID, catalog parity test); Fleet/Radar new screens: see their status files | PASS-unit E 1206 frontend | none for Entry |
| A27 outcomes | DONE E@6215ec7 + UI ("Selesai diproses" vs ledger state) | PASS-unit | none |
| A28 fair scheduling | DONE E@6215ec7 | PASS-unit (backlog fairness) | none |
| A29 product switcher | DONE N@0f7c744; E@9753a73; F@55a0334; R@d0ad51b,W@7b6b510 | E ProductContext 16 + TenantHint 13 + socket contract 5; F 26 (fake); PASS-real NOT_RUN | live round trip (one flow) |
| R01 Fleet independent | PARTIAL (Fleet needs only Nexus; manual odometer unchanged) | PASS-unit | gateway telematics deferred |
| R02 Radar independent | DEFERRED | NOT_RUN | owner decision |
| R03 Entry independent | DONE (core does not depend on the Fleet adapter) | PASS-unit | none |
| R04 six combinations | PARTIAL N@dbaed5f (extensible catalog, no hard-coded combos) | preflight only; combinations NOT_RUN | one live flow only (owner decision) |
| R05 Nexus authority | PARTIAL = A02 | see A02 | cutover per tenant |
| R06 SSO switching | DONE = A29 + OIDC code/PKCE | PASS-fake; PASS-real (sandbox) for OIDC code + PKCE S256 sign-in to Entry and Fleet, one-time ticket exchange, no token in the URL; switcher round trip NOT_RUN | product-context round trip (outside the one flow) |
| R07 Nexus boundary | DONE N@c0bc909 | PASS-unit | none |
| R08 delegation | DONE = A25 | see A25 (Entry live PASS-real) | delegable-set review |
