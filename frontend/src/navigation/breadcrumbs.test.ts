import { describe, expect, it } from 'vitest';
import appSource from '../App.tsx?raw';
import { BREADCRUMB_PATTERNS, buildTrail, parentOf, PARENT_ONLY_PATTERNS } from './breadcrumbs';
import { NAV_GROUPS } from './menu';

/** Full paths of the routes declared in App.tsx (nesting follows the opening and closing <Route> tags). */
function appRoutes(source: string): string[] {
  const open: string[] = [];
  const found: string[] = [];
  for (const line of source.split('\n').map((l) => l.trim())) {
    if (line.startsWith('</Route>')) {
      open.pop();
      continue;
    }
    if (!line.startsWith('<Route')) continue;
    const parent = open[open.length - 1] ?? '';
    const path = line.match(/^<Route path="([^"]*)"/)?.[1];
    const full = path === undefined ? parent : `${parent}/${path}`.replace(/\/+/g, '/');
    if (path !== undefined) found.push(full);
    if (!line.endsWith('/>')) open.push(full);
  }
  return [...new Set(found)].filter((p) => p !== '/login' && !p.endsWith('*'));
}

describe('breadcrumb metadata', () => {
  it('covers every page route in App.tsx and nothing else', () => {
    const routes = appRoutes(appSource);
    expect(routes.length).toBeGreaterThan(50); // the parser found the nested routes
    expect(routes.filter((r) => !BREADCRUMB_PATTERNS.includes(r))).toEqual([]);
    expect(BREADCRUMB_PATTERNS.filter((p) => !routes.includes(p) && !PARENT_ONLY_PATTERNS.includes(p))).toEqual([]);
  });

  it('has a page for every menu entry', () => {
    const items = NAV_GROUPS.flatMap((g) => g.items.map((i) => i.to));
    expect(items.filter((to) => !BREADCRUMB_PATTERNS.includes(to))).toEqual([]);
    expect(new Set(items).size).toBe(items.length); // no entry listed twice
  });

  it('gives every page a trail that starts at the dashboard and ends on itself', () => {
    for (const pattern of BREADCRUMB_PATTERNS) {
      const path = pattern.replace(/:[A-Za-z]+/g, 'x-1');
      const trail = buildTrail(path);
      expect(trail.length, path).toBeGreaterThan(0);
      expect(trail[trail.length - 1].to, path).toBeUndefined();
      if (path !== '/') expect(trail[0], path).toEqual({ label: 'Dashboard', to: '/' });
    }
  });
});

describe('buildTrail', () => {
  it('shows only the dashboard as the current page on the home page', () => {
    expect(buildTrail('/')).toEqual([{ label: 'Dashboard' }]);
  });

  it('puts the menu group between the dashboard and the page, as plain text', () => {
    expect(buildTrail('/roles')).toEqual([{ label: 'Dashboard', to: '/' }, { label: 'Access Control' }, { label: 'Roles' }]);
    expect(buildTrail('/pricing/simulate')).toEqual([{ label: 'Dashboard', to: '/' }, { label: 'Catalog & Pricing' }, { label: 'Simulate Price' }]);
  });

  it('reads a details page as the title it showed, and falls back to "Details"', () => {
    expect(buildTrail('/roles/r-1').map((c) => c.label)).toEqual(['Dashboard', 'Access Control', 'Roles', 'Details']);
    expect(buildTrail('/roles/r-1', { '/roles/r-1': 'Billing Admin' })).toEqual([
      { label: 'Dashboard', to: '/' },
      { label: 'Access Control' },
      { label: 'Roles', to: '/roles' },
      { label: 'Billing Admin' },
    ]);
  });

  it('keeps the tenant in the trail of its entitlements page', () => {
    expect(buildTrail('/tenants/t-1/entitlements', { '/tenants/t-1': 'Acme' }).slice(2)).toEqual([
      { label: 'Tenants', to: '/tenants' },
      { label: 'Acme', to: '/tenants/t-1' },
      { label: 'Entitlements' },
    ]);
  });

  it('does not mistake an unknown address for a page, and tolerates a trailing slash', () => {
    expect(buildTrail('/nope/nothing')).toEqual([{ label: 'Dashboard', to: '/' }, { label: 'Page not found' }]);
    expect(buildTrail('/users/').map((c) => c.label)).toEqual(['Dashboard', 'Organisation', 'Users']);
  });
});

describe('parentOf', () => {
  it('is the nearest ancestor that is a page, skipping a group that is only a label', () => {
    expect(parentOf(buildTrail('/roles'))).toBe('/');
    expect(parentOf(buildTrail('/roles/r-1'))).toBe('/roles');
    expect(parentOf(buildTrail('/tenants/t-1/entitlements'))).toBe('/tenants/t-1');
  });

  it('is nothing on the home page', () => {
    expect(parentOf(buildTrail('/'))).toBeNull();
  });
});
