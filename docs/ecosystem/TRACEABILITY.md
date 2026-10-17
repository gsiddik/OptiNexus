# Traceability A01-A29 and R01-R08

Snapshot 2026-10-17 (checkpoint and SHAs: `ECOSYSTEM_AUDIT_STATUS.md`). Columns are separate on purpose: **Impl** = code exists on the
branch, **Valid** = what was actually executed (pushed is not tested), **Open** = blocker or remaining work.
Codes: DONE / PARTIAL / DEFERRED (owner decision, Radar paused 2026-10-17) / BACKLOG. Valid: PASS-unit (own repo tests), PASS-fake (against a
Nexus built from the documented JSON), PASS-real (against a running OptiNexus), NOT_RUN. Repos: N=OptiNexus, F=OptiFleet-v2, E=OptiEntry
(OptiAccounting), R=optiradar, W=optiradar-web. Investigation = done for all rows (audit text + code reading); "reproduced" is stated where done.

| ID | Impl (repo@sha) | Valid | Open |
|---|---|---|---|
| A01 OA6 Fleet->Entry | PARTIAL F@72b4541,e262d3d,31357af; E@6215ec7 | PASS-unit F feed 42 (incl. parallel-process cursor proof on PostgreSQL), E integration 94 + concurrency 4; two-backend pull NOT_RUN | owner decisions D1-D11 (source coverage), live flow, E consumes `pairing`/`review` (in progress) |
| A02 central governance | PARTIAL N@f0cf948,4eda307; F@4a85f7b,7555b9a; E@9595ddd; R@608fc11 | PASS-unit N governance suite (66 tests); PASS-fake F/E/R; PASS-real NOT_RUN | Fleet tenants stay LOCAL until an operator runs the per-tenant enable; legacy local mode does not satisfy R01-R08 (plans: E `LEGACY_IDENTITY_MIGRATION.md`, F `CENTRAL_GOVERNANCE.md`) |
| A03 bulk permissions | DONE N@9130581; E@7bc779d; F@4a85f7b; R@608fc11 | N PASS-unit; E 21 focused + baseline reproduced on a real Nexus (136 calls = 119 x 200 + 17 x 429; after: 1 request, 60-110 ms); F/R PASS-fake | none |
| A04 env forwarded to roles | DONE E@24793de, F@43f3719, N@3bcffeb, R@7abcc29 | compose config + sandbox bring-up (Ubuntu base images); Alpine image builds NOT_RUN; Windows Podman NOT_RUN | Windows Podman rehearsal |
| A05 port clash | DONE same commits (8100/5180, 8000/5173, 8200/5190, 8082) | compose config; sandbox stacks of N and F ran side by side | none |
| A06 Nexus container package | DONE N@3bcffeb,6f7e8d2,dbaed5f | sandbox stack `optinexus-orch` ran; Alpine build NOT_RUN | Podman |
| A07 Nexus env / issuer | DONE N@3bcffeb | PASS in sandbox stack | none |
| A11 Fleet single migrator | DONE F@43f3719 | sandbox stack ran, bootstrap once | none |
| A13 Radar packaging | DONE R@7abcc29 (server + Web from source, ports opt-in) | image build NOT_RUN (no Gradle/registry here) | build on a real host |
| A16 Entry .dockerignore | DONE E@24793de | image content checked in sandbox | none |
| A08 Radar OIDC | DONE R@5e98785, W@6605f24 | PASS-unit R (state/nonce/PKCE/ID token/linker); N-side conversation test N@6bc17c9; PASS-real NOT_RUN | Radar paused |
| A09 stable subject | DONE R@5e98785 (identity by issuer+subject) | PASS-unit R | multi-tenant parallel-session decision (deferred) |
| A10 telematics | DEFERRED (R read model R@83aa90f only; F partial WIP, N gateway untouched) | NOT_RUN | Radar-dependent, deferred by owner |
| A12 blank pages | PARTIAL N@6ea431e..e8f164d; E delegation/switcher states; F delegation pages; W@19cbac2 | N: 184 unit + smoke 156/156 checks at 1440/820/390 (mocked API); F/E/R full-route smoke NOT_RUN | BACKLOG: route matrix + smoke for F/E/R |
| A14 Nexus onboarding UI | DONE N@f0fa6bf,b5c329a,8e37bb7 | PASS-unit + mocked-API smoke; real backend NOT_RUN | server-side `match_state`/paging for the gateway lists, `X-Request-Id` exposure |
| A15 bundles | PARTIAL N@6ea431e..e8f164d (initial JS 496.4 -> 394.8 kB raw, 126.8 -> 121.7 gzip, measured); W@5134a71 (map lib split, not measured) | N measured (9 runs); others NOT_RUN | BACKLOG: E (1,285 kB) and F (2.37 MB) not started |
| A17 backfill/projection | DONE F@72b4541; E@6215ec7 | PASS-unit + real-DB parallel proof (F); E concurrency 4; two-backend NOT_RUN | live flow |
| A18 gap lifecycle | DONE F@e262d3d (`high_water`); E@6215ec7 | PASS-unit E 35 hardening cases | UI for gaps pending (active batch) |
| A19 feed pairing | DONE F@72b4541 (producer) | PASS-fake F; E-side check in progress | verify E@next against F@72b4541 |
| A20 pause/disconnect | DONE E@6215ec7 | PASS-unit + concurrency (pause waits for in-flight; retry during pause refused) | none |
| A21 conflicts | DONE E@6215ec7 | PASS-unit | resolution UI pending |
| A22 cursor validation | DONE E@6215ec7; F cursor proof | PASS-unit (18-case malformed-page matrix) | none |
| A23 service identity | DONE E@88fea73 (registry), N@4eda307 (scope allow-list) | E ServicePrincipalTest 29, Sso 41; N 4 tests | none |
| A24 portal gate | DONE N@c0bc909, UI N@6ea431e | PASS-unit EcosystemAccessGateTest + route inventory | none |
| A25 delegation | DONE N@0f7c744; E@9595ddd,71a0ac1; F@38320a4,9105445; R@b3a031e,W@19cbac2 | N PASS-unit; E ~50; F 16 (fake); PASS-real NOT_RUN | delegable-set review by owner |
| A26 bilingual OA6 UI | PARTIAL E@71a0ac1,9753a73 (i18n layer, delegation, switcher) | PASS-unit E 1167 frontend | OA6 screens (gaps, conflicts, outcomes) in active batch |
| A27 outcomes | DONE backend E@6215ec7 | PASS-unit | UI in active batch |
| A28 fair scheduling | DONE E@6215ec7 | PASS-unit (backlog fairness) | none |
| A29 product switcher | DONE N@0f7c744; E@9753a73; F@55a0334; R@d0ad51b,W@7b6b510 | E ProductContext 16 + TenantHint 13 + socket contract 5; F 26 (fake); PASS-real NOT_RUN | live round trip (one flow) |
| R01 Fleet independent | PARTIAL (Fleet needs only Nexus; manual odometer unchanged) | PASS-unit | gateway telematics deferred |
| R02 Radar independent | DEFERRED | NOT_RUN | owner decision |
| R03 Entry independent | DONE (core does not depend on the Fleet adapter) | PASS-unit | none |
| R04 six combinations | PARTIAL N@dbaed5f (extensible catalog, no hard-coded combos) | preflight only; combinations NOT_RUN | one live flow only (owner decision) |
| R05 Nexus authority | PARTIAL = A02 | see A02 | cutover per tenant |
| R06 SSO switching | DONE = A29 + OIDC code/PKCE | PASS-fake | live round trip |
| R07 Nexus boundary | DONE N@c0bc909 | PASS-unit | none |
| R08 delegation | DONE = A25 | see A25 | delegable-set review |
