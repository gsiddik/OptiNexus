import { matchPath } from 'react-router-dom';
import type { OnboardingApplication, OnboardingCheck, OnboardingReport, OnboardingStatus } from '../../api/types';
import { hasMessage, translate, type MessageKey } from '../../i18n';
import { BREADCRUMB_PATTERNS } from '../../navigation/breadcrumbs';

/**
 * A link from the report is followed only if it is a path of a screen this portal has. The report comes from the API, but a link is
 * a place the operator's browser goes to: nothing that could leave the portal (another host, a scheme, a protocol-relative path) is
 * ever turned into a link.
 */
export function safePortalRoute(route: unknown): string | null {
  if (typeof route !== 'string' || route === '') return null;
  if (!route.startsWith('/') || route.startsWith('//') || route.includes('\\')) return null;
  if ([...route].some((char) => char.charCodeAt(0) <= 0x20 || char.charCodeAt(0) === 0x7f)) return null; // spaces and control characters
  const path = route.split(/[?#]/)[0];
  return BREADCRUMB_PATTERNS.some((pattern) => matchPath({ path: pattern, end: true }, path)) ? route : null;
}

export interface Counts {
  missing: number;
  warn: number;
  ok: number;
}

export function countChecks(checks: OnboardingCheck[]): Counts {
  const counts: Counts = { missing: 0, warn: 0, ok: 0 };
  for (const check of checks) {
    if (check.status === 'MISSING') counts.missing++;
    else if (check.status === 'WARN') counts.warn++;
    else counts.ok++;
  }
  return counts;
}

export type Verdict = { kind: 'ready' | 'readyWarnings' | 'notReady' | 'unknown'; counts: Counts };

/**
 * The headline. "Ready" needs checks to have been returned, none of them missing, and the API not saying otherwise: an empty or
 * contradictory report never reads as success.
 */
export function verdict(report: OnboardingReport): Verdict {
  const counts = countChecks(report.checks);
  if (report.checks.length === 0) return { kind: 'unknown', counts };
  if (counts.missing > 0 || report.ready === false) return { kind: 'notReady', counts: { ...counts, missing: Math.max(counts.missing, 1) } };
  if (counts.warn > 0) return { kind: 'readyWarnings', counts };
  return { kind: 'ready', counts };
}

const RANK: Record<OnboardingStatus, number> = { MISSING: 0, WARN: 1, OK: 2 };
export const byUrgency = (a: OnboardingCheck, b: OnboardingCheck) => RANK[a.status] - RANK[b.status];

/**
 * Worst finding for one application: its own checks, and the facts shown beside them (so a check the API forgot to return can never
 * make a visibly broken application look fine).
 */
export function applicationStatus(app: OnboardingApplication, checks: OnboardingCheck[]): OnboardingStatus {
  const counts = countChecks(checks);
  const factMissing = !app.subscribed || app.oidc_client === null || !app.service_account.present || app.users_with_access === 0 || app.tenant_admins === 0;
  const factWarn = (app.oidc_client !== null && !app.oidc_client.pkce_required) || app.manifest.revision === null;
  if (counts.missing > 0 || factMissing) return 'MISSING';
  if (counts.warn > 0 || factWarn) return 'WARN';
  return 'OK';
}

/** Wording of one finding; an unknown code gets a safe generic sentence that still names the code. */
export function checkMessage(check: OnboardingCheck): string {
  const key = `onboarding.check.${check.message_code}`;
  if (hasMessage(key)) return translate(key);
  const generic: MessageKey = check.status === 'MISSING' ? 'onboarding.check.unknownMissing' : check.status === 'WARN' ? 'onboarding.check.unknownWarn' : 'onboarding.check.unknownOk';
  return translate(generic, { code: check.message_code });
}

/** Short title of a check: "SSO client", "Users with access"... derived from its key; an unknown key is shown as it is. */
export function checkTitle(check: OnboardingCheck): string {
  const suffix = check.application && check.key.startsWith(`${check.application}.`) ? check.key.slice(check.application.length + 1) : check.key.replace('.', '_');
  const key = `onboarding.item.${suffix}`;
  return hasMessage(key) ? translate(key) : check.key;
}

/** The number a check counted (users with access...), when it reports one. */
export function checkCount(check: OnboardingCheck): number | null {
  const count = check.detail?.count;
  return typeof count === 'number' && Number.isFinite(count) ? count : null;
}

export interface CheckAction {
  label: string;
  to: string;
}

/**
 * The action to offer beside a finding: the API's action when its route is a portal screen, with the SSO client link sharpened to
 * "create a client for this application". No action is offered for a route the portal does not have.
 */
export function checkAction(check: OnboardingCheck): CheckAction | null {
  if (check.status === 'OK' || !check.action) return null;
  let to = safePortalRoute(check.action.route);
  if (!to) return null;
  let labelKey: string = check.action.label_key;
  if (check.message_code === 'oidc_client_missing' && check.application && to === '/sso-clients') {
    to = `/sso-clients?new=1&application=${encodeURIComponent(check.application)}`;
    labelKey = 'onboarding.action.create_oidc_client';
  }
  return { to, label: hasMessage(labelKey) ? translate(labelKey) : translate('onboarding.action.open') };
}
