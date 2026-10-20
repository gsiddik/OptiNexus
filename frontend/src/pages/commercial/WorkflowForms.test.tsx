// @vitest-environment jsdom
import { cleanup, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { afterEach, beforeEach, expect, it } from 'vitest';
import { TOKEN_STORAGE_KEY } from '../../api/client';
import { installMockApi, ok, operatorContext, type MockCall } from '../../test/mockApi';
import { renderApp } from '../../test/renderApp';
import { setLocale } from '../../i18n';

let restore = () => {};
let calls: MockCall[];
const plan = { id: 'pl1', name: 'Fleet Standard', status: 'ACTIVE', product_id: 'p1' };
const addon = { id: 'ad1', name: 'Extra Vehicles', status: 'ACTIVE' };
const tenant = { id: 't1', name: 'Acme Tenant', status: 'ACTIVE' };
const otherTenant = { id: 't2', name: 'Other Tenant', status: 'ACTIVE' };
const subscription = { id: 's1', subscription_number: 'SUB-001', tenant_id: 't1', tenant_name: 'Acme Tenant', customer_name: 'Acme Customer', product_name: 'Fleet Suite', plan_name: 'Fleet Standard', plan_id: 'pl1', status: 'ACTIVE', items: [{ id: 'i1', item_type: 'PLAN', plan_name: 'Fleet Standard', plan_id: 'pl1' }], current_period_end: '2026-10-20T13:15:00Z' };
beforeEach(() => {
  localStorage.clear(); localStorage.setItem(TOKEN_STORAGE_KEY, 'tok'); setLocale('en');
  const mock = installMockApi(({ url, method }) => {
    if (url === '/auth/context') return ok(operatorContext(['cgo.billing.create', 'cgo.billing.view', 'cgo.pricing.simulate', 'cgo.subscription.view', 'cgo.subscription.upgrade', 'cgo.plan.view', 'cgo.plan.capability.assign', 'cgo.product.capability.assign', 'cgo.product.capability.revoke']));
    if (url === '/form-options/plans') return ok([plan, { id: 'inactive', name: 'Retired Plan', status: 'RETIRED' }]);
    if (url === '/form-options/addons') return ok([addon, { id: 'ad2', name: 'Priority Support', status: 'ACTIVE' }]);
    if (url === '/form-options/tenants') return ok([tenant, otherTenant]);
    if (url === '/form-options/subscriptions') return ok([subscription, { id: 's2', subscription_number: 'SUB-002', tenant_id: 't2', status: 'ACTIVE' }]);
    if (url === '/billings' || url === '/subscriptions') return ok([]);
    if (url === '/billing-runs') return ok({ results: [] });
    if (url === '/pricing/simulate') return ok({ components: [], currency: 'IDR', subtotal: '0', tax: '0', total: '0' });
    if (url === '/subscriptions/s1') return ok(subscription);
    if (url === '/subscriptions/s1/upgrade') return ok(subscription);
    if (url === '/plans/pl1') return ok(plan);
    if (url === '/plans/pl1/limits') return ok([]);
    if (url === '/plans/pl1/capabilities') return ok([]);
    if (url === '/plans/pl1/capability-options') return ok([{ application: { id: 'app1', name: 'OptiFleet' }, capabilities: [{ id: 'c1', name: 'Vehicles' }, { id: 'c2', name: 'Workshop' }] }]);
    if (method === 'POST' && url.startsWith('/plans/pl1/capabilities/')) return ok([]);
    return undefined;
  }); restore = mock.restore; calls = mock.calls;
});
afterEach(() => { cleanup(); restore(); });

it('links billing subscription and tenant choices and filters by tenant', async () => {
  renderApp('/billings'); await userEvent.click(await screen.findByRole('button', { name: 'Run Billing' }));
  const dialog = screen.getByRole('dialog', { name: 'Run Billing' });
  await within(dialog).findByRole('option', { name: 'SUB-001' });
  const subscriptions = within(dialog).getByLabelText('Subscription ID');
  const tenants = within(dialog).getByLabelText('Tenant ID') as HTMLSelectElement;
  await userEvent.selectOptions(subscriptions, 's1'); expect(tenants.value).toBe('t1');
  await userEvent.selectOptions(tenants, 't2');
  expect((subscriptions as HTMLSelectElement).value).toBe('');
  expect(within(dialog).queryByRole('option', { name: 'SUB-001' })).toBeNull();
  await userEvent.selectOptions(subscriptions, 's2');
  await userEvent.click(within(dialog).getByRole('button', { name: /^Run$/ }));
  await waitFor(() => expect(calls.find((c) => c.url === '/billing-runs')?.body).toMatchObject({ tenant_id: 't2', subscription_id: 's2' }));
});

it('simulates with active named plans, tenants, and multiple addons', async () => {
  renderApp('/pricing/simulate');
  await screen.findByRole('option', { name: 'Fleet Standard' });
  expect(screen.queryByRole('option', { name: 'Retired Plan' })).toBeNull();
  await userEvent.selectOptions(screen.getByLabelText('Plan ID'), 'pl1');
  await userEvent.selectOptions(screen.getByLabelText(/Tenant ID/), 't1');
  await userEvent.selectOptions(screen.getByLabelText(/Addon IDs/), ['ad1', 'ad2']);
  await userEvent.click(screen.getByRole('button', { name: 'Simulate' }));
  await waitFor(() => expect(calls.find((c) => c.url === '/pricing/simulate')?.body).toMatchObject({ plan_id: 'pl1', tenant_id: 't1', addon_ids: ['ad1', 'ad2'] }));
});

it('shows subscription relation names and changes plan by an active named option', async () => {
  renderApp('/subscriptions/s1');
  await screen.findByRole('heading', { name: 'SUB-001' });
  expect(screen.getByText('Acme Customer')).toBeTruthy(); expect(screen.getByText('Acme Tenant')).toBeTruthy(); expect(screen.getByText('Fleet Suite')).toBeTruthy();
  await screen.findByRole('option', { name: 'Fleet Standard' });
  await userEvent.selectOptions(screen.getByLabelText('New Plan ID'), 'pl1');
  await userEvent.click(screen.getByRole('button', { name: 'Upgrade' }));
  await waitFor(() => expect(calls.some((c) => c.url === '/subscriptions/s1/upgrade' && c.method === 'POST')).toBe(true));
});

it('groups product capabilities automatically and attaches every checked capability', async () => {
  renderApp('/plans/pl1');
  const attach = await screen.findByRole('button', { name: 'Attach Capability' }) as HTMLButtonElement;
  expect(attach.disabled).toBe(true);
  await userEvent.click(await screen.findByRole('checkbox', { name: 'Vehicles' }));
  await userEvent.click(screen.getByRole('checkbox', { name: 'Workshop' }));
  expect(attach.disabled).toBe(false); expect(screen.queryByRole('option', { name: /Select application/ })).toBeNull();
  await userEvent.click(attach);
  await waitFor(() => expect(calls.filter((c) => c.method === 'POST' && c.url.startsWith('/plans/pl1/capabilities/')).map((c) => c.url)).toEqual(['/plans/pl1/capabilities/c1', '/plans/pl1/capabilities/c2']));
});
