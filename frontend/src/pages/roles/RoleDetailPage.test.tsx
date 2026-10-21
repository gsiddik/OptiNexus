// @vitest-environment jsdom
import { cleanup, fireEvent, screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { afterEach, beforeEach, expect, it } from 'vitest';
import { TOKEN_STORAGE_KEY } from '../../api/client';
import { installMockApi, ok, operatorContext } from '../../test/mockApi';
import { renderApp } from '../../test/renderApp';
import { setLocale } from '../../i18n';

let restore = () => {};
beforeEach(() => { localStorage.clear(); localStorage.setItem(TOKEN_STORAGE_KEY, 'tok'); setLocale('en'); });
afterEach(() => { cleanup(); restore(); });
it('explains each permission using its description or readable name, including on read-only roles', async () => {
  const permissions = [
    { id: 'p1', permission_key: 'optifleet.purchase_order.approve', name: 'Purchase Order: Approve', description: 'Approve a purchase order before it can be issued.', application_name: 'OptiFleet', module_name: 'Procurement' },
    { id: 'p2', permission_key: 'optifleet.product.view', name: 'Product: View', description: '  ', application_name: 'OptiFleet', module_name: 'Inventory' },
  ];
  const mock = installMockApi(({ url }) => {
    if (url === '/auth/context') return ok(operatorContext(['cgo.ecosystem.access', 'cgo.role.view']));
    if (url === '/roles/r1') return ok({ id: 'r1', name: 'Fleet Admin', status: 'ACTIVE', role_type: 'APPLICATION', is_system: true });
    if (url === '/roles/r1/permissions' || url === '/permissions') return ok(permissions);
    return undefined;
  });
  restore = mock.restore;
  renderApp('/roles/r1');
  const checkbox = await screen.findByRole('checkbox', { name: 'optifleet.purchase_order.approve' });
  expect((checkbox as HTMLInputElement).disabled).toBe(true);
  const label = checkbox.closest('label')!;
  await userEvent.hover(within(label).getByRole('button', { name: 'Field information' }));
  const help = screen.getByText('Approve a purchase order before it can be issued.');
  expect(help.getAttribute('role')).toBe('tooltip');
  expect(help.className).toContain('field-tooltip-open');
  expect(checkbox.getAttribute('aria-describedby')).toBe(help.id);
  const other = screen.getByRole('checkbox', { name: 'optifleet.product.view' });
  fireEvent.focus(within(other.closest('label')!).getByRole('button', { name: 'Field information' }));
  expect(screen.getByText('Product: View').className).toContain('field-tooltip-open');
  expect(mock.calls.some((c) => c.method === 'POST' || c.method === 'DELETE')).toBe(false);
});
