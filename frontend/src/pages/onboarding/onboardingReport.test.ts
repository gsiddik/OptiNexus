import { describe, expect, it } from 'vitest';
import type { OnboardingApplication, OnboardingCheck, OnboardingReport } from '../../api/types';
import { setLocale } from '../../i18n';
import { applicationStatus, checkAction, checkMessage, checkTitle, countChecks, safePortalRoute, verdict } from './onboardingReport';

const check = (over: Partial<OnboardingCheck>): OnboardingCheck => ({ key: 'tenant.usable', application: null, status: 'OK', message_code: 'ok', detail: null, action: null, ...over });
const report = (checks: OnboardingCheck[], over: Partial<OnboardingReport> = {}): OnboardingReport => ({
  tenant: { id: 't-1', code: 'ACME', name: 'Acme', status: 'ACTIVE' }, ready: true, checks, applications: [], ...over,
});
const app = (over: Partial<OnboardingApplication> = {}): OnboardingApplication => ({
  code: 'optifleet', name: 'OptiFleet', subscribed: true, oidc_client: { status: 'ACTIVE', pkce_required: true, redirect_uris: 2 },
  service_account: { present: true, last_used_at: null }, manifest: { revision: 'r1', synced_at: null }, users_with_access: 3, tenant_admins: 1, ...over,
});

describe('safePortalRoute', () => {
  it.each([
    ['/tenants/abc', '/tenants/abc'],
    ['/tenants/abc/admins', '/tenants/abc/admins'],
    ['/tenants/abc/applications', '/tenants/abc/applications'],
    ['/sso-clients', '/sso-clients'],
    ['/service-accounts', '/service-accounts'],
    ['/applications/app-1', '/applications/app-1'],
    ['/sso-clients?new=1&application=x', '/sso-clients?new=1&application=x'],
  ])('follows %s', (route, expected) => expect(safePortalRoute(route)).toBe(expected));

  it.each(['https://evil.example/phish', '//evil.example', 'javascript:alert(1)', '/\\evil.example', '/tenants/abc\n/x', '', '/not-a-screen', '/tenants/a/b/c/d', 'tenants/abc', undefined, 42])(
    'refuses %s',
    (route) => expect(safePortalRoute(route)).toBeNull(),
  );
});

describe('verdict: never a false success', () => {
  it('ready only with checks, none missing and the API agreeing', () => {
    expect(verdict(report([check({})])).kind).toBe('ready');
  });
  it('an empty report cannot be confirmed', () => {
    expect(verdict(report([])).kind).toBe('unknown');
  });
  it('a missing check makes it not ready even if the API said ready', () => {
    const v = verdict(report([check({ status: 'MISSING', message_code: 'no_tenant_admin' })], { ready: true }));
    expect(v.kind).toBe('notReady');
    expect(v.counts.missing).toBe(1);
  });
  it('the API saying not ready is believed even when no check explains it', () => {
    expect(verdict(report([check({})], { ready: false })).kind).toBe('notReady');
  });
  it('warnings alone are ready-with-review', () => {
    expect(verdict(report([check({}), check({ key: 'a.b', status: 'WARN', message_code: 'launch_url_missing' })])).kind).toBe('readyWarnings');
  });
  it('counts', () => {
    expect(countChecks([check({}), check({ status: 'WARN' }), check({ status: 'MISSING' }), check({ status: 'MISSING' })])).toEqual({ missing: 2, warn: 1, ok: 1 });
  });
});

