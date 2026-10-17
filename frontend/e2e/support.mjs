// Shared helpers for the browser checks (smoke.mjs, measure.mjs): Playwright loader, a preview server for the production build,
// and a mocked OptiNexus API. Nothing here talks to a real backend and no secret is used: the "secret" below is a made-up string.
import { spawn } from 'node:child_process';
import { createRequire } from 'node:module';
import net from 'node:net';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const here = path.dirname(fileURLToPath(import.meta.url));
export const frontendDir = path.resolve(here, '..');

/** Playwright is not a dependency of the portal; it is used from the machine (PLAYWRIGHT_MODULE, a local install, or the global one). */
export function loadPlaywright() {
  const require = createRequire(import.meta.url);
  const candidates = [process.env.PLAYWRIGHT_MODULE, 'playwright', '/opt/node22/lib/node_modules/playwright'].filter(Boolean);
  for (const id of candidates) {
    try {
      return require(id);
    } catch {
      // try the next one
    }
  }
  console.error('NOT_RUN: Playwright was not found. Set PLAYWRIGHT_MODULE to its path (and PLAYWRIGHT_BROWSERS_PATH to the browsers).');
  process.exit(2);
}

function freePort() {
  return new Promise((resolve, reject) => {
    const server = net.createServer();
    server.listen(0, '127.0.0.1', () => {
      const { port } = server.address();
      server.close(() => resolve(port));
    });
    server.on('error', reject);
  });
}

/** Serves a production build (default ./dist) with `vite preview`; resolves with the base URL and a stop function. */
export async function startPreview(outDir = 'dist') {
  const port = await freePort();
  const child = spawn(path.join(frontendDir, 'node_modules/.bin/vite'), ['preview', '--outDir', outDir, '--port', String(port), '--strictPort', '--host', '127.0.0.1'], {
    cwd: frontendDir,
    stdio: ['ignore', 'pipe', 'pipe'],
  });
  await new Promise((resolve, reject) => {
    const timer = setTimeout(() => reject(new Error('vite preview did not start')), 20000);
    child.stdout.on('data', (chunk) => {
      if (String(chunk).includes(String(port))) {
        clearTimeout(timer);
        resolve();
      }
    });
    child.on('exit', (code) => reject(new Error(`vite preview exited (${code})`)));
  });
  return { base: `http://127.0.0.1:${port}`, stop: () => child.kill('SIGTERM') };
}

export const VIEWPORTS = [
  { name: '1440', width: 1440, height: 900 },
  { name: '820', width: 820, height: 1100 },
  { name: '390', width: 390, height: 844 },
];

// ------------------------------------------------------------------ mocked API
export const FAKE_SECRET = 'FAKE-SECRET-for-the-browser-check-only-0123456789';

const page1 = (n) => ({ pagination: { current_page: 1, per_page: 25, total: n, last_page: 1 } });
const user = { id: 'u-1', name: 'Ops Admin', email: 'ops@example.test', status: 'ACTIVE', mfa_enabled: false, email_verified_at: null, last_login_at: null, metadata: null };

export const ALL_PERMISSIONS = [
  'cgo.ecosystem.access', 'cgo.tenant.view', 'cgo.sso.client.view', 'cgo.sso.client.manage',
  'cgo.gateway.link.view', 'cgo.gateway.link.manage', 'cgo.gateway.log.view',
];

