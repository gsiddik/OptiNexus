# Separate-branch follow-up: Entry verified email and merge boundaries

Date: 2026-10-18. Nexus docs branch `codex/entry-email-review-20261018`, base `c75a3e13444fc0211bde900c5615073347ccc559`.
Entry code branch `codex/entry-verified-email-20261018`, base `8f10478a45815cb364d5de587129f18771583952`.
Owner delegated implementation of the preceding follow-up prompt to Codex on separate branches; item 11 is approved for that scope only.
No merge, deployment, operational data change, backfill activation or new financial treatment is authorised.

## Delta and compatibility
- Entry delta commit `630543de58d6b5ffa1d1a2e4749d662d92471e38`, stacked [PR #11](https://github.com/gsiddik/OptiAccounting/pull/11).
- Entry delta targets the existing Claude PR branch, not main: integrate/review this delta before considering the original Entry PR #10.
- Nexus code stays at `51773cc` (docs base `c75a3e1`); Fleet stays at `8781ecd`; neither needs a code change for this decision.
- Nexus `OidcTokenService::userClaims` already emits boolean `email_verified` when scope `email` is requested, for both ID token and userinfo.
- Every Entry Nexus SSO login now requires boolean true in the validated, signed ID token before provisioning/linking/membership/ticket.
- Existing linked users cannot bypass the check. Recovery text is EN/ID. Invitation and manual operator verification both qualify.
- Stable-subject identity, existing legacy linking rules, standalone mode and existing session expiry/revocation semantics are unchanged.
- This is a next-login gate; it does not introduce continuous email-verification polling or revoke existing sessions.

## Evidence executed by Codex (not inherited PASS)
- Frontend SSO/i18n/switcher: 48 PASS; lint and production build PASS (existing bundle-size warning).
- Chromium with mocked status API: EN/ID x 1440/820/390, all 6 PASS; no overflow, render error or session.
- PHP 8.3 syntax/Pint PASS; linker-only negative smoke 7/7 PASS (no framework/DB).
- Supplemental PGlite 0.5.10 / PostgreSQL 18.6 WebAssembly: focused SSO/login/logout/hint/context 100 PASS, 814 assertions.
- New 12-case regression class fails 11/12 with the baseline linker; passes with the gate restored.
- Wider supplemental Nexus directory: 270/272 PASS. Stale-projection and outbox-backoff timing failures reproduce on baseline in the same harness.
- These are **not** native PostgreSQL 16 or live Nexus–Entry results. Initial native-DB attempt failed before assertions: no local server.
- Existing Entry CI [run 39133273115](https://github.com/gsiddik/OptiAccounting/actions/runs/39133273115) started on delta SHA; final result must be checked.
- Live verification transition, native local regression, Windows Podman, Alpine images and real mail transport: NOT_RUN in this follow-up.
- Detailed Entry checkpoint: `docs/status/SSO_EMAIL_VERIFICATION_REVIEW.md` on its delta branch.

## Deployment trigger inspection and unresolved boundary
- Entry root `ci.yml`: same on main and PR base; pull_request and push(main), install/lint/test/build only.
- Fleet root `regression.yml`: same on main and PR head; pull_request(main) / workflow_dispatch, testing only.
- Nexus main and PR head: no root Actions workflows. Nested backend/.github files are not root Actions workflow entries.
- No root hosting descriptor found for the inspected repos. External webhooks/hosting/CI settings were not accessible or verified.
- **Owner confirmation is still required:** does main feed any external deployment? Absence in source does not prove absence externally.
- No deployment trigger, setting, secret, webhook or original PR branch was changed.

## Checkpoint and verdict
- Delta implementation is ready to review; native PG16 CI / scoped live evidence remain separate validation gates. Delta PR stays draft pending validation.
- The original three PR verdicts are historical for their recorded SHAs; the new Entry delta is not automatically MERGE_RECOMMENDED.
- Review/include this delta and its Nexus documentation before relying on item 11 as implemented in main.
- After approval: original ecosystem merge order remains Nexus, then Fleet/Entry according to compatibility. No merge is performed here.
- Entry-first before backfill is a compatibility prerequisite, never permission to activate it; all cutover/review/quarantine/owner gates still apply.
- Ecosystem stays PARTIALLY_COMPLETE. Next: inspect new CI, run scoped live check when a suitable sandbox exists, resolve external auto-deploy question.
