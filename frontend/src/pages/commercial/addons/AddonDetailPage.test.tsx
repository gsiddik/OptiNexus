// @vitest-environment jsdom
import { cleanup, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { afterEach, beforeEach, expect, it } from 'vitest';
import { TOKEN_STORAGE_KEY } from '../../../api/client';
import { fail, installMockApi, ok, operatorContext, type MockCall } from '../../../test/mockApi';
import { renderApp } from '../../../test/renderApp';
import { setLocale } from '../../../i18n';

let restore = () => {};
let calls: MockCall[] = [];
const limit = { id: 'l1', addon_id: 'p1', limit_key: 'vehicle_limit', limit_delta: '10', unit: 'vehicle' };
function serve(edit = true, reject = false) {
  let limits = [limit];
  const mock = installMockApi(({ method, url }) => {
    if (url === '/auth/context') return ok(operatorContext(['cgo.ecosystem.access', 'cgo.addon.view', ...(edit ? ['cgo.addon.limit.configure'] : [])]));
    if (url === '/addons/p1') return ok({ id: 'p1', name: 'Fleet Addon', product_id: 'private-product-id', product_name: 'Fleet Suite', addon_code: 'PLN-1', status: 'ACTIVE' });
    if (url === '/addons/p1/limits') return ok(limits);
    if (url === '/addons/p1/limits/l1' && method === 'DELETE') {
      if (reject) return fail(409, 'LIMIT_BUSY', 'Cannot remove this limit.');
      limits = []; return ok(null);
    }
    if (url === '/addons/p1/capabilities' || url === '/addons/p1/capability-options') return ok([]);
    return undefined;
  });
  restore = mock.restore; calls = mock.calls;
  renderApp('/addons/p1');
}
beforeEach(() => { localStorage.clear(); localStorage.setItem(TOKEN_STORAGE_KEY, 'tok'); setLocale('en'); });
afterEach(() => { cleanup(); restore(); });

it('shows the product name and removes a limit after confirmation, then refreshes the list', async () => {
  serve();
  expect(await screen.findByText('Fleet Suite')).toBeTruthy();
  expect(screen.queryByText('Product ID')).toBeNull();
  expect(screen.queryByText('private-product-id')).toBeNull();
  await userEvent.click(await screen.findByRole('button', { name: 'Remove limit vehicle_limit' }));
  expect(calls.some((c) => c.method === 'DELETE')).toBe(false);
  const dialog = screen.getByRole('alertdialog', { name: 'Remove Add-on Limit' });
  await userEvent.click(within(dialog).getByRole('button', { name: 'Remove' }));
  expect(await screen.findByText('No limit deltas configured.')).toBeTruthy();
  expect(calls.filter((c) => c.method === 'DELETE').map((c) => c.url)).toEqual(['/addons/p1/limits/l1']);
});
it('allows canceling without deleting and hides removal from read-only operators', async () => {
  serve();
  await userEvent.click(await screen.findByRole('button', { name: 'Remove limit vehicle_limit' }));
  await userEvent.click(within(screen.getByRole('alertdialog')).getByRole('button', { name: 'Cancel' }));
  expect(calls.some((c) => c.method === 'DELETE')).toBe(false);
  cleanup(); restore(); serve(false);
  await screen.findByText('Fleet Suite');
  expect(screen.queryByRole('button', { name: /Remove limit/ })).toBeNull();
});
it('keeps the limit and confirmation available when removal fails', async () => {
  serve(true, true);
  await userEvent.click(await screen.findByRole('button', { name: 'Remove limit vehicle_limit' }));
  await userEvent.click(within(screen.getByRole('alertdialog')).getByRole('button', { name: 'Remove' }));
  await waitFor(() => expect(within(screen.getByRole('alertdialog')).getByText(/Cannot remove this limit/)).toBeTruthy());
  expect(screen.getByRole('button', { name: 'Remove limit vehicle_limit' })).toBeTruthy();
});
