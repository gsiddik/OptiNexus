// @vitest-environment jsdom
import { cleanup, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { afterEach, beforeEach, expect, it } from 'vitest';
import { TOKEN_STORAGE_KEY } from '../../../api/client';
import { installMockApi, ok, operatorContext } from '../../../test/mockApi';
import { renderApp } from '../../../test/renderApp';
import { setLocale } from '../../../i18n';
let restore = () => {};
beforeEach(() => { localStorage.setItem(TOKEN_STORAGE_KEY, 'tok'); setLocale('en'); });
afterEach(() => { cleanup(); restore(); });
it.each(['verify', 'reject'] as const)('reviews the selected submitted payment using %s', async (action) => {
  const calls: string[] = [];
  let status = 'SUBMITTED';
  const mock = installMockApi(({ url, method }) => {
    if (url === '/auth/context') return ok(operatorContext(['cgo.ecosystem.access', 'cgo.invoice.view', 'cgo.invoice.payment.record']));
    if (url === `/invoice-payments/p1/${action}` && method === 'POST') { calls.push(url); status = action === 'verify' ? 'VERIFIED' : 'REJECTED'; return ok({}); }
    if (url === '/invoice-payments/p1') return ok({ id: 'p1', invoice_id: 'i1', status, amount: '100.00', paid_at: '2026-10-22', method: 'BANK_TRANSFER', reference: 'REF', bank_name: 'BCA', account_name: 'Customer', note: 'Paid invoice' });
    return ok([]);
  }); restore = mock.restore;
  renderApp('/invoice-payments/p1');
  expect(await screen.findByText('BCA')).toBeTruthy();
  await userEvent.click(screen.getByRole('button', { name: action === 'verify' ? 'Mark Fully Paid' : 'Reject' }));
  await waitFor(() => expect(calls).toEqual([`/invoice-payments/p1/${action}`]));
  await waitFor(() => expect(screen.queryByRole('button', { name: 'Mark Fully Paid' })).toBeNull());
});
it('shows readable billing relations instead of identifiers', async () => {
  const mock = installMockApi(({ url }) => {
    if (url === '/auth/context') return ok(operatorContext(['cgo.ecosystem.access', 'cgo.billing.view']));
    if (url === '/billings/b1') return ok({ id: 'b1', billing_number: 'BILL-1', status: 'FINALIZED', customer_id: 'hidden-customer-id', customer_name: 'Customer One', tenant_name: 'Tenant One', subscription_number: 'SUB-001', items: [], adjustments: [] });
    return ok([]);
  }); restore = mock.restore; renderApp('/billings/b1');
  expect(await screen.findByText('Customer One')).toBeTruthy();
  expect(screen.getByText('Tenant One')).toBeTruthy(); expect(screen.getByText('SUB-001')).toBeTruthy();
  expect(screen.queryByText('hidden-customer-id')).toBeNull();
});
