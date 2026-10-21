// @vitest-environment jsdom
import { cleanup, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { afterEach, beforeEach, describe, expect, it } from 'vitest';
import { TOKEN_STORAGE_KEY } from '../../api/client';
import type { GatewayLink, GatewayRequestLog, GatewayVehicle } from '../../api/types';
import { setLocale } from '../../i18n';
import { fail, installMockApi, ok, operatorContext, type MockCall, type MockHandler } from '../../test/mockApi';
import { renderApp } from '../../test/renderApp';

const TENANT = 't-acme';
const tenant = { id: TENANT, tenant_code: 'ACME', customer_id: 'c', name: 'Acme Logistics', status: 'ACTIVE' };
const v = (id: string, plate: string, tenantId = TENANT): GatewayVehicle => ({
  id, tenant_id: tenantId, application_id: 'app', external_vehicle_id: `ext-${id}`, registration_number: plate, normalized_registration: plate.replace(/[^A-Z0-9]/gi, '').toUpperCase(), vin: null, status: 'ACTIVE',
});
const vehicles = [v('v1', 'B 1234 XYZ'), v('v2', 'D 5555 AB'), v('v3', 'D-5555-AB'), v('v4', 'L 77 QQ'), v('foreign', 'Z 9999 ZZ', 't-other')];
const links: GatewayLink[] = [
  { id: 'l-ok', telematics_source: 'traccar', device_ref: 'IMEI-1', device_name: 'Truck 1', device_registration: 'B 1234 XYZ', link_type: 'AUTO', status: 'ACTIVE', fleet_vehicle_id: 'v1', fleet_vehicle: { external_vehicle_id: 'ext-v1', registration_number: 'B 1234 XYZ' } },
  { id: 'l-un', telematics_source: 'traccar', device_ref: 'IMEI-2', device_name: 'Unknown van', device_registration: 'F 1 NOPE', link_type: 'AUTO', status: 'UNMATCHED', fleet_vehicle_id: null, fleet_vehicle: null },
  { id: 'l-amb', telematics_source: 'gps-co', device_ref: 'IMEI-3', device_name: 'Twin plate', device_registration: 'D 5555 AB', link_type: 'AUTO', status: 'UNMATCHED', fleet_vehicle_id: null, fleet_vehicle: null },
  { id: 'l-off', telematics_source: 'traccar', device_ref: 'IMEI-4', device_name: 'Spare', device_registration: null, link_type: 'MANUAL', status: 'INACTIVE', fleet_vehicle_id: 'v4', fleet_vehicle: { external_vehicle_id: 'ext-v4', registration_number: 'L 77 QQ' } },
];
const iso = (minutesAgo: number) => new Date(Date.now() - minutesAgo * 60_000).toISOString();
const logRow = (i: number, status: number): GatewayRequestLog => ({
  id: `log-${i}`, tenant_id: TENANT, application_id: 'app', service_account_id: 'sa', method: i % 2 ? 'POST' : 'GET', path: `/gateway/v1/telematics/odometer-readings?i=${i}`.split('?')[0],
  status_code: status, duration_ms: 40 + i, correlation_id: `corr-${i}`, created_at: iso(i * 3),
});

const LINK_VIEW = 'cgo.gateway.link.view';
const LINK_MANAGE = 'cgo.gateway.link.manage';
const LOG_VIEW = 'cgo.gateway.log.view';

let restore: () => void = () => undefined;
let calls: MockCall[] = [];

function serve(permissions: string[], extra?: MockHandler, data: { links?: GatewayLink[]; logs?: GatewayRequestLog[] } = {}) {
  const mock = installMockApi((call) => {
    const custom = extra?.(call);
    if (custom) return custom;
    if (call.url === '/auth/context') return ok(operatorContext(permissions));
    if (call.url === `/tenants/${TENANT}`) return ok(tenant);
    if (call.url === `/gateway/tenants/${TENANT}/vehicle-links` && call.method === 'GET') return ok(data.links ?? links);
    if (call.url === `/gateway/tenants/${TENANT}/fleet-vehicles`) return ok(vehicles);
    if (call.url === `/gateway/tenants/${TENANT}/request-logs`) {
      const rows = data.logs ?? [logRow(1, 200), logRow(2, 200), logRow(3, 500), logRow(4, 200)];
      return ok(rows, { meta: { pagination: { current_page: 1, per_page: 50, total: rows.length, last_page: 1 } } });
    }
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

describe('vehicle mapping page', () => {
  it('classifies every device as matched, unmatched, ambiguous or unlinked and counts them', async () => {
    serve([LINK_VIEW, LOG_VIEW]);
    renderApp(`/tenants/${TENANT}/gateway`);
    expect(await screen.findByRole('heading', { name: 'Acme Logistics' })).toBeTruthy();
    await screen.findByText('Truck 1');
    for (const cls of ['matched', 'unmatched', 'ambiguous', 'unlinked']) {
      expect(within(screen.getByTestId(`count-${cls}`)).getByText('1')).toBeTruthy();
    }
    const ambiguous = screen.getByText('Twin plate').closest('tr')!;
    expect(within(ambiguous).getByText('Ambiguous')).toBeTruthy();
    expect(within(ambiguous).getByText('2 vehicles share this plate')).toBeTruthy();
    expect(within(screen.getByText('Unknown van').closest('tr')!).getByText('Unmatched')).toBeTruthy();
    expect(screen.getByRole('status').textContent).toContain('2 device(s) are waiting for a vehicle');
  });

  it('only talks to the gateway endpoints of the tenant in the address', async () => {
    serve([LINK_VIEW, LOG_VIEW]);
    renderApp(`/tenants/${TENANT}/gateway`);
    await screen.findByText('Truck 1');
    const gatewayCalls = calls.filter((c) => c.url.startsWith('/gateway/'));
    expect(gatewayCalls.length).toBeGreaterThanOrEqual(3);
    expect(gatewayCalls.every((c) => c.url.startsWith(`/gateway/tenants/${TENANT}/`))).toBe(true);
    expect(gatewayCalls.some((c) => c.url.includes('t-other'))).toBe(false);
  });

  it('never offers or counts a vehicle that belongs to another tenant, even if the API sent it', async () => {
    serve([LINK_VIEW, LINK_MANAGE]);
    renderApp(`/tenants/${TENANT}/gateway`);
    const user = userEvent.setup();
    const row = (await screen.findByText('Unknown van')).closest('tr')!;
    await user.click(within(row).getByRole('button', { name: 'Link to vehicle' }));
    const dialog = await screen.findByRole('dialog');
    expect(within(dialog).queryByText(/Z 9999 ZZ/)).toBeNull();
    expect(within(dialog).getAllByRole('option').length).toBe(1 + 4); // placeholder + this tenant's four vehicles
    expect(screen.getByText('Fleet vehicles').previousElementSibling?.textContent).toBe('4');
  });

  it('links a device by hand: the picker puts the vehicles with the reported plate first, and the PUT carries only the vehicle id', async () => {
    serve([LINK_VIEW, LINK_MANAGE], (c) => (c.method === 'PUT' ? ok({ ...links[2], status: 'ACTIVE' }) : undefined));
    renderApp(`/tenants/${TENANT}/gateway`);
    const user = userEvent.setup();
    const row = (await screen.findByText('Twin plate')).closest('tr')!;
    await user.click(within(row).getByRole('button', { name: 'Link to vehicle' }));
    const dialog = await screen.findByRole('dialog', { name: /Link Twin plate to a vehicle/ });
    const groups = within(dialog).getAllByRole('group');
    expect(groups[0].getAttribute('label')).toBe('Vehicles with the reported plate');
    expect(within(groups[0]).getAllByRole('option').map((o) => o.textContent)).toEqual(['D 5555 AB (ext-v2)', 'D-5555-AB (ext-v3)']);

    await user.click(within(dialog).getByRole('button', { name: 'Link' }));
    expect(within(dialog).getByText('Choose a vehicle.')).toBeTruthy();
    expect(calls.some((c) => c.method === 'PUT')).toBe(false);

    await user.selectOptions(within(dialog).getByLabelText('Vehicle'), 'v3');
    await user.click(within(dialog).getByRole('button', { name: 'Link' }));
    await waitFor(() => expect(calls.some((c) => c.method === 'PUT')).toBe(true));
    const put = calls.find((c) => c.method === 'PUT') as MockCall;
    expect(put.url).toBe(`/gateway/tenants/${TENANT}/vehicle-links/l-amb`);
    expect(put.body).toEqual({ fleet_vehicle_id: 'v3' });
    await waitFor(() => expect(screen.queryByRole('dialog')).toBeNull());
  });

  it('unlinks after a confirmation', async () => {
    serve([LINK_VIEW, LINK_MANAGE], (c) => (c.method === 'DELETE' ? ok({ ...links[0], status: 'INACTIVE' }) : undefined));
    renderApp(`/tenants/${TENANT}/gateway`);
    const user = userEvent.setup();
    const row = (await screen.findByText('Truck 1')).closest('tr')!;
    await user.click(within(row).getByRole('button', { name: 'Unlink' }));
    const confirm = await screen.findByRole('alertdialog');
    expect(calls.some((c) => c.method === 'DELETE')).toBe(false);
    await user.click(within(confirm).getByRole('button', { name: 'Unlink' }));
    await waitFor(() => expect(calls.some((c) => c.method === 'DELETE' && c.url === `/gateway/tenants/${TENANT}/vehicle-links/l-ok`)).toBe(true));
  });

  it('a server refusal while linking is shown in the form with its reference, and the form stays open', async () => {
    serve([LINK_VIEW, LINK_MANAGE], (c) => (c.method === 'PUT' ? fail(404, 'NOT_FOUND', 'No query results for model.') : undefined));
    renderApp(`/tenants/${TENANT}/gateway`);
    const user = userEvent.setup();
    const row = (await screen.findByText('Unknown van')).closest('tr')!;
    await user.click(within(row).getByRole('button', { name: 'Link to vehicle' }));
    const dialog = await screen.findByRole('dialog');
    await user.selectOptions(within(dialog).getByLabelText('Vehicle'), 'v1');
    await user.click(within(dialog).getByRole('button', { name: 'Link' }));
    expect(await within(dialog).findByText(/No query results for model\./)).toBeTruthy();
    expect(screen.getByRole('dialog')).toBeTruthy();
  });

  it('filters by mapping state, source and text', async () => {
    serve([LINK_VIEW]);
    renderApp(`/tenants/${TENANT}/gateway`);
    await screen.findByText('Truck 1');
    const user = userEvent.setup();
    await user.selectOptions(screen.getByLabelText('Mapping state'), 'ambiguous');
    expect(screen.getByText('Twin plate')).toBeTruthy();
    expect(screen.queryByText('Truck 1')).toBeNull();
    await user.selectOptions(screen.getByLabelText('Mapping state'), '');
    await user.selectOptions(screen.getByLabelText('Telematics source'), 'gps-co');
    expect(screen.queryByText('Unknown van')).toBeNull();
    await user.selectOptions(screen.getByLabelText('Telematics source'), '');
    await user.type(screen.getByLabelText('Search device, plate or vehicle'), 'b1234');
    expect(screen.getByText('Truck 1')).toBeTruthy();
    expect(screen.queryByText('Twin plate')).toBeNull();
    await user.clear(screen.getByLabelText('Search device, plate or vehicle'));
    await user.type(screen.getByLabelText('Search device, plate or vehicle'), 'nothing-like-this');
    expect(screen.getByText('No device matches the filter.')).toBeTruthy();
  });

  it('with view only there is no way to link or unlink', async () => {
    serve([LINK_VIEW]);
    renderApp(`/tenants/${TENANT}/gateway`);
    await screen.findByText('Truck 1');
    expect(screen.queryByRole('button', { name: /Link to vehicle|Unlink|Change vehicle/ })).toBeNull();
  });

  it('is forbidden without any gateway permission and asks the API for nothing', async () => {
    serve(['cgo.ecosystem.access']);
    renderApp(`/tenants/${TENANT}/gateway`);
    expect(await screen.findByTestId('forbidden-state')).toBeTruthy();
    expect(calls.some((c) => c.url.startsWith('/gateway/'))).toBe(false);
  });

  it('without the log permission the logs tab is absent and the health card says why', async () => {
    serve([LINK_VIEW]);
    renderApp(`/tenants/${TENANT}/gateway`);
    await screen.findByText('Truck 1');
    expect(screen.queryByRole('tab', { name: 'Requests and sync health' })).toBeNull();
    expect(within(screen.getByTestId('sync-health')).getByText('No permission to see request logs')).toBeTruthy();
    expect(calls.some((c) => c.url.endsWith('/request-logs'))).toBe(false);
  });

  it('shows the empty state when no device has reported', async () => {
    serve([LINK_VIEW], undefined, { links: [] });
    renderApp(`/tenants/${TENANT}/gateway`);
    expect(await screen.findByText('No devices have reported yet')).toBeTruthy();
  });

  it('shows the error with a retry when the links cannot be loaded', async () => {
    let up = false;
    serve([LINK_VIEW], (c) => (c.url.endsWith('/vehicle-links') && !up ? { status: 0, network: true } : undefined));
    renderApp(`/tenants/${TENANT}/gateway`);
    expect(await screen.findByTestId('offline-state')).toBeTruthy();
    up = true;
    await userEvent.setup().click(screen.getByRole('button', { name: 'Try again' }));
    expect(await screen.findByText('Truck 1')).toBeTruthy();
  });
});

describe('request logs and sync health', () => {
  it('shows health, the failed-request count and the log table; "failed only" filters the table', async () => {
    serve([LINK_VIEW, LOG_VIEW]);
    renderApp(`/tenants/${TENANT}/gateway`);
    await screen.findByText('Truck 1');
    const health = await screen.findByTestId('sync-health');
    await waitFor(() => expect(within(health).getByText('Degraded')).toBeTruthy()); // 1 of 4 failed = 25 %
    expect(within(health).getByText(/1 of 4 recent/)).toBeTruthy();

    const user = userEvent.setup();
    await user.click(screen.getByRole('tab', { name: 'Requests and sync health' }));
    const table = await screen.findByRole('table');
    expect(within(table).getAllByRole('row').length).toBe(1 + 4);
    expect(within(table).getByText('corr-3')).toBeTruthy();
    await user.click(screen.getByRole('checkbox', { name: 'Failed requests only' }));
    expect(within(table).getAllByRole('row').length).toBe(1 + 1);
    expect(within(table).getByText('500')).toBeTruthy();
  });

  it('is healthy when everything succeeds, and reads "No activity" with no logs', async () => {
    serve([LINK_VIEW, LOG_VIEW], undefined, { logs: [logRow(1, 200), logRow(2, 200)] });
    const view = renderApp(`/tenants/${TENANT}/gateway`);
    await waitFor(() => expect(within(screen.getByTestId('sync-health')).getByText('Healthy')).toBeTruthy());
    view.unmount();
    restore();
    serve([LINK_VIEW, LOG_VIEW], undefined, { logs: [] });
    renderApp(`/tenants/${TENANT}/gateway`);
    await waitFor(() => expect(within(screen.getByTestId('sync-health')).getByText('No activity')).toBeTruthy());
  });

  it('is in Indonesian when the screen is', async () => {
    setLocale('id');
    serve([LINK_VIEW, LOG_VIEW]);
    renderApp(`/tenants/${TENANT}/gateway`);
    expect(await screen.findByText('Tautan perangkat')).toBeTruthy();
    expect(await screen.findByText('Ambigu', { selector: '.badge' })).toBeTruthy();
  });
});
