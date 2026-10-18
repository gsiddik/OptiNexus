# OptiNexus portal UI status (audit A12, A14, A15, A24; owner R07)

Scope: `frontend/` only. Detail lives in code and tests; this file is the checkpoint.

## Delivered

| Item | Result |
|---|---|
| N1 refused account (A24/R07) | Login or any call answered `403 ECOSYSTEM_ACCESS_REQUIRED` ends on `/access-denied` (en+id): token revoked and removed, no redirect loop, no blank page; any portal URL opened by such an account ends there; logout works offline; no product switcher or launch link anywhere |
| N2 SSO clients | `/sso-clients`: list, create, edit, revoke, rotate secret; exact URL lists; PKCE on by default with strong warning and acknowledgement to turn off (public clients locked on); secret shown once in a dialog (copy button, never in state after close, URL, storage or logs); permission-aware (`cgo.sso.client.view/manage`) |
| N3 vehicle mapping | `/gateway`, `/tenants/:id/gateway`: matched / unmatched / ambiguous / unlinked, search and filters, manual link and unlink, request logs, sync health; only the chosen tenant's data (`cgo.gateway.link.view/manage`, `cgo.gateway.log.view`) |
| N4 onboarding | `/onboarding`, `/tenants/:id/onboarding`: never "ready" for an empty or contradictory report; each gap has a labelled action to the right screen; unknown `message_code` gets a safe generic sentence; links limited to portal routes |
| N5 resilience | App and route error boundaries, chunk-load failure with reload (one automatic reload per minute, never offline), offline banner, session expiry notice, forbidden and unexpected states, errors show a reference id |
| N5 lazy loading | Every page except sign-in and `/access-denied` is its own file; the file of the opened address is fetched while the session is checked |

## Bundle, before and after (measured)

Command: `RUNS=9 node e2e/measure.mjs <dist-before> dist` (Chromium, `vite preview`, mocked API, localhost: only the relative numbers mean anything). Before = build of `bcb4fb9` (before this work). Sizes are JS+CSS fetched for the screen, gzip -9.

| Screen | Requests before / after | Raw before / after | Gzip before / after | First usable before / after |
|---|---|---|---|---|
| Sign-in | 4 / 5 | 496.4 / 394.8 kB (-20.5%) | 126.8 / 121.7 kB (-4.0%) | 139 / 137 ms |
| Dashboard, signed in | 4 / 6 | 496.4 / 396.1 kB (-20.2%) | 126.8 / 122.2 kB (-3.6%) | 146 / 189 ms |
| Then open Roles | +0 / +7 | 496.4 / 403.4 kB | 126.8 / 125.7 kB | 85 / 105 ms |

Whole build: 1 JS file, 485 kB raw / 124 kB gzip before; 69 files, 618 kB / 203 kB after. The growth is the new screens, i18n and boundaries plus per-file overhead; no visit downloads it all. Honest reading: the saving is mostly raw size; gzip barely moves because the entry (259 kB raw: React Router, axios, both language dictionaries, shell) is still large. A first measurement showed 441 ms for the signed-in dashboard: React holds a revealed fallback for 300 ms. Fixed by fetching the page file at start-up and handing a ready file over synchronously (`routes.tsx`, `lazyPage.ts`, test `preload.test.tsx`).
Next saving (not done): load the Indonesian dictionary on demand; merge the small shared chunks (a page opens with up to 7 requests).

## Contract gaps for OptiNexus

- Expose `X-Request-Id` / `X-Correlation-Id` in CORS (`Access-Control-Expose-Headers`): the portal shows the id it generated and sent (audited), not the server's.
- Gateway admin API returns whole lists with no paging or search; the match state (matched/unmatched/ambiguous) is derived in the browser from the gateway's plate rule. A server field would remove the duplicate rule and allow thousands of devices.
- Onboarding: no token scopes (Nexus does not store them); service account shows `present` and `last_used_at` only. Manifest import API (`POST /applications/{id}/manifest`) has no UI yet (optional).

## Decisions

