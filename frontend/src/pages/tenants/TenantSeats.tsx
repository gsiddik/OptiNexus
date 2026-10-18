import { tenantsApi } from '../../api/endpoints';
import { useApiResource } from '../../api/useApi';
import type { SeatState, SeatStatus } from '../../api/types';
import { DataTable, type Column } from '../../components/DataTable';
import { ErrorAlert } from '../../components/ErrorAlert';
import { LoadingSpinner } from '../../components/LoadingSpinner';
import { hasMessage, useI18n } from '../../i18n';
import type { Tenant } from '../../api/types';

const TONE: Record<SeatState, 'success' | 'warning' | 'danger' | 'info' | 'neutral'> = {
  NOT_CONTROLLED: 'neutral',
  LIMITED: 'success',
  UNLIMITED: 'info',
  ENTITLEMENT_MISSING: 'warning',
  ENTITLEMENT_INVALID: 'danger',
  ENTITLEMENT_UNAVAILABLE: 'warning',
};

/**
 * Read-only seat picture of a tenant, exactly as OptiNexus counts it (used, reserved, limit, available). Over-limit is shown beside
 * the numbers, never as a negative availability. The operator changes the limit through the entitlement, not here.
 */
export function TenantSeats({ tenant }: { tenant: Tenant }) {
  const { t } = useI18n();
  const { data, loading, error } = useApiResource<SeatStatus[]>(() => tenantsApi.seats(tenant.id), [tenant.id]);

  const number = (value: number | null) => (value === null ? '—' : String(value));

  const columns: Column<SeatStatus>[] = [
    { key: 'app', header: t('seats.col.application'), render: (s) => s.application_code },
    {
      key: 'state',
      header: t('seats.col.state'),
      render: (s) => (
        <span className="badge-row">
          <span className={`badge badge-${TONE[s.state]} nowrap`}>{t(`seats.state.${s.state}` as 'seats.state.LIMITED')}</span>
          {s.over_limit && <span className="badge badge-danger nowrap">{t('seats.overBy', { n: s.over_by })}</span>}
        </span>
      ),
    },
    { key: 'limit', header: t('seats.col.limit'), render: (s) => (s.unlimited ? t('seats.unlimited') : number(s.limit)) },
    { key: 'used', header: t('seats.col.used'), render: (s) => s.used },
    { key: 'reserved', header: t('seats.col.reserved'), render: (s) => s.reserved },
    { key: 'available', header: t('seats.col.available'), render: (s) => number(s.available) },
  ];

  const blocked = (data ?? []).filter((s) => s.controlled && s.blocked_reason);

  if (loading && !data) return <LoadingSpinner />;
  return (
    <div className="card" aria-labelledby="seats-title">
      <h2 id="seats-title">{t('seats.title')}</h2>
      <p className="muted">{t('seats.intro')}</p>
      <ErrorAlert message={error} />
      <DataTable columns={columns} rows={data ?? []} rowKey={(s) => s.application_code} emptyMessage={t('seats.empty')} />
      {blocked.map((s) => {
        const key = `seats.hint.${s.blocked_reason}`;
        return hasMessage(key) ? <p key={s.application_code} className="muted"><strong>{s.application_code}:</strong> {t(key)}</p> : null;
      })}
    </div>
  );
}
