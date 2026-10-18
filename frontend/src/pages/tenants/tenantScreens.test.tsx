// @vitest-environment jsdom
import { cleanup, screen, within } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it } from 'vitest';
import { TOKEN_STORAGE_KEY } from '../../api/client';
import { setLocale } from '../../i18n';
import { installMockApi, ok, operatorContext } from '../../test/mockApi';
import { renderApp } from '../../test/renderApp';

const tenant = { id: 't-1', tenant_code: 'ACME', customer_id: 'c', name: 'Acme Logistics', status: 'ACTIVE', region: null, timezone: null, currency: null, language: null };
let restore: () => void = () => undefined;

beforeEach(() => {
  window.localStorage.clear();
  window.localStorage.setItem(TOKEN_STORAGE_KEY, 'tok');
  setLocale('en');
  restore = installMockApi((call) => {
    if (call.url === '/auth/context') return ok(operatorContext(['cgo.tenant.view']));
    if (call.url === '/tenants/t-1') return ok(tenant);
    if (call.url === '/tenants') return ok([tenant], { meta: { pagination: { current_page: 1, per_page: 25, total: 1, last_page: 1 } } });
    if (call.url === '/tenants/t-1/applications') return ok([{ id: 'a1', name: 'OptiFleet' }]);
    if (call.url === '/tenants/t-1/admins') return ok([{ id: 'u1', name: 'Dewi', email: 'dewi@example.test' }]);
    if (call.url === '/applications') return ok([], { meta: { pagination: { current_page: 1, per_page: 100, total: 0, last_page: 1 } } });
    return undefined;
  }).restore;
});
afterEach(() => {
  cleanup();
  restore();
});

describe('tenant screens the onboarding report links to', () => {
  it('/tenants/:id/applications opens the tenant on its Applications tab', async () => {
    renderApp('/tenants/t-1/applications');
    expect(await screen.findByRole('heading', { name: 'Acme Logistics' })).toBeTruthy();
    expect(await screen.findByText('OptiFleet')).toBeTruthy();
    expect(screen.getByRole('button', { name: 'Applications' }).className).toContain('active');
  });

  it('/tenants/:id/admins opens the Admins tab', async () => {
    renderApp('/tenants/t-1/admins');
    expect(await screen.findByText(/Dewi/)).toBeTruthy();
    expect(screen.getByRole('button', { name: 'Admins' }).className).toContain('active');
  });

  it('the tenant page offers the onboarding report and the vehicle mapping', async () => {
    renderApp('/tenants/t-1');
    await screen.findByRole('heading', { name: 'Acme Logistics' });
    const page = within(screen.getByRole('main'));
    expect(page.getByRole('link', { name: 'Onboarding' }).getAttribute('href')).toBe('/tenants/t-1/onboarding');
    expect(page.getByRole('link', { name: 'Vehicle Mapping' }).getAttribute('href')).toBe('/tenants/t-1/gateway');
  });

  it('the menu pickers list tenants and link to the per-tenant screen', async () => {
    renderApp('/onboarding');
    const row = (await screen.findByText('Acme Logistics')).closest('tr')!;
    expect(within(row).getByRole('link', { name: 'Open' }).getAttribute('href')).toBe('/tenants/t-1/onboarding');
    cleanup();
    renderApp('/gateway');
    const row2 = (await screen.findByText('Acme Logistics')).closest('tr')!;
    expect(within(row2).getByRole('link', { name: 'Open' }).getAttribute('href')).toBe('/tenants/t-1/gateway');
  });

  it('the sidebar lists the three new screens', async () => {
    renderApp('/');
    await screen.findByText(/Welcome/);
    const hrefs = screen.getAllByRole('link').map((a) => a.getAttribute('href'));
    for (const to of ['/onboarding', '/sso-clients', '/gateway']) expect(hrefs).toContain(to);
  });
});