const tenants = [
  { id: 't-1', tenant_code: 'ACME', customer_id: 'c-1', name: 'Acme Logistics', status: 'ACTIVE', region: 'ID', timezone: 'Asia/Jakarta', currency: 'IDR', language: 'id', metadata: null, created_at: '2026-01-01T00:00:00Z', updated_at: '2026-01-01T00:00:00Z' },
  { id: 't-2', tenant_code: 'BETA', customer_id: 'c-2', name: 'Beta Trans', status: 'SUSPENDED', region: 'ID', timezone: null, currency: null, language: null, metadata: null, created_at: '2026-01-01T00:00:00Z', updated_at: '2026-01-01T00:00:00Z' },
];
const apps = [
  { id: 'app-fleet', application_code: 'optifleet', name: 'OptiFleet', status: 'PUBLISHED' },
  { id: 'app-entry', application_code: 'optiaccounting', name: 'OptiEntry', status: 'PUBLISHED' },
];
const clients = [
  { id: 'c-1', application_id: 'app-entry', client_id: 'onx_entryweb0001', name: 'OptiEntry web', confidential: true, redirect_uris: ['https://entry.example.com/auth/callback', 'https://entry.example.com/alt'], post_logout_redirect_uris: [], launch_url: 'https://entry.example.com/', backchannel_logout_uri: null, require_pkce: true, status: 'ACTIVE', created_at: '2026-03-01T10:00:00Z' },
  { id: 'c-2', application_id: 'app-fleet', client_id: 'onx_fleetspa0002', name: 'OptiFleet console (legacy)', confidential: false, redirect_uris: ['http://fleet.example.com/cb'], post_logout_redirect_uris: [], launch_url: null, backchannel_logout_uri: null, require_pkce: false, status: 'REVOKED', created_at: '2026-02-01T10:00:00Z' },
];
const vehicle = (id, plate) => ({ id, tenant_id: 't-1', application_id: 'app-fleet', external_vehicle_id: `veh-${id}`, registration_number: plate, normalized_registration: plate.replace(/[^A-Z0-9]/gi, '').toUpperCase(), vin: null, status: 'ACTIVE' });
const vehicles = [vehicle('v1', 'B 1234 XYZ'), vehicle('v2', 'D 5555 AB'), vehicle('v3', 'D-5555-AB'), vehicle('v4', 'L 77 QQ')];
const links = [
  { id: 'l1', telematics_source: 'traccar', device_ref: 'IMEI-100200300', device_name: 'Truck 1', device_registration: 'B 1234 XYZ', link_type: 'AUTO', status: 'ACTIVE', fleet_vehicle_id: 'v1', fleet_vehicle: { external_vehicle_id: 'veh-v1', registration_number: 'B 1234 XYZ' } },
  { id: 'l2', telematics_source: 'traccar', device_ref: 'IMEI-100200301', device_name: 'Unknown van', device_registration: 'F 1 NOPE', link_type: 'AUTO', status: 'UNMATCHED', fleet_vehicle_id: null, fleet_vehicle: null },
  { id: 'l3', telematics_source: 'gps-co', device_ref: 'IMEI-100200302', device_name: 'Twin plate', device_registration: 'D 5555 AB', link_type: 'AUTO', status: 'UNMATCHED', fleet_vehicle_id: null, fleet_vehicle: null },
  { id: 'l4', telematics_source: 'traccar', device_ref: 'IMEI-100200303', device_name: 'Spare', device_registration: null, link_type: 'MANUAL', status: 'INACTIVE', fleet_vehicle_id: 'v4', fleet_vehicle: { external_vehicle_id: 'veh-v4', registration_number: 'L 77 QQ' } },
];
const minutesAgo = (m) => new Date(Date.now() - m * 60000).toISOString();
const logs = [200, 200, 500, 200, 422, 200].map((status, i) => ({ id: `log-${i}`, tenant_id: 't-1', application_id: 'app-fleet', service_account_id: 'sa-1', method: i % 2 ? 'POST' : 'GET', path: '/gateway/v1/telematics/odometer-readings', status_code: status, duration_ms: 35 + i * 7, correlation_id: `7a1c0d2e-${i}b3f-4c55-9e10-00000000000${i}`, created_at: minutesAgo(5 + i * 9) }));
const onboarding = {
  tenant: { id: 't-1', code: 'ACME', name: 'Acme Logistics', status: 'ACTIVE' },
  ready: false,
  checks: [
    { key: 'tenant.usable', application: null, status: 'OK', message_code: 'ok', detail: null, action: null },
    { key: 'tenant.admin', application: null, status: 'MISSING', message_code: 'no_tenant_admin', detail: { count: 0 }, action: { label_key: 'onboarding.action.assign_admin', route: '/tenants/t-1/admins' } },
    { key: 'tenant.applications', application: null, status: 'OK', message_code: 'ok', detail: null, action: null },
    { key: 'optifleet.oidc_client', application: 'optifleet', status: 'MISSING', message_code: 'oidc_client_missing', detail: null, action: { label_key: 'onboarding.action.open_oidc_clients', route: '/sso-clients' } },
    { key: 'optifleet.service_account', application: 'optifleet', status: 'MISSING', message_code: 'service_account_missing', detail: null, action: { label_key: 'onboarding.action.open_service_accounts', route: '/service-accounts' } },
    { key: 'optifleet.manifest', application: 'optifleet', status: 'WARN', message_code: 'manifest_not_imported', detail: null, action: { label_key: 'onboarding.action.open_application', route: '/applications/app-fleet' } },
    { key: 'optifleet.users', application: 'optifleet', status: 'MISSING', message_code: 'no_user_has_access', detail: { count: 0 }, action: { label_key: 'onboarding.action.assign_access', route: '/tenants/t-1/applications' } },
    { key: 'optiaccounting.oidc_client', application: 'optiaccounting', status: 'OK', message_code: 'ok', detail: null, action: null },
    { key: 'optiaccounting.oidc_pkce', application: 'optiaccounting', status: 'OK', message_code: 'ok', detail: null, action: null },
    { key: 'optiaccounting.users', application: 'optiaccounting', status: 'OK', message_code: 'ok', detail: { count: 4 }, action: null },
  ],
  applications: [
    { code: 'optifleet', name: 'OptiFleet', subscribed: true, oidc_client: null, service_account: { present: false, last_used_at: null }, manifest: { revision: null, synced_at: null }, users_with_access: 0, tenant_admins: 0 },
    { code: 'optiaccounting', name: 'OptiEntry', subscribed: true, oidc_client: { status: 'ACTIVE', pkce_required: true, redirect_uris: 2 }, service_account: { present: true, last_used_at: minutesAgo(120) }, manifest: { revision: 'r7', synced_at: minutesAgo(600) }, users_with_access: 4, tenant_admins: 1 },
  ],
};

