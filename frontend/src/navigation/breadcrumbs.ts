import { generatePath, matchPath } from 'react-router-dom';
import type { MessageKey } from '../i18n';
import { NAV_GROUPS } from './menu';

/** One step of the breadcrumb trail. Without `to` it is plain text (the current page, or a menu group that has no page of its own). */
export type Crumb = { label: string; labelKey?: MessageKey; to?: string };

type Meta = { label: string; labelKey?: MessageKey; group?: string; groupKey?: MessageKey; parent?: string };

const HOME = '/';
const HOME_LABEL = 'Dashboard';

const META: Record<string, Meta> = {};

// List pages come straight from the sidebar menu, so a menu entry always has a breadcrumb.
for (const group of NAV_GROUPS) {
  for (const item of group.items) META[item.to] = { label: item.label, labelKey: item.labelKey, group: group.label ?? undefined, groupKey: group.labelKey };
}

/** Pages that open one record of a list (`/:id`). */
const DETAIL_OF = [
  '/customers', '/tenants', '/applications', '/roles', '/users', '/products', '/plans', '/addons', '/prices', '/subscriptions', '/billings', '/invoices',
  '/policies', '/workflows', '/workflow-instances', '/approval-definitions', '/approval-requests', '/integrations', '/event-catalog', '/feature-flags',
  '/notification-templates', '/notification-rules', '/notifications',
];
for (const list of DETAIL_OF) META[`${list}/:id`] = { label: 'Details', labelKey: 'nav.details', parent: list };

// The entitlements page names its tenant parameter differently from the tenant detail page; this alias is a parent only.
META['/tenants/:tenantId'] = { label: 'Details', labelKey: 'nav.details', parent: '/tenants' };
META['/tenants/:tenantId/entitlements'] = { label: 'Entitlements', labelKey: 'nav.entitlements', parent: '/tenants/:tenantId' };
// Per-tenant screens, reached from the tenant page or from the menu's tenant picker.
META['/tenants/:tenantId/gateway'] = { label: 'Vehicle Mapping', labelKey: 'nav.gateway', parent: '/tenants/:tenantId' };

/** Route patterns that have breadcrumb metadata but are not routes themselves. */
export const PARENT_ONLY_PATTERNS = ['/tenants/:tenantId'];

/** Every pattern that has breadcrumb metadata (a test keeps this in step with the routes in App.tsx). */
export const BREADCRUMB_PATTERNS = Object.keys(META);

const isDynamic = (pattern: string) => /\/:[^/]+$/.test(pattern);

/** The most specific route pattern that matches: fixed paths win over `/:id`. */
function resolvePattern(pathname: string): { pattern: string; params: Record<string, string> } | null {
  const trimmed = pathname.length > 1 ? pathname.replace(/\/+$/, '') : pathname;
  const ordered = [...BREADCRUMB_PATTERNS].sort((a, b) => Number(a.includes(':')) - Number(b.includes(':')));
  for (const pattern of ordered) {
    const match = matchPath({ path: pattern, end: true }, trimmed);
    if (match) return { pattern, params: Object.fromEntries(Object.entries(match.params).map(([k, v]) => [k, v ?? ''])) };
  }
  return null;
}

/**
 * Breadcrumb trail of a page: Dashboard, the menu group, the ancestors, then the page itself (no link).
 * `headings` holds the page title each address showed, so a details page reads as the record's name instead of "Details".
 */
export function buildTrail(pathname: string, headings: Record<string, string> = {}): Crumb[] {
  const resolved = resolvePattern(pathname);
  if (!resolved) return [{ label: HOME_LABEL, labelKey: 'nav.dashboard', to: HOME }, { label: 'Page not found', labelKey: 'nav.notFound' }];

  const chain: string[] = [];
  for (let pattern: string | undefined = resolved.pattern; pattern; pattern = META[pattern].parent) chain.unshift(pattern);

  const trail: Crumb[] = [];
  if (chain[0] !== HOME) trail.push({ label: HOME_LABEL, labelKey: 'nav.dashboard', to: HOME });
  const first = META[chain[0]];
  if (first.group) trail.push({ label: first.group, labelKey: first.groupKey });
  for (const pattern of chain) {
    const to = generatePath(pattern, resolved.params);
    const heading = isDynamic(pattern) ? headings[to] : undefined;
    // A record's own title (its page heading) is data, not a translatable label.
    trail.push(heading ? { label: heading, to } : { label: META[pattern].label, labelKey: META[pattern].labelKey, to });
  }
  const last = trail[trail.length - 1];
  trail[trail.length - 1] = { label: last.label, labelKey: last.labelKey };
  return trail;
}

/** Where "Back" leads when this session has no earlier page: the nearest ancestor that is a page. */
export function parentOf(trail: Crumb[]): string | null {
  for (let i = trail.length - 2; i >= 0; i--) if (trail[i].to) return trail[i].to ?? null;
  return null;
}
