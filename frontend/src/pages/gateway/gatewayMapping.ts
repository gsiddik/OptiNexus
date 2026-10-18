import type { GatewayLink, GatewayRequestLog, GatewayVehicle } from '../../api/types';

/**
 * How a device link reads to an operator. The API only knows ACTIVE / UNMATCHED / INACTIVE; "ambiguous" is derived here from the
 * tenant's vehicle directory with the same rule the gateway uses to auto-link (plate first, then device name, normalised to upper-case
 * letters and digits): a plate that two vehicles share is never guessed, so it stays unmatched and needs a person.
 */
export type LinkClass = 'matched' | 'unmatched' | 'ambiguous' | 'unlinked';

export interface Classified {
  link: GatewayLink;
  cls: LinkClass;
  /** Vehicles that carry the reported plate (more than one when ambiguous). */
  candidates: GatewayVehicle[];
  /** The one vehicle automatic matching would pick but has not yet (an unmatched link whose plate now has exactly one vehicle). */
  suggestion: GatewayVehicle | null;
}

export function normalizeRegistration(value: string | null | undefined): string {
  return (value ?? '').replace(/[^A-Za-z0-9]/g, '').toUpperCase();
}

/** Drops anything that is not demonstrably this tenant's: the URL scopes the request, this is the second lock. */
export function onlyTenant<T extends { tenant_id?: string | null }>(rows: T[], tenantId: string): T[] {
  return rows.filter((row) => row.tenant_id === undefined || row.tenant_id === null || row.tenant_id === tenantId);
}

export function classifyLink(link: GatewayLink, vehicles: GatewayVehicle[]): Classified {
  if (link.status === 'ACTIVE' && link.fleet_vehicle) return { link, cls: 'matched', candidates: [], suggestion: null };
  if (link.status === 'INACTIVE') return { link, cls: 'unlinked', candidates: [], suggestion: null };

  let suggestion: GatewayVehicle | null = null;
  const shared = new Map<string, GatewayVehicle>();
  for (const reported of [link.device_registration, link.device_name]) {
    const plate = normalizeRegistration(reported);
    if (!plate) continue;
    const matches = vehicles.filter((v) => (v.normalized_registration || normalizeRegistration(v.registration_number)) === plate);
    if (matches.length === 1 && !suggestion) suggestion = matches[0];
    if (matches.length > 1) matches.forEach((v) => shared.set(v.id, v));
  }
  if (suggestion) return { link, cls: 'unmatched', candidates: [suggestion], suggestion };
  if (shared.size > 1) return { link, cls: 'ambiguous', candidates: [...shared.values()], suggestion: null };
  return { link, cls: 'unmatched', candidates: [], suggestion: null };
}

export function classifyAll(links: GatewayLink[], vehicles: GatewayVehicle[]): Classified[] {
  return links.map((link) => classifyLink(link, vehicles));
}

export function countByClass(rows: Classified[]): Record<LinkClass, number> {
  const counts: Record<LinkClass, number> = { matched: 0, unmatched: 0, ambiguous: 0, unlinked: 0 };
  rows.forEach((row) => counts[row.cls]++);
  return counts;
}

export function matchesSearch(row: Classified, needle: string): boolean {
  const text = needle.trim().toLowerCase();
  if (!text) return true;
  const plate = normalizeRegistration(text);
  const haystack = [row.link.device_name, row.link.device_ref, row.link.device_registration, row.link.fleet_vehicle?.registration_number, row.link.fleet_vehicle?.external_vehicle_id]
    .filter((v): v is string => !!v)
    .map((v) => v.toLowerCase());
  return haystack.some((v) => v.includes(text) || (plate !== '' && normalizeRegistration(v).includes(plate)));
}

export type SyncState = 'none' | 'healthy' | 'degraded' | 'stale';

export interface SyncHealth {
  state: SyncState;
  total: number;
  errors: number;
  serverErrors: number;
  lastAt: string | null;
  lastSuccessAt: string | null;
  lastErrorAt: string | null;
  averageMs: number | null;
}

export const STALE_AFTER_MS = 24 * 3600 * 1000;
/** Share of failed recent requests from which the sync counts as degraded. */
export const DEGRADED_RATIO = 0.2;

/**
 * Health of the tenant's gateway traffic, computed from its most recent request logs (newest first):
 * none = nothing recorded; stale = newest request older than 24 h; degraded = the newest request failed or at least 20 % of the
 * recent ones did; healthy otherwise. A failure is any answer of 400 or above.
 */
export function syncHealth(logs: GatewayRequestLog[], now: number = Date.now()): SyncHealth {
  if (logs.length === 0) return { state: 'none', total: 0, errors: 0, serverErrors: 0, lastAt: null, lastSuccessAt: null, lastErrorAt: null, averageMs: null };
  const sorted = [...logs].sort((a, b) => Date.parse(b.created_at) - Date.parse(a.created_at));
  const failed = (log: GatewayRequestLog) => log.status_code >= 400;
  const errors = sorted.filter(failed).length;
  const durations = sorted.map((l) => l.duration_ms).filter((d): d is number => typeof d === 'number');
  const newest = sorted[0];
  let state: SyncState = 'healthy';
  if (now - Date.parse(newest.created_at) > STALE_AFTER_MS) state = 'stale';
  else if (failed(newest) || errors / sorted.length >= DEGRADED_RATIO) state = 'degraded';
  return {
    state,
    total: sorted.length,
    errors,
    serverErrors: sorted.filter((l) => l.status_code >= 500).length,
    lastAt: newest.created_at,
    lastSuccessAt: sorted.find((l) => !failed(l))?.created_at ?? null,
    lastErrorAt: sorted.find(failed)?.created_at ?? null,
    averageMs: durations.length ? Math.round(durations.reduce((a, b) => a + b, 0) / durations.length) : null,
  };
}
