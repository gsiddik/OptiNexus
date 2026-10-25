import { HelpInput, HelpSelect } from '../../../components/FieldControls';
import { useState } from 'react';
import { useSearchParams } from 'react-router-dom';
import { emailSuppressionsApi } from '../../../api/endpoints';
import { useApiList } from '../../../api/useApi';
import { DataTable } from '../../../components/DataTable';
import { Pagination } from '../../../components/Pagination';
import { StatusBadge } from '../../../components/StatusBadge';
import { LoadingSpinner } from '../../../components/LoadingSpinner';
import { ErrorAlert } from '../../../components/ErrorAlert';
import { ConfirmDialog } from '../../../components/ConfirmDialog';
import { PermissionGuard } from '../../../auth/PermissionGuard';
import { unwrapError } from '../../../api/client';
import { formatDateTime, useI18n, type MessageKey } from '../../../i18n';
import type { EmailSuppression } from '../../../api/types';

const STATES = ['active', 'released', 'all'] as const;

/** Addresses the mail provider stopped delivering to; the backend cancels operational e-mail to an active entry. */
export function EmailSuppressionsListPage() {
  const { t } = useI18n();
  const [params, setParams] = useSearchParams();
  const state = params.get('state') ?? 'active';
  const search = params.get('search') ?? '';
  const [pending, setPending] = useState<EmailSuppression | null>(null);
  const [busy, setBusy] = useState(false);
  const [actionError, setActionError] = useState<string | null>(null);

  const { rows, meta, setPage, loading, error, reload } = useApiList<EmailSuppression>(
    (p) => emailSuppressionsApi.list({ page: p, state: state === 'all' ? undefined : state, search: search || undefined }),
    [state, search],
  );

  function setParam(key: string, value: string) {
    setParams((p) => { if (value) p.set(key, value); else p.delete(key); return p; });
  }

  async function release() {
    if (!pending) return;
    setBusy(true);
    setActionError(null);
    try {
      await emailSuppressionsApi.release(pending.id);
      setPending(null);
      reload();
    } catch (err) {
      setActionError(unwrapError(err).message);
      setPending(null);
    } finally {
      setBusy(false);
    }
  }

  const reasonLabel = (reason: string) => {
    const key = `suppressions.reason.${reason}` as MessageKey;
    const label = t(key);
    return label === key ? reason : label;
  };

  return (
    <div>
      <div className="page-header"><h1>{t('suppressions.title')}</h1></div>
      <p className="muted">{t('suppressions.lead')}</p>

      <div className="toolbar">
        <HelpInput help={t('suppressions.search')} placeholder={t('suppressions.searchPlaceholder')} type="search" value={search} onChange={(e) => setParam('search', e.target.value)} />
        <HelpSelect help={t('suppressions.state')} value={state} onChange={(e) => setParam('state', e.target.value)}>
          {STATES.map((s) => <option key={s} value={s}>{t(`suppressions.state.${s}`)}</option>)}
        </HelpSelect>
      </div>

      <ErrorAlert message={error ?? actionError} />
      {loading ? (
        <LoadingSpinner />
      ) : rows.length === 0 ? (
        <p className="muted">{t('suppressions.empty')}</p>
      ) : (
        <>
          <DataTable
            rows={rows}
            rowKey={(r) => r.id}
            columns={[
              { key: 'email', header: t('suppressions.col.email'), render: (r) => <span style={{ wordBreak: 'break-all' }}>{r.email}</span> },
              { key: 'reason', header: t('suppressions.col.reason'), render: (r) => reasonLabel(r.reason) },
              { key: 'description', header: t('suppressions.col.description'), render: (r) => r.description ?? '—' },
              { key: 'suppressed', header: t('suppressions.col.suppressedAt'), render: (r) => formatDateTime(r.suppressed_at) },
              {
                key: 'state', header: t('suppressions.col.releasedAt'),
                render: (r) => (r.active ? <StatusBadge status="ACTIVE" /> : `${formatDateTime(r.released_at)}${r.released_by ? ` · ${r.released_by.name}` : ''}`),
              },
              {
                key: 'actions', header: t('suppressions.col.actions'),
                render: (r) => r.active && (
                  <PermissionGuard permission="cgo.notification.delivery.retry">
                    <button className="btn btn-secondary btn-sm" onClick={() => setPending(r)}>{t('suppressions.release')}</button>
                  </PermissionGuard>
                ),
              },
            ]}
          />
          <Pagination meta={meta} onPageChange={setPage} />
        </>
      )}

      <ConfirmDialog
        open={pending !== null}
        title={t('suppressions.releaseTitle')}
        message={t('suppressions.releaseMessage', { email: pending?.email ?? '' })}
        confirmLabel={t('suppressions.release')}
        busy={busy}
        onConfirm={release}
        onCancel={() => setPending(null)}
      />
    </div>
  );
}
