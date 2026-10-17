import { describe, expect, it } from 'vitest';
import type { GatewayLink, GatewayRequestLog, GatewayVehicle } from '../../api/types';
import { classifyAll, classifyLink, countByClass, matchesSearch, normalizeRegistration, onlyTenant, STALE_AFTER_MS, syncHealth } from './gatewayMapping';

const vehicle = (id: string, plate: string): GatewayVehicle => ({
  id, tenant_id: 't-1', external_vehicle_id: `ext-${id}`, registration_number: plate, normalized_registration: normalizeRegistration(plate), vin: null, status: 'ACTIVE',
});
const link = (over: Partial<GatewayLink>): GatewayLink => ({
  id: 'l', telematics_source: 'traccar', device_ref: 'd1', device_name: null, device_registration: null, link_type: 'AUTO', status: 'UNMATCHED',
  fleet_vehicle_id: null, fleet_vehicle: null, ...over,
});

const vehicles = [vehicle('v1', 'B 1234 XYZ'), vehicle('v2', 'D 5555 AB'), vehicle('v3', 'D-5555-AB'), vehicle('v4', 'L 9 ZZ')];

describe('classifyLink', () => {
  it('matched: active with a vehicle', () => {
    const l = link({ status: 'ACTIVE', fleet_vehicle_id: 'v1', fleet_vehicle: { external_vehicle_id: 'ext-v1', registration_number: 'B 1234 XYZ' } });
    expect(classifyLink(l, vehicles).cls).toBe('matched');
  });

  it('unmatched: no vehicle carries the reported plate', () => {
    const row = classifyLink(link({ device_registration: 'F 1 NOPE' }), vehicles);
    expect(row.cls).toBe('unmatched');
    expect(row.candidates).toEqual([]);
    expect(row.suggestion).toBeNull();
  });

  it('unmatched with a suggestion: exactly one vehicle has the plate, whatever the spacing and case', () => {
    const row = classifyLink(link({ device_registration: 'b1234xyz' }), vehicles);
    expect(row.cls).toBe('unmatched');
    expect(row.suggestion?.id).toBe('v1');
  });

  it('ambiguous: two vehicles share the plate, so nothing is guessed', () => {
    const row = classifyLink(link({ device_registration: 'D 5555 AB' }), vehicles);
    expect(row.cls).toBe('ambiguous');
    expect(row.suggestion).toBeNull();
    expect(row.candidates.map((v) => v.id).sort()).toEqual(['v2', 'v3']);
  });

  it('uses the device name like the gateway does when the plate gives nothing unique', () => {
    expect(classifyLink(link({ device_registration: 'D 5555 AB', device_name: 'L 9 ZZ' }), vehicles).cls).toBe('unmatched');
    expect(classifyLink(link({ device_registration: 'D 5555 AB', device_name: 'L 9 ZZ' }), vehicles).suggestion?.id).toBe('v4');
  });

  it('unlinked: taken off by hand', () => {
    expect(classifyLink(link({ status: 'INACTIVE', link_type: 'MANUAL' }), vehicles).cls).toBe('unlinked');
  });

  it('an active link whose vehicle is gone needs attention instead of counting as matched', () => {
    expect(classifyLink(link({ status: 'ACTIVE' }), vehicles).cls).toBe('unmatched');
  });

  it('counts per class', () => {
    const rows = classifyAll([link({ id: 'a', device_registration: 'x' }), link({ id: 'b', device_registration: 'D 5555 AB' }), link({ id: 'c', status: 'INACTIVE' })], vehicles);
    expect(countByClass(rows)).toEqual({ matched: 0, unmatched: 1, ambiguous: 1, unlinked: 1 });
  });
});

describe('tenant isolation of what is shown', () => {
  it('drops rows that name another tenant and keeps rows without a tenant column', () => {
    const rows = [{ id: 'a', tenant_id: 't-1' }, { id: 'b', tenant_id: 't-2' }, { id: 'c', tenant_id: null }, { id: 'd' }];
    expect(onlyTenant(rows, 't-1').map((r) => r.id)).toEqual(['a', 'c', 'd']);
  });
});

describe('search', () => {
  const row = classifyLink(link({ device_name: 'Truck 7', device_ref: 'IMEI-998', device_registration: 'B 1234 XYZ' }), vehicles);
  it('finds a device by name, reference or plate in any spacing', () => {
    expect(matchesSearch(row, 'truck')).toBe(true);
    expect(matchesSearch(row, 'imei-998')).toBe(true);
    expect(matchesSearch(row, 'b1234')).toBe(true);
    expect(matchesSearch(row, 'zzz-none')).toBe(false);
    expect(matchesSearch(row, '  ')).toBe(true);
  });
});

describe('syncHealth', () => {
  const now = Date.parse('2026-10-17T12:00:00Z');
  const log = (minutesAgo: number, status: number, ms = 100): GatewayRequestLog => ({
    id: `${minutesAgo}-${status}`, application_id: null, service_account_id: null, method: 'POST', path: '/gateway/v1/x', status_code: status, duration_ms: ms,
    correlation_id: null, created_at: new Date(now - minutesAgo * 60_000).toISOString(),
  });

  it('none without requests', () => {
    expect(syncHealth([], now).state).toBe('none');
  });
  it('healthy when recent requests succeed', () => {
    const h = syncHealth([log(5, 200), log(10, 200), log(20, 201)], now);
    expect(h).toMatchObject({ state: 'healthy', errors: 0, total: 3, averageMs: 100 });
  });
  it('degraded when the newest request failed', () => {
    expect(syncHealth([log(1, 500), ...Array.from({ length: 20 }, (_, i) => log(10 + i, 200))], now).state).toBe('degraded');
  });
  it('degraded when a fifth of the recent requests failed', () => {
    const logs = [log(1, 200), log(2, 200), log(3, 200), log(4, 200), log(5, 422)];
    expect(syncHealth(logs, now).state).toBe('degraded');
    expect(syncHealth([...logs, log(6, 200), log(7, 200), log(8, 200), log(9, 200), log(10, 200), log(11, 200)], now).state).toBe('healthy');
  });
  it('stale when nothing arrived for more than a day, even if the last one succeeded', () => {
    const h = syncHealth([log(STALE_AFTER_MS / 60_000 + 5, 200)], now);
    expect(h.state).toBe('stale');
    expect(h.lastSuccessAt).not.toBeNull();
  });
  it('reports the last failure and the server errors', () => {
    const h = syncHealth([log(1, 200), log(2, 503), log(3, 404)], now);
    expect(h.serverErrors).toBe(1);
    expect(h.errors).toBe(2);
    expect(h.lastErrorAt).toBe(new Date(now - 2 * 60_000).toISOString());
  });
});