- Legacy page bodies (about 55 list and detail pages) stay English; shell, navigation, errors, state views and all new screens are en+id (test `i18n.test.ts` keeps dictionaries in step and flags untranslated sentences).
- Refusal and expiry are separate states: 401 returns to sign-in with a notice; 403 `ECOSYSTEM_ACCESS_REQUIRED` goes to `/access-denied` and never to sign-in (that was the loop).
- Network or 5xx while the session is checked keeps the token and shows a retry page; an unknown remembered tenant falls back to global scope.
- Playwright is used from the machine (`PLAYWRIGHT_MODULE`), not added as a dependency.

## Validation (run in this session, from `frontend/`)

| Check | Command | Result |
|---|---|---|
| Types | `npx tsc -b` | PASS |
| Lint | `npm run lint` | PASS, 0 errors, 1 old warning (`src/api/useApi.ts:38`) |
| Unit and integration | `npm test` | PASS 184 tests, 14 files |
| Build | `npm run build` | PASS |
| Browser smoke 1440/820/390 | `PLAYWRIGHT_BROWSERS_PATH=/opt/pw-browsers node e2e/smoke.mjs` | PASS 156/156 checks, 13 scenarios at each width |
| Bundle measurement | `RUNS=9 node e2e/measure.mjs <dist-before> dist` | PASS (table above) |

NOT_RUN: real backend end to end (backend not run here); Docker image build and nginx (npm blocked in containers); Firefox and WebKit (Chromium only); Lighthouse and network throttling; automated accessibility audit (axe); native-speaker review of the Indonesian wording.

## Route coverage matrix

States = loading / empty / forbidden / error. Smoke = a Playwright scenario of `e2e/smoke.mjs`, unit = vitest file.

| Route | States | en+id | Evidence |
|---|---|---|---|
| `/login` | form error, refusal | yes | unit `session.test`; smoke login, deniedAtLogin |
| `/access-denied` | n/a (static) | yes | unit `session.test`; smoke deniedAtLogin, deniedByDirectUrl |
| `/` | loading, context error with retry | shell yes, body en | unit `session.test`; smoke dashboard, staleChunk |
| `/sso-clients` | all four | yes | unit `OidcClientsPage.test`, `oidcForm.test`, `SecretDialog.test`; smoke ssoClients, ssoForbidden |
| `/gateway`, `/tenants/:tenantId/gateway` | all four | yes | unit `GatewayPage.test`, `gatewayMapping.test`, `tenantScreens.test`; smoke gateway |
| `/onboarding`, `/tenants/:tenantId/onboarding` | all four | yes | unit `OnboardingPage.test`, `onboardingReport.test`, `tenantScreens.test`; smoke onboarding, deniedByDirectUrl |
| `/tenants`, `/tenants/:id`, `.../applications`, `.../admins` | loading, empty, error (reference id) | shell yes, body en | unit `tenantScreens.test`; smoke offline |
| `/customers`, `/customers/:id` | same | shell yes, body en | smoke forbiddenLegacyPage, sessionExpiry, offline |
| `/roles`, `/roles/:id`, `/permissions`, `/users`, `/users/:id`, `/applications`, `/applications/:id`, `/audit-logs`, `/service-accounts` | same | shell yes, body en | smoke renderError (`/roles`); no page-level test for the others (NOT_RUN) |
| `/products`, `/plans`, `/addons`, `/prices` and their `/:id`, `/pricing/simulate`, `/subscriptions` and `/:id`, `/tenants/:tenantId/entitlements`, `/usage`, `/billings` and `/:id`, `/invoices` and `/:id` | same | shell yes, body en | breadcrumbs and chunk split only; page not opened by a test (NOT_RUN) |
| `/policies`, `/workflows`, `/workflow-instances`, `/approval-definitions`, `/approval-requests`, `/integrations`, `/event-catalog`, `/event-deliveries`, `/feature-flags`, `/notification-templates`, `/notification-rules`, `/notifications` and their `/:id` | same | shell yes, body en | breadcrumbs and chunk split only; page not opened by a test (NOT_RUN) |
| `*` | redirect to `/` | n/a | `breadcrumbs.test` covers every declared route |

Every route sits behind the route error boundary: a page that throws shows a retryable state inside the shell (unit `boundaries.test`; smoke renderError, staleChunk).
