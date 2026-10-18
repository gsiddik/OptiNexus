import { useMemo } from 'react';
import { Link, useParams } from 'react-router-dom';
import { onboardingApi } from '../../api/endpoints';
import { useApiResource } from '../../api/useApi';
import type { OnboardingApplication, OnboardingCheck, OnboardingReport, OnboardingStatus } from '../../api/types';
import { useAuth } from '../../auth/useAuth';
import { LoadingSpinner } from '../../components/LoadingSpinner';
import { ErrorState, ForbiddenState } from '../../components/StateViews';
import { Badge, StatusBadge } from '../../components/StatusBadge';
import { formatRelative, translate, useI18n, type MessageKey } from '../../i18n';
import { applicationStatus, byUrgency, checkAction, checkCount, checkMessage, checkTitle, verdict } from './onboardingReport';

export const ONBOARDING_PERMISSION = 'cgo.tenant.view';

const TONE: Record<OnboardingStatus, 'success' | 'warning' | 'danger'> = { OK: 'success', WARN: 'warning', MISSING: 'danger' };
const MARK: Record<OnboardingStatus, string> = { OK: '✓', WARN: '!', MISSING: '✕' };

/** Per-tenant preflight: what is still missing before the tenant's applications can really be used, each with the screen that fixes it. */
export function OnboardingPage() {
  const { tenantId = '' } = useParams<{ tenantId: string }>();
  const { hasPermission } = useAuth();
  const { t } = useI18n();
  if (!hasPermission(ONBOARDING_PERMISSION)) {
    return (
      <div>
        <div className="page-header"><h1>{t('onboarding.title')}</h1></div>
        <ForbiddenState permission={ONBOARDING_PERMISSION} />
      </div>
    );
  }
  return <OnboardingView key={tenantId} tenantId={tenantId} />;
}

function OnboardingView({ tenantId }: { tenantId: string }) {
  const { t } = useI18n();
  const report = useApiResource<OnboardingReport>(() => onboardingApi.report(tenantId), [tenantId]);

  if (report.loading) {
    return (
      <div>
        <div className="page-header"><h1>{t('onboarding.title')}</h1></div>
        <LoadingSpinner />
      </div>
    );
  }
  if (report.errorInfo || !report.data) {
    return (
      <div>
        <div className="page-header"><h1>{t('onboarding.title')}</h1></div>
        <ErrorState error={report.errorInfo} onRetry={report.reload} permission={ONBOARDING_PERMISSION} />
      </div>
    );
  }
  return <Report report={report.data} onRecheck={report.reload} />;
}

function Report({ report, onRecheck }: { report: OnboardingReport; onRecheck: () => void }) {
  const { t } = useI18n();
  const result = useMemo(() => verdict(report), [report]);
  const tenantChecks = useMemo(() => report.checks.filter((c) => !c.application).sort(byUrgency), [report.checks]);
  const tone = result.kind === 'ready' ? 'success' : result.kind === 'notReady' ? 'danger' : 'warning';
  const headline =
    result.kind === 'ready' ? t('onboarding.verdict.ready')
    : result.kind === 'readyWarnings' ? t('onboarding.verdict.readyWarnings', { n: result.counts.warn })
    : result.kind === 'notReady' ? t('onboarding.verdict.notReady', { n: result.counts.missing })
    : t('onboarding.verdict.unknown');

  return (
    <div>
      <div className="page-header">
        <h1>{report.tenant.name}</h1>
        <StatusBadge status={report.tenant.status} />
      </div>
      <p className="lead">{t('onboarding.lead')}</p>
      <p className="muted">{t('onboarding.intro')}</p>
      <div className="toolbar">
        <Link className="btn btn-secondary btn-sm tab-link" to={`/tenants/${report.tenant.id}`}>{t('onboarding.openTenant')}</Link>
        <Link className="btn btn-secondary btn-sm tab-link" to={`/tenants/${report.tenant.id}/gateway`}>{t('onboarding.openGateway')}</Link>
        <button type="button" className="btn btn-primary btn-sm" onClick={onRecheck}>{t('onboarding.recheck')}</button>
      </div>

      <div className={`verdict verdict-${tone}`} role="status" data-testid="onboarding-verdict" data-kind={result.kind}>
        <strong>{headline}</strong>
        <span className="verdict-counts">
          <Badge tone="danger">{`${t('onboarding.count.missing')}: ${result.counts.missing}`}</Badge>
          <Badge tone="warning">{`${t('onboarding.count.warn')}: ${result.counts.warn}`}</Badge>
          <Badge tone="success">{`${t('onboarding.count.ok')}: ${result.counts.ok}`}</Badge>
        </span>
      </div>

      <section aria-labelledby="ob-tenant">
        <h2 id="ob-tenant" className="section-title">{t('onboarding.section.tenant')}</h2>
        <CheckList checks={tenantChecks} />
      </section>

      <section aria-labelledby="ob-apps">
        <h2 id="ob-apps" className="section-title">{t('onboarding.section.applications')}</h2>
        {report.applications.length === 0 ? (
          <div className="card muted" data-testid="no-applications">{t('onboarding.noApplications')}</div>
        ) : (
          report.applications.map((app) => (
            <ApplicationCard key={app.code} app={app} checks={report.checks.filter((c) => c.application === app.code)} />
          ))
        )}
      </section>
    </div>
  );
}

