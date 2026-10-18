// Browser smoke of the production build at 1440 / 820 / 390 px against a mocked API (no backend needed):
//   npm run build && node e2e/smoke.mjs            (screenshots: SMOKE_SHOTS=/some/dir, default a temp folder outside the repo)
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import { FAKE_SECRET, failure, horizontalOverflow, loadPlaywright, mockApi, ok, startPreview, VIEWPORTS, withSession } from './support.mjs';

const { chromium } = loadPlaywright();
const shotsDir = process.env.SMOKE_SHOTS ?? fs.mkdtempSync(path.join(os.tmpdir(), 'nexus-ui-shots-'));
fs.mkdirSync(shotsDir, { recursive: true });

const results = [];
let current = '';
function check(name, condition, detail = '') {
  results.push({ scenario: current, name, pass: !!condition, detail: condition ? '' : String(detail) });
  if (!condition) console.log(`  FAIL  [${current}] ${name} ${detail}`);
}

const wait = { timeout: 10000 };

const scenarios = {
  async login({ page, base, shot }) {
    await page.goto(`${base}/login`);
    await page.getByRole('button', { name: 'Sign in' }).waitFor(wait);
    check('login form is usable', await page.getByLabel('Email').isVisible());
    check('no horizontal overflow', (await horizontalOverflow(page)) <= 0, await horizontalOverflow(page));
    await shot('login');
    await page.getByRole('button', { name: 'ID' }).click();
    check('language switch translates the form', await page.getByRole('button', { name: 'Masuk' }).isVisible());
    await shot('login-id');
  },

  async deniedAtLogin({ page, base, shot, api }) {
    api.override = (method, p) => (method === 'POST' && p === '/auth/login' ? failure(403, 'ECOSYSTEM_ACCESS_REQUIRED', 'This account cannot use the ecosystem portal.') : undefined);
    await page.goto(`${base}/login`);
    await page.getByLabel('Email').fill('tenant.admin@example.test');
    await page.getByLabel('Password').fill('a-long-enough-password');
    await page.getByRole('button', { name: 'Sign in' }).click();
    await page.getByTestId('access-denied').waitFor(wait);
    check('explanation page shown', await page.getByRole('heading', { name: 'This account cannot use the ecosystem portal' }).isVisible());
    check('no token kept', (await page.evaluate(() => localStorage.getItem('cgo_token'))) === null);
    check('only the sign-in request was made (no context, no data)', api.requests.filter((r) => r.path !== '/auth/login').length === 0, JSON.stringify(api.requests.map((r) => r.path)));
    check('no portal navigation, no product links', (await page.locator('nav a, aside a, main a').count()) === 0);
    check('no horizontal overflow', (await horizontalOverflow(page)) <= 0);
    await shot('denied');
    await page.getByRole('button', { name: 'ID' }).click();
    check('Indonesian explanation', await page.getByRole('heading', { name: 'Akun ini tidak dapat menggunakan portal ekosistem' }).isVisible());
    await shot('denied-id');
    await page.getByRole('button', { name: 'Masuk dengan akun lain' }).click();
    check('"another account" returns to a working sign-in form (no loop)', await page.getByRole('button', { name: 'Masuk' }).isVisible());
  },

  async deniedByDirectUrl({ page, context, base, shot, api }) {
    await withSession(context);
    api.override = (method, p) => (p === '/auth/logout' ? undefined : failure(403, 'ECOSYSTEM_ACCESS_REQUIRED', 'This account cannot use the ecosystem portal.'));
    await page.goto(`${base}/tenants/t-1/onboarding`);
    await page.getByTestId('access-denied').waitFor(wait);
    check('direct portal URL ends on the explanation page', page.url().endsWith('/access-denied'), page.url());
    check('token removed', (await page.evaluate(() => localStorage.getItem('cgo_token'))) === null);
    await page.waitForTimeout(300);
    check('token revoked on the server exactly once', api.requests.filter((r) => r.path === '/auth/logout').length === 1);
    check('no request loop', api.requests.length <= 3, api.requests.length);
    await page.goto(`${base}/customers`);
    check('a second portal URL without a session goes to sign-in, not a blank page', await page.getByRole('button', { name: 'Sign in' }).isVisible());
    await shot('denied-direct');
  },

  async dashboard({ page, context, base, shot, viewport }) {
    await withSession(context);
    await page.goto(`${base}/`);
    await page.getByRole('heading', { name: 'Welcome, Ops Admin' }).waitFor(wait);
    check('no horizontal overflow', (await horizontalOverflow(page)) <= 0, await horizontalOverflow(page));
    const links = await page.locator('a').evaluateAll((as) => as.map((a) => a.getAttribute('href') ?? ''));
    check('shell links stay inside the portal (no product switcher)', links.every((h) => h.startsWith('/') || h.startsWith('#')), JSON.stringify(links.filter((h) => !h.startsWith('/'))));
    check('menu lists the new screens', ['/onboarding', '/sso-clients', '/gateway'].every((h) => links.includes(h)));
    await shot('dashboard');
    if (viewport.width <= 900) {
      await page.getByRole('button', { name: 'Open menu' }).click();
      await page.getByRole('link', { name: 'SSO Clients' }).waitFor(wait);
      await shot('menu-open');
    }
    await page.evaluate(() => localStorage.setItem('optinexus.locale', 'id'));
    await page.reload();
    await page.getByRole('heading', { name: 'Welcome, Ops Admin' }).waitFor(wait);
    check('sidebar and top bar are in Indonesian', (await page.getByText('Keluar').count()) > 0 && (await page.locator('a[href="/"]').count()) > 0);
  },

  async ssoClients({ page, context, base, shot, api, viewport }) {
    await context.grantPermissions(['clipboard-read', 'clipboard-write'], { origin: base });
    await withSession(context);
    await page.goto(`${base}/sso-clients`);
    await page.getByText('OptiEntry web').waitFor(wait);
    check('list shows type, PKCE and status badges', (await page.getByText('PKCE required').count()) > 0 && (await page.getByText('PKCE off').count()) > 0 && (await page.getByText('Revoked').count()) > 0);
    check('no horizontal overflow of the page', (await horizontalOverflow(page)) <= 0, await horizontalOverflow(page));
    const edit = await page.getByRole('button', { name: 'Edit' }).first().boundingBox();
    check('row actions stay in view without scrolling the table', !!edit && edit.x >= 0 && edit.x + edit.width <= viewport.width, JSON.stringify(edit));
    await shot('sso-list');
    await page.getByRole('button', { name: '+ New client' }).click();
    const dialog = page.getByRole('dialog', { name: 'New SSO client' });
    await dialog.waitFor(wait);
    check('PKCE is on by default', await dialog.getByRole('checkbox', { name: /Require PKCE/ }).isChecked());
    await shot('sso-create');
    await dialog.getByRole('checkbox', { name: /Require PKCE/ }).uncheck();
    check('turning PKCE off shows the strong warning', await dialog.getByTestId('pkce-warning').isVisible());
    await shot('sso-pkce-off');
    await dialog.getByRole('checkbox', { name: /Require PKCE/ }).check();
    await dialog.getByLabel('Application', { exact: true }).selectOption('app-entry');
    await dialog.getByLabel('Client name').fill('New client');
    await dialog.getByLabel(/Redirect URLs - URL 1/).fill('https://*.example.com/cb');
    await dialog.getByRole('button', { name: 'Create client' }).click();
    check('wildcard URL is refused beside the field', await dialog.getByText('Wildcards are not allowed: the URL must be exact.').isVisible());
    await dialog.getByLabel(/Redirect URLs - URL 1/).fill('https://entry.example.com/auth/callback');
    await dialog.getByRole('button', { name: 'Create client' }).click();
    const secretDialog = page.getByTestId('secret-dialog');
    await secretDialog.waitFor(wait);
    check('secret shown once', (await secretDialog.getByTestId('client-secret').textContent()) === FAKE_SECRET);
    await shot('sso-secret');
    await secretDialog.getByRole('button', { name: 'Copy secret' }).click();
    check('copy button copies the secret', (await page.evaluate(() => navigator.clipboard.readText())) === FAKE_SECRET);
    await secretDialog.getByRole('button', { name: 'Close' }).click();
    await secretDialog.waitFor({ state: 'detached', ...wait });
    const leaks = await page.evaluate((secret) => ({
      dom: document.documentElement.outerHTML.includes(secret),
      url: location.href.includes(secret),
      local: JSON.stringify({ ...localStorage }).includes(secret),
      session: JSON.stringify({ ...sessionStorage }).includes(secret),
    }), FAKE_SECRET);
    check('secret is gone from page, address and storage after closing', !leaks.dom && !leaks.url && !leaks.local && !leaks.session, JSON.stringify(leaks));
    const post = api.requests.find((r) => r.method === 'POST' && r.path === '/oidc-clients');
    check('create request was made once', !!post && api.requests.filter((r) => r.method === 'POST' && r.path === '/oidc-clients').length === 1);
  },

  async ssoForbidden({ page, context, base, shot, api }) {
    api.permissions = ['cgo.ecosystem.access'];
    await withSession(context);
    await page.goto(`${base}/sso-clients`);
    await page.getByTestId('forbidden-state').waitFor(wait);
    check('forbidden state names the permission', (await page.getByTestId('forbidden-state').textContent()).includes('cgo.sso.client.view'));
    check('the list was never requested', !api.requests.some((r) => r.path === '/oidc-clients'));
    await shot('sso-forbidden');
  },

  async gateway({ page, context, base, shot, api }) {
    await withSession(context);
    await page.goto(`${base}/tenants/t-1/gateway`);
    await page.getByText('Truck 1').waitFor(wait);
    check('counts per state', (await page.getByTestId('count-ambiguous').textContent()).includes('1') && (await page.getByTestId('count-matched').textContent()).includes('1'));
    check('only this tenant is requested', api.requests.filter((r) => r.path.startsWith('/gateway/')).every((r) => r.path.startsWith('/gateway/tenants/t-1/')));
    check('no horizontal overflow of the page', (await horizontalOverflow(page)) <= 0, await horizontalOverflow(page));
    await shot('gateway-links');
    await page.getByRole('row', { name: /Twin plate/ }).getByRole('button', { name: 'Link to vehicle' }).click();
    const dialog = page.getByRole('dialog');
    await dialog.waitFor(wait);
    await shot('gateway-link-dialog');
    await dialog.getByRole('button', { name: 'Cancel' }).click();
    await page.getByRole('tab', { name: 'Requests and sync health' }).click();
    await page.getByText('7a1c0d2e-2b3f-4c55-9e10-000000000002').waitFor(wait);
    await shot('gateway-logs');
  },

  async onboarding({ page, context, base, shot }) {
    await withSession(context);
    await page.goto(`${base}/tenants/t-1/onboarding`);
    const verdict = page.getByTestId('onboarding-verdict');
    await verdict.waitFor(wait);
    check('not ready is stated', (await verdict.getAttribute('data-kind')) === 'notReady');
    check('no horizontal overflow', (await horizontalOverflow(page)) <= 0, await horizontalOverflow(page));
    await shot('onboarding');
    await page.getByTestId('check-optifleet.oidc_client').getByRole('link').click();
    await page.getByRole('heading', { name: 'SSO Clients' }).waitFor(wait);
    const form = page.getByRole('dialog', { name: 'New SSO client' });
    await form.waitFor(wait);
    check('the action opens the SSO client form for that application', (await form.getByLabel('Application', { exact: true }).inputValue()) === 'app-fleet');
    await shot('onboarding-to-sso');
  },

  async renderError({ page, context, base, shot, api }) {
    await withSession(context);
    // An answer that is not the list the page expects makes the page itself throw while rendering.
    api.override = (method, p) => (method === 'GET' && p === '/roles' ? ok({ unexpected: 'object' }) : undefined);
    await page.goto(`${base}/roles`);
    await page.getByTestId('render-error').waitFor(wait);
    check('route boundary message with a reference', (await page.getByTestId('render-error').textContent()).includes('Reference:'));
    check('the menu and top bar are still there', await page.getByRole('button', { name: 'Log out' }).isVisible());
    await shot('route-error');
    api.override = undefined;
    const open = await page.getByRole('button', { name: 'Open menu' }).isVisible();
    if (open) await page.getByRole('button', { name: 'Open menu' }).click();
    await page.getByRole('link', { name: 'Customers' }).click();
    await page.getByRole('heading', { name: 'Customers' }).waitFor(wait);
    check('navigating on recovers the page', true);
  },

  async staleChunk({ page, context, base, shot }) {
    await withSession(context);
    await page.route('**/assets/RolesListPage-*.js', (route) => route.abort('failed'));
    await page.goto(`${base}/`);
    await page.getByRole('heading', { name: 'Welcome, Ops Admin' }).waitFor(wait);
    if (await page.getByRole('button', { name: 'Open menu' }).isVisible()) await page.getByRole('button', { name: 'Open menu' }).click();
    await page.getByRole('link', { name: 'Roles', exact: true }).click();
    await page.getByTestId('chunk-error').waitFor({ timeout: 15000 });
    check('chunk failure shows the reload message (after one automatic reload at most)', (await page.getByTestId('chunk-error').textContent()).includes('Part of the portal could not be loaded'));
    check('reload action present', await page.getByRole('button', { name: 'Reload page' }).isVisible());
    check('the shell is still usable', await page.getByRole('button', { name: 'Log out' }).isVisible());
    await shot('chunk-error');
    await page.unroute('**/assets/RolesListPage-*.js');
    await page.getByRole('button', { name: 'Reload page' }).click();
    await page.getByRole('heading', { name: 'Roles' }).first().waitFor(wait);
    check('Reload fetches the page again', true);
  },

  async sessionExpiry({ page, context, base, shot, api }) {
    await withSession(context);
    api.override = (method, p) => (p === '/customers' ? failure(401, 'UNAUTHENTICATED', 'Authentication is required to access this resource.') : undefined);
    await page.goto(`${base}/customers`);
    await page.getByText('Your session has expired. Sign in again to continue.').waitFor(wait);
    check('token cleared', (await page.evaluate(() => localStorage.getItem('cgo_token'))) === null);
    await shot('session-expired');
  },

  async forbiddenLegacyPage({ page, context, base, shot, api }) {
    await withSession(context);
    api.override = (method, p) => (p === '/customers' ? failure(403, 'UNAUTHORIZED', 'You do not have the [cgo.customer.view] permission required for this action.') : undefined);
    await page.goto(`${base}/customers`);
    const alert = page.getByRole('alert').filter({ hasText: 'cgo.customer.view' });
    await alert.waitFor(wait);
    check('permission refusal is shown with a reference', /\(Ref: \w+\)/.test(await alert.textContent()));
    await shot('forbidden-legacy');
  },

  async offline({ page, context, base, shot }) {
    await withSession(context);
    await page.goto(`${base}/tenants`);
    await page.getByRole('heading', { name: 'Tenants' }).first().waitFor(wait); // its page file is now cached by the browser
    if (await page.getByRole('button', { name: 'Open menu' }).isVisible()) await page.getByRole('button', { name: 'Open menu' }).click();
    await page.getByRole('link', { name: 'Dashboard', exact: true }).first().click();
    await page.getByRole('heading', { name: 'Welcome, Ops Admin' }).waitFor(wait);
    await context.setOffline(true);
    // Requests answered by the mock never touch the network, so cut them the way a dropped connection would.
    const cut = (route) => route.abort('internetdisconnected');
    await page.route('**/api/v1/**', cut);
    await page.getByTestId('offline-banner').waitFor(wait);
    check('offline banner', await page.getByTestId('offline-banner').isVisible());
    // A page whose file is already loaded: only its data request fails.
    if (await page.getByRole('button', { name: 'Open menu' }).isVisible()) await page.getByRole('button', { name: 'Open menu' }).click();
    await page.getByRole('link', { name: 'Tenants' }).click();
    await page.getByRole('alert').filter({ hasText: 'Cannot reach the server' }).waitFor(wait);
    check('a loaded page whose data cannot be fetched says the server cannot be reached', true);
    check('the shell stays usable offline', await page.getByRole('button', { name: 'Log out' }).isVisible());
    await page.waitForTimeout(500); // the mobile menu slides away first
    await shot('offline');
    // A page whose file was never loaded: the file cannot be fetched either. No reload into the browser's offline page.
    if (await page.getByRole('button', { name: 'Open menu' }).isVisible()) await page.getByRole('button', { name: 'Open menu' }).click();
    await page.getByRole('link', { name: 'Customers' }).click();
    await page.getByTestId('chunk-error').waitFor(wait);
    check('offline navigation to a new page shows the reload message inside the portal (no blank page)', await page.getByRole('button', { name: 'Log out' }).isVisible());
    await page.waitForTimeout(500); // the mobile menu slides away first
    await shot('offline-new-page');
    await context.setOffline(false);
    await page.unroute('**/api/v1/**', cut);
    await page.getByTestId('offline-banner').waitFor({ state: 'detached', ...wait });
    check('banner leaves when the connection returns', true);
    await page.getByRole('button', { name: 'Reload page' }).click();
    await page.getByRole('heading', { name: 'Customers' }).first().waitFor(wait);
    check('Reload brings the page back', true);
  },
};

