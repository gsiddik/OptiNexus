// @vitest-environment jsdom
import { cleanup, screen, within } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it } from 'vitest';
import { TOKEN_STORAGE_KEY, unwrapError } from '../../api/client';
import { setLocale } from '../../i18n';
import { installMockApi, ok, operatorContext } from '../../test/mockApi';
import userEvent from '@testing-library/user-event';
import { renderApp } from '../../test/renderApp';

const tenant = { id: 't-1', tenant_code: 'ACME', customer_id: 'c', name: 'Acme Logistics', status: 'ACTIVE', region: null, timezone: null, currency: null, language: null };

const seat = (overrides: Record<string, unknown>) => ({
  application_code: 'optiaccounting', controlled: true, state: 'LIMITED', limit: 5, unlimited: false, used: 3, reserved: 1, available: 1,
  over_limit: false, over_by: 0, can_add: true, blocked_reason: null, limit_source: 'MANUAL_OVERRIDE', ...overrides,
});

let restore: () => void = () => undefined;
let seats: unknown[] = [];

beforeEach(() => {
  window.localStorage.clear();
  window.localStorage.setItem(TOKEN_STORAGE_KEY, 'tok');
  setLocale('en');
  seats = [];
  restore = installMockApi((call) => {
    if (call.url === '/auth/context') return ok(operatorContext(['cgo.tenant.view']));
    if (call.url === '/tenants/t-1') return ok(tenant);
    if (call.url === '/tenants/t-1/applications') return ok([{ id: 'a1', name: 'OptiEntry' }]);
    if (call.url === '/tenants/t-1/seats') return ok(seats);
    if (call.url === '/applications') return ok([], { meta: { pagination: { current_page: 1, per_page: 100, total: 0, last_page: 1 } } });
    return undefined;
  }).restore;
});
afterEach(() => {
  cleanup();
  restore();
});

async function openSeats() {
  renderApp('/tenants/t-1/applications');
  await screen.findByRole('heading', { name: 'Acme Logistics' });
  return screen.findByRole('heading', { name: 'Seats' });
}

describe('seats on the tenant page', () => {
  it('shows limit, in use, reserved and available per application, as OptiNexus counts them', async () => {
    seats = [seat({}), seat({ application_code: 'optifleet', controlled: false, state: 'NOT_CONTROLLED', limit: null, available: null, used: 2, reserved: 0, limit_source: null })];
    await openSeats();

    const rows = screen.getAllByRole('row');
    const entry = within(rows.find((r) => r.textContent?.includes('optiaccounting'))!).getAllByRole('cell').map((c) => c.textContent);
    expect(entry).toEqual(['optiaccounting', 'Limited', '5', '3', '1', '1']);
    const fleet = within(rows.find((r) => r.textContent?.includes('optifleet'))!).getAllByRole('cell').map((c) => c.textContent);
    expect(fleet).toEqual(['optifleet', 'Seats not controlled', '—', '2', '0', '—']);
  });

  it('refreshes seats immediately after assigning an application without a page refresh', async () => {
    restore();
    let assigned = false;
    restore = installMockApi((call) => {
      if (call.url === '/auth/context') return ok(operatorContext(['cgo.tenant.view']));
      if (call.url === '/tenants/t-1') return ok(tenant);
      if (call.url === '/tenants/t-1/applications/a1' && call.method === 'POST') { assigned = true; return ok(null); }
      if (call.url === '/tenants/t-1/applications') return ok(assigned ? [{ id: 'a1', name: 'OptiFleet' }] : []);
      if (call.url === '/tenants/t-1/seats') return ok(assigned ? [seat({ application_code: 'optifleet' })] : []);
      if (call.url === '/applications') return ok([{ id: 'a1', name: 'OptiFleet' }]);
      return undefined;
    }).restore;
    await openSeats();
    expect(screen.queryByText('optifleet')).toBeNull();
    await userEvent.selectOptions((await screen.findByRole('option', { name: 'OptiFleet' })).closest('select')!, 'a1');
    await userEvent.click(screen.getByRole('button', { name: 'Assign' }));
    expect(await screen.findByText('optifleet')).toBeTruthy();
  });

  it('marks over-limit beside the numbers and never shows a negative availability', async () => {
    seats = [seat({ limit: 2, used: 4, reserved: 1, available: 0, over_limit: true, over_by: 3, can_add: false, blocked_reason: 'SEAT_OVER_LIMIT' })];
    await openSeats();

    expect(screen.getByText('Over the limit by 3')).toBeTruthy();
    expect(screen.getByText(/Nobody is removed automatically/)).toBeTruthy();
    expect(screen.queryByText(/-\d/)).toBeNull();
  });

  it('says a missing limit means nobody can be added, never unlimited', async () => {
    seats = [seat({ state: 'ENTITLEMENT_MISSING', limit: null, available: null, can_add: false, blocked_reason: 'SEAT_ENTITLEMENT_MISSING', limit_source: null })];
    await openSeats();

    expect(screen.getAllByText('No limit set').length).toBeGreaterThan(0);
    expect(screen.getByText(/so nobody can be added/)).toBeTruthy();
    expect(screen.queryByText('Unlimited')).toBeNull();
  });

  it('shows an explicit unlimited as such', async () => {
    seats = [seat({ state: 'UNLIMITED', limit: null, unlimited: true, available: null })];
    await openSeats();

    expect(screen.getAllByText('Unlimited').length).toBeGreaterThan(0);
  });

  it('is available in Indonesian', async () => {
    setLocale('id');
    seats = [seat({ limit: 2, used: 4, reserved: 0, available: 0, over_limit: true, over_by: 2, can_add: false, blocked_reason: 'SEAT_OVER_LIMIT' })];
    renderApp('/tenants/t-1/applications');
    expect(await screen.findByRole('heading', { name: 'Kursi' })).toBeTruthy();
    expect(screen.getByText('Melebihi batas sebanyak 2')).toBeTruthy();
    expect(screen.getByText('Terpakai')).toBeTruthy();
  });
});

describe('a seat refusal from the API', () => {
  const refusal = (code: string) => ({ isAxiosError: true, response: { status: 409, data: { success: false, error: { code, message: 'English message from the API', details: { limit: 2, used: 2 } } } } });

  it('is shown in the language of the screen, with the code and the numbers kept', () => {
    const english = unwrapError(refusal('SEAT_LIMIT_REACHED'));
    expect(english.message).toMatch(/No seat is left/);
    expect(english.code).toBe('SEAT_LIMIT_REACHED');
    expect(english.details).toEqual({ limit: 2, used: 2 });

    setLocale('id');
    expect(unwrapError(refusal('SEAT_LIMIT_REACHED')).message).toMatch(/Tidak ada kursi tersisa/);
  });

  it('leaves every other error exactly as the API sent it', () => {
    expect(unwrapError(refusal('INVITATION_PENDING')).message).toBe('English message from the API');
  });
});