function CheckList({ checks }: { checks: OnboardingCheck[] }) {
  if (checks.length === 0) return null;
  return (
    <ul className="check-list">
      {[...checks].sort(byUrgency).map((check) => <CheckRow key={check.key} check={check} />)}
    </ul>
  );
}

function CheckRow({ check }: { check: OnboardingCheck }) {
  const { t } = useI18n();
  const action = checkAction(check);
  const count = checkCount(check);
  return (
    <li className={`check-row check-${check.status.toLowerCase()}`} data-testid={`check-${check.key}`} data-status={check.status}>
      <span className={`check-mark mark-${check.status.toLowerCase()}`} aria-hidden="true">{MARK[check.status]}</span>
      <div className="check-body">
        <div className="check-title">
          <strong>{checkTitle(check)}</strong>
          {count !== null && <span className="muted"> ({count})</span>}
          <Badge tone={TONE[check.status]}>{t(`onboarding.status.${check.status}` as MessageKey)}</Badge>
        </div>
        <div className="muted">{checkMessage(check)}</div>
      </div>
      {action && <Link className="btn btn-secondary btn-sm tab-link" to={action.to}>{action.label}</Link>}
    </li>
  );
}

function ApplicationCard({ app, checks }: { app: OnboardingApplication; checks: OnboardingCheck[] }) {
  const { t } = useI18n();
  const status = applicationStatus(app, checks);
  const label = status === 'OK' ? t('onboarding.app.ready') : status === 'WARN' ? t('onboarding.app.warn') : t('onboarding.app.notReady');
  const sso = app.oidc_client;
  const lastUsed = app.service_account.last_used_at;
  const manifest = app.manifest;
  const yes = t('common.yes');

  return (
    <article className="card app-card" data-testid={`app-${app.code}`} data-status={status}>
      <header className="app-card-head">
        <h3>{app.name} <code>{app.code}</code></h3>
        <Badge tone={TONE[status]}>{label}</Badge>
      </header>
      <dl className="definition-list app-facts">
        <dt>{t('onboarding.fact.subscribed')}</dt>
        <dd className={app.subscribed ? '' : 'fact-bad'}>{app.subscribed ? yes : t('common.no')}</dd>
        <dt>{t('onboarding.fact.sso')}</dt>
        <dd className={sso ? (sso.pkce_required ? '' : 'fact-warn') : 'fact-bad'}>
          {sso
            ? translate('onboarding.fact.ssoDetail', {
                status: translate(`status.${sso.status}`),
                pkce: t(sso.pkce_required ? 'onboarding.fact.pkceRequired' : 'onboarding.fact.pkceOff'),
                n: sso.redirect_uris,
              })
            : t('onboarding.fact.ssoMissing')}
        </dd>
        <dt>{t('onboarding.fact.serviceAccount')}</dt>
        <dd className={app.service_account.present ? '' : 'fact-bad'}>
          {app.service_account.present
            ? `${t('onboarding.fact.serviceAccountPresent')} (${lastUsed ? t('onboarding.fact.lastUsed', { when: formatRelative(lastUsed) }) : t('onboarding.fact.neverUsed')})`
            : t('onboarding.fact.serviceAccountMissing')}
        </dd>
        <dt>{t('onboarding.fact.manifest')}</dt>
        <dd className={manifest.revision ? '' : 'fact-warn'}>
          {manifest.revision
            ? manifest.synced_at
              ? t('onboarding.fact.manifestRev', { revision: manifest.revision, when: formatRelative(manifest.synced_at) })
              : t('onboarding.fact.manifestNoDate', { revision: manifest.revision })
            : t('onboarding.fact.manifestNone')}
        </dd>
        <dt>{t('onboarding.fact.users')}</dt>
        <dd className={app.users_with_access > 0 ? '' : 'fact-bad'}>{app.users_with_access}</dd>
        <dt>{t('onboarding.fact.admins')}</dt>
        <dd className={app.tenant_admins > 0 ? '' : 'fact-bad'}>{app.tenant_admins}</dd>
      </dl>
      <CheckList checks={checks} />
      {checks.length === 0 && <div className="muted" data-testid={`no-checks-${app.code}`}>{t('onboarding.verdict.unknown')}</div>}
    </article>
  );
}
