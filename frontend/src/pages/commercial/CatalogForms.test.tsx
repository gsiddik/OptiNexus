// @vitest-environment jsdom
import { cleanup, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { afterEach, beforeEach, describe, expect, it } from 'vitest';
import { TOKEN_STORAGE_KEY } from '../../api/client';
import { fail, installMockApi, ok, operatorContext, type MockCall, type MockHandler } from '../../test/mockApi';
import { renderApp } from '../../test/renderApp';
import { setLocale } from '../../i18n';

const customer = { id: 'c1', legal_name: 'Acme Logistics', customer_code: 'CUS-1' };
const otherCustomer = { id: 'c2', legal_name: 'Second Customer', customer_code: 'CUS-2' };
const tenant = { id: 't1', customer_id: 'c1', name: 'Acme Tenant', tenant_code: 'TEN-1' };
const otherTenant = { id: 't2', customer_id: 'c2', name: 'Second Tenant', tenant_code: 'TEN-2' };
const product = { id: 'p1', name: 'Fleet Suite', product_code: 'PRD-1', status: 'DRAFT', created_at: '2026-10-01T00:00:00Z' };
const plan = { id: 'plan1', name: 'Fleet Plan', plan_code: 'PLN-1', status: 'ACTIVE' };
const addon = { id: 'addon1', name: 'Extra Vehicles', addon_code: 'ADD-1', status: 'ACTIVE' };
const app = { id: 'app1', name: 'OptiFleet', application_code: 'optifleet' };
const cap1 = { id: 'cap1', name: 'Vehicle Management', code: 'vehicles', application_id: 'app1' };
const cap2 = { id: 'cap2', name: 'Workshop Management', code: 'workshop', application_id: 'app1' };
const PERMISSIONS = ['cgo.ecosystem.access', 'cgo.customer.view', 'cgo.customer.create', 'cgo.tenant.view', 'cgo.tenant.create',
  'cgo.product.view', 'cgo.product.create', 'cgo.product.application.assign', 'cgo.product.application.revoke',
  'cgo.product.capability.assign', 'cgo.product.capability.revoke', 'cgo.plan.view', 'cgo.plan.create',
  'cgo.addon.view', 'cgo.addon.create', 'cgo.subscription.view', 'cgo.subscription.create', 'cgo.pricing.view', 'cgo.pricing.create'];
let restore = () => {};
let calls: MockCall[] = [];
let selected = [cap1];
let attached = [app];

function serve(extra?: MockHandler) {
  const mock = installMockApi((call) => {
    const custom = extra?.(call);
    if (custom) return custom;
    const { method, url } = call;
    if (url === '/auth/context') return ok(operatorContext(PERMISSIONS));
    if (url === '/subscriptions/options/customers') return ok([customer, otherCustomer]);
    if (url === '/subscriptions/options/tenants') return ok(call.params?.customer_id === 'c1' ? [tenant] : [otherTenant]);
    if (url === '/form-options/plans') return ok([plan]);
    if (url === '/form-options/addons') return ok([addon]);
    if (url === '/products/options/applications') return ok([app]);
    if (url === '/products/p1') return ok(product);
    if (url === '/products/p1/applications') return ok(attached);
    if (url === '/products/p1/applications/app1' && method === 'POST') { attached = [app]; return ok(attached); }
    if (url === '/products/p1/capability-options') return ok(attached.map((application) => ({ application, capabilities: [cap1, cap2] })));
    if (url === '/products/p1/capabilities') {
      if (method === 'PUT') {
        const ids = (call.body as { capability_ids: string[] }).capability_ids;
        selected = [cap1, cap2].filter((cap) => ids.includes(cap.id));
      }
      return ok(selected);
    }
    const lists: Record<string, unknown[]> = { '/customers': [customer], '/tenants': [tenant], '/products': [product], '/plans': [plan], '/addons': [addon], '/subscriptions': [], '/prices': [] };
    if (method === 'GET' && url in lists) return ok(lists[url]);
    if (method === 'POST' && url in lists) return ok({ id: 'new' });
    return undefined;
  });
  restore = mock.restore;
  calls = mock.calls;
}
beforeEach(() => {
  localStorage.clear();
  localStorage.setItem(TOKEN_STORAGE_KEY, 'tok');
  setLocale('en');
  selected = [cap1];
  attached = [app];
});
afterEach(() => { cleanup(); restore(); });

async function open(path: string, title: string) {
  renderApp(path);
  await userEvent.click(await screen.findByRole('button', { name: `+ ${title}` }, { timeout: 5000 }));
  return screen.getByRole('dialog', { name: title });
}

describe('catalog relation forms', () => {
  it.each([
    ['/customers', 'New Customer', 'Customer Code', 'customer_code'],
    ['/tenants', 'New Tenant', 'Tenant Code', 'tenant_code'],
    ['/products', 'New Product', 'Product Code', 'product_code'],
    ['/plans', 'New Plan', 'Plan Code', 'plan_code'],
    ['/addons', 'New Add-on', 'Addon Code', 'addon_code'],
  ])('generates the code for %s without allowing manual input', async (path, title, label, codeKey) => {
    serve();
    const dialog = await open(path, title);
    const code = within(dialog).getByLabelText(label) as HTMLInputElement;
    expect(code.readOnly).toBe(true);
    await userEvent.type(within(dialog).getByLabelText(title === 'New Customer' ? 'Legal Name' : 'Name', { exact: true }), 'New record');
    if (title === 'New Tenant') await userEvent.selectOptions(within(dialog).getByLabelText('Customer', { exact: true }), 'c1');
    if (title === 'New Plan' || title === 'New Add-on') {
      await within(dialog).findByRole('option', { name: 'Fleet Suite (PRD-1)' });
      await userEvent.selectOptions(within(dialog).getByLabelText('Product ID'), 'p1');
    }
    await userEvent.click(within(dialog).getByRole('button', { name: 'Create' }));
    await waitFor(() => expect(calls.some((call) => call.method === 'POST' && call.url === path)).toBe(true));
    expect(calls.find((call) => call.method === 'POST' && call.url === path)?.body).not.toHaveProperty(codeKey);
  });

  it('loads products beyond the first page for the new plan selector', async () => {
    serve((call) => call.url === '/products' && call.method === 'GET' ? ok(call.params?.page === 2 ? [{ ...product, id: 'p2', name: 'Last Product' }] : [product], { meta: { pagination: { last_page: 2 } } }) : undefined);
    const dialog = await open('/plans', 'New Plan');
    expect(await within(dialog).findByRole('option', { name: 'Last Product (PRD-1)' })).toBeTruthy();
  });

  it('filters tenants by customer, clears an old tenant, and submits their real IDs', async () => {
    serve();
    const dialog = await open('/subscriptions', 'New Subscription');
    const customerSelect = within(dialog).getByLabelText('Customer ID');
    const tenantSelect = within(dialog).getByLabelText('Tenant ID') as HTMLSelectElement;
    expect(tenantSelect.disabled).toBe(true);
    await within(dialog).findByRole('option', { name: /Acme Logistics.*CUS-1.*c1/ });
    await userEvent.selectOptions(customerSelect, 'c1');
    await within(dialog).findByRole('option', { name: /Acme Tenant.*TEN-1.*t1/ });
    await userEvent.selectOptions(tenantSelect, 't1');
    await userEvent.selectOptions(customerSelect, 'c2');
    expect(tenantSelect.value).toBe('');
    expect(within(dialog).queryByRole('option', { name: /Acme Tenant/ })).toBeNull();
    await within(dialog).findByRole('option', { name: /Second Tenant/ });
    await userEvent.selectOptions(tenantSelect, 't2');
    await within(dialog).findByRole('option', { name: 'Fleet Plan' });
    await userEvent.selectOptions(within(dialog).getByLabelText('Plan ID', { exact: true }), 'plan1');
    await userEvent.click(within(dialog).getByRole('button', { name: 'Create' }));
    await waitFor(() => expect(calls.find((call) => call.method === 'POST' && call.url === '/subscriptions')?.body).toMatchObject({ customer_id: 'c2', tenant_id: 't2' }));
  });

  it.each([['plan:plan1', 'plan_id', 'plan1', 'addon_id'], ['addon:addon1', 'addon_id', 'addon1', 'plan_id']])('prices %s using one target only', async (target, field, id, absent) => {
    serve();
    const dialog = await open('/prices', 'New Price');
    await within(dialog).findByRole('option', { name: 'Extra Vehicles (ADD-1)' });
    expect(within(dialog).queryByLabelText('Addon ID', { exact: true })).toBeNull();
    await userEvent.selectOptions(within(dialog).getByLabelText('Plan ID / Addon ID'), target);
    await userEvent.type(within(dialog).getByLabelText('Unit Amount'), '10000');
    await userEvent.click(within(dialog).getByRole('button', { name: 'Create' }));
    await waitFor(() => expect(calls.find((call) => call.method === 'POST' && call.url === '/prices')?.body).toHaveProperty(field, id));
    expect(calls.find((call) => call.method === 'POST' && call.url === '/prices')?.body).not.toHaveProperty(absent);
  });

  it('shows all application capabilities, saves checkboxes, and persists them after reloading', async () => {
    serve();
    const rendered = renderApp('/products/p1');
    const first = await screen.findByRole('checkbox', { name: /Vehicle Management/ }) as HTMLInputElement;
    const second = screen.getByRole('checkbox', { name: /Workshop Management/ }) as HTMLInputElement;
    expect(first.checked).toBe(true);
    expect(second.checked).toBe(false);
    await userEvent.click(second);
    await userEvent.click(screen.getByRole('button', { name: 'Save Capabilities' }));
    await screen.findByRole('status');
    expect(calls.find((call) => call.method === 'PUT' && call.url === '/products/p1/capabilities')?.body).toEqual({ capability_ids: ['cap1', 'cap2'] });
    rendered.unmount();
    renderApp('/products/p1');
    expect((await screen.findByRole('checkbox', { name: /Workshop Management/ }) as HTMLInputElement).checked).toBe(true);
  });

  it('selects and clears all product capabilities within the application group', async () => {
    serve(); renderApp('/products/p1');
    const all = await screen.findByRole('checkbox', { name: 'Select All' });
    await userEvent.click(all);
    expect((screen.getByRole('checkbox', { name: /Workshop Management/ }) as HTMLInputElement).checked).toBe(true);
    await userEvent.click(screen.getByRole('button', { name: 'Save Capabilities' }));
    await screen.findByRole('status');
    expect(calls.find((c) => c.method === 'PUT')?.body).toEqual({ capability_ids: ['cap1', 'cap2'] });
    await userEvent.click(all);
    expect((screen.getByRole('checkbox', { name: /Vehicle Management/ }) as HTMLInputElement).checked).toBe(false);
    expect((screen.getByRole('checkbox', { name: /Workshop Management/ }) as HTMLInputElement).checked).toBe(false);
  });

  it('refreshes capability choices immediately after attaching an application', async () => {
    attached = [];
    selected = [];
    serve();
    renderApp('/products/p1');
    await screen.findByText('Attach an application to choose its capabilities.');
    await userEvent.selectOptions(screen.getByRole('combobox', { name: 'Application to attach' }), 'app1');
    await userEvent.click(screen.getByRole('button', { name: 'Attach Application' }));
    expect(await screen.findByRole('checkbox', { name: /Vehicle Management/ })).toBeTruthy();
  });

  it('lets an assign-only operator undo an unsaved addition without granting revoke access', async () => {
    serve((call) => call.url === '/auth/context' ? ok(operatorContext(PERMISSIONS.filter((permission) => permission !== 'cgo.product.capability.revoke'))) : undefined);
    renderApp('/products/p1');
    const existing = await screen.findByRole('checkbox', { name: /Vehicle Management/ }) as HTMLInputElement;
    const addition = screen.getByRole('checkbox', { name: /Workshop Management/ }) as HTMLInputElement;
    expect(existing.disabled).toBe(true);
    await userEvent.click(addition);
    expect(addition.checked).toBe(true);
    expect(addition.disabled).toBe(false);
    await userEvent.click(addition);
    expect(addition.checked).toBe(false);
    await userEvent.click(addition);
    await userEvent.click(screen.getByRole('button', { name: 'Save Capabilities' }));
    await screen.findByRole('status');
    expect(calls.find((call) => call.method === 'PUT' && call.url === '/products/p1/capabilities')?.body).toEqual({ capability_ids: ['cap1', 'cap2'] });
  });

  it('keeps checkbox choices after a save failure and allows retry', async () => {
    let reject = true;
    serve((call) => call.method === 'PUT' && call.url === '/products/p1/capabilities' && reject ? fail(500, 'INTERNAL_ERROR', 'Please retry') : undefined);
    renderApp('/products/p1');
    const second = await screen.findByRole('checkbox', { name: /Workshop Management/ }) as HTMLInputElement;
    await userEvent.click(second);
    await userEvent.click(screen.getByRole('button', { name: 'Save Capabilities' }));
    await screen.findByText(/Please retry/);
    expect(second.checked).toBe(true);
    reject = false;
    await userEvent.click(screen.getByRole('button', { name: 'Save Capabilities' }));
    await screen.findByRole('status');
  });
});