export const ok = (data, meta) => ({ status: 200, body: { success: true, data, ...(meta ? { meta } : {}) } });
export const failure = (status, code, message = code, details = null) => ({ status, body: { success: false, error: { code, message, details } } });

/**
 * Installs the mocked API on a page. `state` is mutable so a scenario can change behaviour mid-way (go offline, expire the session...).
 * `state.override(method, path, url)` may return a reply first. Every API request is pushed to `state.requests`.
 */
export async function mockApi(page, state = {}) {
  state.permissions ??= ALL_PERMISSIONS;
  state.requests ??= [];
  await page.route('**/api/v1/**', async (route) => {
    const request = route.request();
    const url = new URL(request.url());
    const pathname = url.pathname.replace(/^.*\/api\/v1/, '');
    const method = request.method();
    state.requests.push({ method, path: pathname, headers: request.headers() });
    if (request.method() === 'OPTIONS') return route.fulfill({ status: 204, headers: { 'access-control-allow-origin': '*', 'access-control-allow-headers': '*', 'access-control-allow-methods': '*' } });

    const reply = (state.override?.(method, pathname, url) ?? defaultReply(state, method, pathname)) ?? failure(404, 'NOT_FOUND', `no mock for ${method} ${pathname}`);
    await route.fulfill({
      status: reply.status,
      contentType: 'application/json',
      headers: { 'access-control-allow-origin': '*', 'access-control-allow-headers': '*' },
      body: JSON.stringify(reply.body),
    });
  });
  return state;
}

function defaultReply(state, method, pathname) {
  if (method === 'POST' && pathname === '/auth/login') return ok({ token: 'smoke-token', token_type: 'Bearer', user });
  if (method === 'POST' && pathname === '/auth/logout') return ok({ message: 'Logged out.' });
  if (pathname === '/auth/context') {
    return ok({ user, active_tenant_id: null, available_tenants: tenants, roles: [], effective_permissions: state.permissions.map((permission_key) => ({ permission_key, scope: 'GLOBAL' })) });
  }
  if (pathname === '/tenants' && method === 'GET') return ok(tenants, page1(tenants.length));
  if (/^\/tenants\/t-1$/.test(pathname)) return ok(tenants[0]);
  if (/^\/tenants\/t-1\/(applications|admins)$/.test(pathname)) return ok([]);
  if (pathname === '/applications') return ok(apps, page1(apps.length));
  if (pathname === '/oidc-clients' && method === 'GET') return ok(clients);
  if (pathname === '/oidc-clients' && method === 'POST') return { status: 201, body: { success: true, data: { client: { ...clients[0], id: 'c-9', name: 'New client', client_id: 'onx_newclient0009' }, client_secret: FAKE_SECRET } } };
  if (/^\/oidc-clients\/[\w-]+\/rotate-secret$/.test(pathname)) return ok({ client: clients[0], client_secret: FAKE_SECRET });
  if (/^\/gateway\/tenants\/t-1\/vehicle-links$/.test(pathname)) return ok(links);
  if (/^\/gateway\/tenants\/t-1\/fleet-vehicles$/.test(pathname)) return ok(vehicles);
  if (/^\/gateway\/tenants\/t-1\/request-logs$/.test(pathname)) return ok(logs, page1(logs.length));
  if (/^\/ecosystem\/onboarding\/tenants\/t-1$/.test(pathname)) return ok(onboarding);
  if (method === 'GET') return ok([], page1(0));
  return undefined;
}

// ---------------------------------------------------------------- page helpers
/** Signs the page in without the form: the token the API client reads. */
export async function withSession(context) {
  // Only on the first load of the tab: a reload after "log out" must not sign the browser in again.
  await context.addInitScript(() => {
    try {
      if (window.sessionStorage.getItem('__seeded')) return;
      window.sessionStorage.setItem('__seeded', '1');
      window.localStorage.setItem('cgo_token', 'smoke-token');
    } catch {
      // storage blocked: the scenario will show it
    }
  });
}

export async function horizontalOverflow(page) {
  return page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth);
}