const only = process.env.SMOKE_ONLY?.split(',');
const preview = await startPreview(process.env.SMOKE_DIST ?? 'dist');
const browser = await chromium.launch();
let consoleProblems = 0;
try {
  for (const viewport of VIEWPORTS) {
    for (const [name, run] of Object.entries(scenarios)) {
      if (only && !only.includes(name)) continue;
      current = `${name}@${viewport.name}`;
      const context = await browser.newContext({ viewport: { width: viewport.width, height: viewport.height }, locale: 'en-GB' });
      const page = await context.newPage();
      const problems = [];
      page.on('pageerror', (err) => problems.push(`pageerror: ${err.message}`));
      page.on('console', (msg) => {
        if (msg.type() !== 'error') return;
        const text = msg.text();
        // Expected noise of the failure scenarios: the browser logs every refused/aborted request itself.
        if (/Failed to load resource|net::ERR|status of (401|403|404|500)|\[portal\] render error|The above error occurred|Error: Uncaught|TypeError: .*(map|filter)|dynamically imported module|ChunkLoadError/i.test(text)) return;
        problems.push(`console: ${text}`);
      });
      const api = await mockApi(page);
      const shot = async (label) => page.screenshot({ path: path.join(shotsDir, `${label}-${viewport.name}.png`), fullPage: false });
      try {
        await run({ page, context, base: preview.base, shot, api, viewport });
      } catch (err) {
        check('scenario completed', false, err.message.split('\n')[0]);
      }
      if (problems.length) {
        consoleProblems += problems.length;
        check('no unexpected console or page errors', false, problems.slice(0, 2).join(' | '));
      }
      await context.close();
    }
  }
} finally {
  await browser.close();
  preview.stop();
}

const failed = results.filter((r) => !r.pass);
console.log(`\nSmoke: ${results.length - failed.length}/${results.length} checks passed across ${VIEWPORTS.map((v) => v.name).join(' / ')} px; screenshots in ${shotsDir}`);
process.exit(failed.length ? 1 : 0);