describe('application status also reads the facts', () => {
  it('ok when everything is in place', () => {
    expect(applicationStatus(app(), [])).toBe('OK');
  });
  it('a visibly broken application is never ok, even if no check was returned for it', () => {
    expect(applicationStatus(app({ oidc_client: null }), [])).toBe('MISSING');
    expect(applicationStatus(app({ service_account: { present: false, last_used_at: null } }), [])).toBe('MISSING');
    expect(applicationStatus(app({ users_with_access: 0 }), [])).toBe('MISSING');
    expect(applicationStatus(app({ tenant_admins: 0 }), [])).toBe('MISSING');
    expect(applicationStatus(app({ oidc_client: { status: 'ACTIVE', pkce_required: false, redirect_uris: 1 } }), [])).toBe('WARN');
    expect(applicationStatus(app({ manifest: { revision: null, synced_at: null } }), [])).toBe('WARN');
  });
  it('takes the worst check', () => {
    expect(applicationStatus(app(), [check({ status: 'WARN' }), check({ status: 'MISSING' })])).toBe('MISSING');
  });
});

describe('wording', () => {
  it('has English and Indonesian text for every message code the API uses', () => {
    const codes = ['ok', 'tenant_not_active', 'no_tenant_admin', 'no_application_assigned', 'oidc_client_missing', 'oidc_pkce_not_required', 'launch_url_missing', 'service_account_missing', 'manifest_not_imported', 'nothing_delegable', 'no_user_has_access', 'no_tenant_admin_has_access'];
    for (const locale of ['en', 'id'] as const) {
      setLocale(locale);
      for (const code of codes) expect(checkMessage(check({ message_code: code, status: 'MISSING' })), `${locale}/${code}`).not.toMatch(/^onboarding\./);
      expect(checkMessage(check({ message_code: 'oidc_client_missing', status: 'MISSING' }))).not.toContain('(');
    }
    setLocale('en');
  });

  it('an unknown code gets a safe generic sentence that names the code', () => {
    expect(checkMessage(check({ message_code: 'brand_new_code', status: 'MISSING' }))).toBe('A required item is missing (brand_new_code).');
    expect(checkMessage(check({ message_code: 'brand_new_code', status: 'WARN' }))).toBe('This item needs review (brand_new_code).');
  });

  it('titles come from the key, with the application prefix removed; an unknown key is shown as it is', () => {
    expect(checkTitle(check({ key: 'optifleet.oidc_client', application: 'optifleet' }))).toBe('SSO client');
    expect(checkTitle(check({ key: 'tenant.admin' }))).toBe('Tenant administrator');
    expect(checkTitle(check({ key: 'optifleet.something_new', application: 'optifleet' }))).toBe('optifleet.something_new');
  });
});

describe('actions', () => {
  it('offers the API action for a screen the portal has, with its translated label', () => {
    const action = checkAction(check({ status: 'MISSING', message_code: 'no_tenant_admin', action: { label_key: 'onboarding.action.assign_admin', route: '/tenants/t-1/admins' } }));
    expect(action).toEqual({ label: 'Assign an administrator', to: '/tenants/t-1/admins' });
  });
  it('sharpens a missing SSO client into "create it for this application"', () => {
    const action = checkAction(check({ key: 'optifleet.oidc_client', application: 'optifleet', status: 'MISSING', message_code: 'oidc_client_missing', action: { label_key: 'onboarding.action.open_oidc_clients', route: '/sso-clients' } }));
    expect(action).toEqual({ label: 'Create the SSO client', to: '/sso-clients?new=1&application=optifleet' });
  });
  it('falls back to "Open" for an unknown label key, and offers nothing for an unsafe or unknown route', () => {
    expect(checkAction(check({ status: 'WARN', action: { label_key: 'onboarding.action.new_thing', route: '/service-accounts' } }))?.label).toBe('Open');
    expect(checkAction(check({ status: 'WARN', action: { label_key: 'onboarding.action.open_tenant', route: 'https://evil.example' } }))).toBeNull();
    expect(checkAction(check({ status: 'WARN', action: { label_key: 'onboarding.action.open_tenant', route: '/no-such-screen' } }))).toBeNull();
  });
  it('offers none for a finished check', () => {
    expect(checkAction(check({ status: 'OK', action: { label_key: 'onboarding.action.open_tenant', route: '/tenants/t-1' } }))).toBeNull();
  });
});
