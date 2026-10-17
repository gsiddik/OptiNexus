// @vitest-environment jsdom
import { cleanup, screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { afterEach, beforeEach, describe, expect, it } from 'vitest';
import { TOKEN_STORAGE_KEY } from '../../api/client';
import type { OnboardingReport } from '../../api/types';
import { setLocale } from '../../i18n';
import { fail, installMockApi, ok, operatorContext, type MockCall, type MockHandler } from '../../test/mockApi';
import { renderApp } from '../../test/renderApp';

const T = 't-acme';
// The shape the API returns (EcosystemOnboardingController / OnboardingReportService).
const notReady: OnboardingReport = {
  tenant: { id: T, code: 'ACME', name: 'Acme Logistics', status: 'ACTIVE' },
  ready: false,
  checks: [
    { key: 'tenant.usable', application: null, status: 'OK', message_code: 'ok', detail: null, action: null },
    { key: 'tenant.admin', application: null, status: 'MISSING', message_code: 'no_tenant_admin', detail: { count: 0 }, action: { label_key: 'onboarding.action.assign_admin', route: `/tenants/${T}/admins` } },
    { key: 'tenant.applications', application: null, status: 'OK', message_code: 'ok', detail: null, action: null },
    { key: 'optifleet.oidc_client', application: 'optifleet', status: 'MISSING', message_code: 'oidc_client_missing', detail: null, action: { label_key: 'onboarding.action.open_oidc_clients', route: '/sso-clients' } },
    { key: 'optifleet.oidc_pkce', application: 'optifleet', status: 'OK', message_code: 'ok', detail: null, action: null },
    { key: 'optifleet.service_account', application: 'optifleet', status: 'MISSING', message_code: 'service_account_missing', detail: null, action: { label_key: 'onboarding.action.open_service_accounts', route: '/service-accounts' } },
    { key: 'optifleet.manifest', application: 'optifleet', status: 'WARN', message_code: 'manifest_not_imported', detail: null, action: { label_key: 'onboarding.action.open_application', route: '/applications/app-fleet' } },
    { key: 'optifleet.users', application: 'optifleet', status: 'MISSING', message_code: 'no_user_has_access', detail: { count: 0 }, action: { label_key: 'onboarding.action.assign_access', route: `/tenants/${T}/applications` } },
    { key: 'optifleet.future_check', application: 'optifleet', status: 'WARN', message_code: 'a_code_from_the_future', detail: null, action: { label_key: 'onboarding.action.something_new', route: 'https://evil.example/x' } },
    { key: 'optiaccounting.oidc_client', application: 'optiaccounting', status: 'OK', message_code: 'ok', detail: null, action: null },
    { key: 'optiaccounting.users', application: 'optiaccounting', status: 'OK', message_code: 'ok', detail: { count: 4 }, action: null },
  ],
  applications: [
    { code: 'optifleet', name: 'OptiFleet', subscribed: true, oidc_client: null, service_account: { present: false, last_used_at: null }, manifest: { revision: null, synced_at: null }, users_with_access: 0, tenant_admins: 0 },
    { code: 'optiaccounting', name: 'OptiEntry', subscribed: true, oidc_client: { status: 'ACTIVE', pkce_required: true, redirect_uris: 2 }, service_account: { present: true, last_used_at: '2026-10-16T08:00:00Z' }, manifest: { revision: 'r7', synced_at: '2026-10-16T08:00:00Z' }, users_with_access: 4, tenant_admins: 1 },
  ],
};
const allGood: OnboardingReport = {
  ...notReady,
  ready: true,
  checks: notReady.checks.map((c) => ({ ...c, status: 'OK', message_code: 'ok', action: null })),
  applications: [notReady.applications[1]],
};

let restore: () => void = () => undefined;
let calls: MockCall[] = [];

function serve(permissions: string[], report: OnboardingReport | MockHandler = notReady) {
  const mock = installMockApi((call) => {
    if (call.url === '/auth/context') return ok(operatorContext(permissions));
    if (call.url === `/ecosystem/onboarding/tenants/${T}`) return typeof report === 'function' ? report(call) : ok(report);
    return undefined;
  });
  restore = mock.restore;
  calls = mock.calls;
}

beforeEach(() => {
  window.localStorage.clear();
  window.localStorage.setItem(TOKEN_STORAGE_KEY, 'tok');
  setLocale('en');
});
afterEach(() => {
  cleanup();
  restore();
});

const PERM = 'cgo.tenant.view';

describe('onboarding report page', () => {
  it('says not ready, counts what is missing, and links every missing item to the screen that fixes it', async () => {
    serve([PERM]);
    renderApp(`/tenants/${T}/onboarding`);
    const verdict = await screen.findByTestId('onboarding-verdict');
    expect(verdict.getAttribute('data-kind')).toBe('notReady');
    expect(verdict.textContent).toContain('Not ready: 4 required item(s) are missing.');
    expect(verdict.textContent).toContain('Missing: 4');

    const link = (testId: string) => within(screen.getByTestId(testId)).getByRole('link').getAttribute('href');
    expect(link('check-tenant.admin')).toBe(`/tenants/${T}/admins`);
    expect(link('check-optifleet.oidc_client')).toBe('/sso-clients?new=1&application=optifleet');
    expect(link('check-optifleet.service_account')).toBe('/service-accounts');
    expect(link('check-optifleet.manifest')).toBe('/applications/app-fleet');
    expect(link('check-optifleet.users')).toBe(`/tenants/${T}/applications`);
    expect(within(screen.getByTestId('check-optifleet.oidc_client')).getByRole('link').textContent).toBe('Create the SSO client');
  });

  it('shows the incomplete application as not ready and the complete one as ready', async () => {
    serve([PERM]);
    renderApp(`/tenants/${T}/onboarding`);
    const fleet = await screen.findByTestId('app-optifleet');
    expect(fleet.getAttribute('data-status')).toBe('MISSING');
    expect(within(fleet).getByText('Not ready')).toBeTruthy();
    expect(within(fleet).getByText('No active client')).toBeTruthy();
    expect(within(fleet).getByText('Not imported')).toBeTruthy();
    const entry = screen.getByTestId('app-optiaccounting');
    expect(entry.getAttribute('data-status')).toBe('OK');
    expect(within(entry).getByText('Ready')).toBeTruthy();
    expect(within(entry).getByText(/Active; PKCE required; 2 redirect URL/)).toBeTruthy();
    expect(within(entry).getByText(/Revision r7/)).toBeTruthy();
  });

  it('puts what is missing before what is done, and shows the count a check reports', async () => {
    serve([PERM]);
    renderApp(`/tenants/${T}/onboarding`);
    const fleet = await screen.findByTestId('app-optifleet');
    const statuses = within(fleet).getAllByRole('listitem').map((li) => li.getAttribute('data-status'));
    expect(statuses).toEqual([...statuses].sort((a, b) => ['MISSING', 'WARN', 'OK'].indexOf(a!) - ['MISSING', 'WARN', 'OK'].indexOf(b!)));
    expect(within(screen.getByTestId('check-optiaccounting.users')).getByText('(4)')).toBeTruthy();
  });

  it('copes with a message code it has never seen, and never turns an off-portal route into a link', async () => {
    serve([PERM]);
    renderApp(`/tenants/${T}/onboarding`);
    const row = await screen.findByTestId('check-optifleet.future_check');
    expect(row.textContent).toContain('This item needs review (a_code_from_the_future).');
    expect(within(row).queryByRole('link')).toBeNull();
    const hrefs = screen.getAllByRole('link').map((a) => a.getAttribute('href') ?? '');
    expect(hrefs.some((h) => h.includes('evil.example'))).toBe(false);
  });

  it('reads "Ready" only when everything is done', async () => {
    serve([PERM], allGood);
    renderApp(`/tenants/${T}/onboarding`);
    const verdict = await screen.findByTestId('onboarding-verdict');
    expect(verdict.getAttribute('data-kind')).toBe('ready');
    expect(verdict.textContent).toContain('Ready: nothing required is missing in OptiNexus.');
    expect(screen.getByText(/does not test the callbacks or connections on the product's own side/)).toBeTruthy();
  });

  it('an empty report is "cannot be confirmed", not success', async () => {
    serve([PERM], { ...notReady, ready: true, checks: [], applications: [] });
    renderApp(`/tenants/${T}/onboarding`);
    const verdict = await screen.findByTestId('onboarding-verdict');
    expect(verdict.getAttribute('data-kind')).toBe('unknown');
    expect(verdict.textContent).toContain('readiness cannot be confirmed');
    expect(screen.getByTestId('no-applications')).toBeTruthy();
  });

  it('a report that says ready but still carries a missing check is not ready', async () => {
    serve([PERM], { ...notReady, ready: true });
    renderApp(`/tenants/${T}/onboarding`);
    expect((await screen.findByTestId('onboarding-verdict')).getAttribute('data-kind')).toBe('notReady');
  });

  it('is in Indonesian when the screen is', async () => {
    setLocale('id');
    serve([PERM]);
    renderApp(`/tenants/${T}/onboarding`);
    const verdict = await screen.findByTestId('onboarding-verdict');
    expect(verdict.textContent).toContain('Belum siap: 4 hal wajib masih kurang.');
    expect(within(screen.getByTestId('check-tenant.admin')).getByRole('link').textContent).toBe('Tetapkan administrator');
    expect(screen.getByText('Tenant tidak memiliki admin tenant yang aktif.')).toBeTruthy();
  });

  it('is forbidden without cgo.tenant.view and does not ask for the report', async () => {
    serve(['cgo.ecosystem.access']);
    renderApp(`/tenants/${T}/onboarding`);
    expect(await screen.findByTestId('forbidden-state')).toBeTruthy();
    expect(calls.some((c) => c.url.startsWith('/ecosystem/'))).toBe(false);
  });

  it('shows a forbidden state when the server refuses the tenant, and an error with retry when it fails', async () => {
    serve([PERM], () => fail(403, 'TENANT_ACCESS_DENIED', 'no'));
    renderApp(`/tenants/${T}/onboarding`);
    expect(await screen.findByTestId('forbidden-state')).toBeTruthy();
    cleanup();
    restore();

    let up = false;
    serve([PERM], () => (up ? ok(allGood) : fail(500, 'INTERNAL_ERROR', 'boom')));
    renderApp(`/tenants/${T}/onboarding`);
    expect(await screen.findByTestId('error-state')).toBeTruthy();
    up = true;
    await userEvent.setup().click(screen.getByRole('button', { name: 'Try again' }));
    expect((await screen.findByTestId('onboarding-verdict')).getAttribute('data-kind')).toBe('ready');
  });

  it('"Check again" asks the API again', async () => {
    serve([PERM]);
    renderApp(`/tenants/${T}/onboarding`);
    await screen.findByTestId('onboarding-verdict');
    await userEvent.setup().click(screen.getByRole('button', { name: 'Check again' }));
    await screen.findByTestId('onboarding-verdict');
    expect(calls.filter((c) => c.url.startsWith('/ecosystem/')).length).toBe(2);
  });

  it('the report links land on real screens: tenant tabs, SSO clients, service accounts', async () => {
    serve([PERM, 'cgo.sso.client.view', 'cgo.sso.client.manage'], (call) => (call.url.startsWith('/ecosystem/') ? ok(notReady) : undefined));
    renderApp(`/tenants/${T}/onboarding`);
    await screen.findByTestId('onboarding-verdict');
    await userEvent.setup().click(within(screen.getByTestId('check-optifleet.oidc_client')).getByRole('link'));
    expect(await screen.findByRole('heading', { name: 'SSO Clients' })).toBeTruthy();
  });
});
