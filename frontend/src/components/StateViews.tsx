import type { ReactNode } from 'react';
import { describeError, unwrapError, type ApiRequestError } from '../api/client';
import { useOnlineStatus } from '../errors/useOnlineStatus';
import { useI18n } from '../i18n';

/** The permission-denied view of a whole page (the account lacks the permission, or the server answered 403). */
export function ForbiddenState({ permission }: { permission?: string }) {
  const { t } = useI18n();
  return (
    <div className="state-card card" role="alert" data-testid="forbidden-state">
      <h2>{t('error.forbiddenTitle')}</h2>
      <p className="muted">{permission ? t('error.forbiddenBody', { permission }) : t('error.forbiddenBodyGeneric')}</p>
    </div>
  );
}

/**
 * A page-level failure: forbidden, offline/unreachable, or anything else, with the request reference and a retry.
 * Pass the error object (`errorInfo` of the data hooks) so the right view is chosen.
 */
export function ErrorState({ error, onRetry, permission }: { error: unknown; onRetry?: () => void; permission?: string }) {
  const { t } = useI18n();
  const online = useOnlineStatus();
  const failure: ApiRequestError = unwrapError(error);
  if (failure.kind === 'forbidden' && failure.code !== 'ECOSYSTEM_ACCESS_REQUIRED') return <ForbiddenState permission={permission} />;

  const offline = failure.kind === 'network' || failure.kind === 'timeout' || !online;
  return (
    <div className="state-card card" role="alert" data-testid={offline ? 'offline-state' : 'error-state'}>
      <h2>{offline ? t('error.offlineTitle') : t('error.loadFailedTitle')}</h2>
      <p className="muted">{describeError(failure)}</p>
      {onRetry && (
        <div className="modal-actions state-actions">
          <button type="button" className="btn btn-primary" onClick={onRetry}>{t('common.retry')}</button>
        </div>
      )}
    </div>
  );
}

/** Nothing to show yet, with the next step when the user may take it. */
export function EmptyState({ title, hint, action }: { title?: string; hint?: string; action?: ReactNode }) {
  const { t } = useI18n();
  return (
    <div className="state-card card" data-testid="empty-state">
      <h2>{title ?? t('error.emptyTitle')}</h2>
      {hint && <p className="muted">{hint}</p>}
      {action && <div className="state-actions">{action}</div>}
    </div>
  );
}

/** Shown under the top bar while the browser has no connection. */
export function OfflineBanner() {
  const { t } = useI18n();
  const online = useOnlineStatus();
  if (online) return null;
  return (
    <div className="offline-banner" role="status" data-testid="offline-banner">
      {t('shell.offline')}
    </div>
  );
}
