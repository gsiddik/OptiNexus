import { useState, type FormEvent } from 'react';
import { HelpInput, HelpLabel, HelpSelect } from '../../components/FieldControls';
import { useAuth } from '../../auth/useAuth';
import { Modal } from '../../components/Modal';
import { unwrapError } from '../../api/client';
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
export function TenantSeats({ tenant, revision = 0 }: { tenant: Tenant; revision?: number }) {
  const { t } = useI18n();
  const { data, loading, error, reload } = useApiResource<SeatStatus[]>(() => tenantsApi.seats(tenant.id), [tenant.id, revision]);

  const { hasPermission } = useAuth();
  const [editing, setEditing] = useState(false);
  const apps = useApiResource(() => tenantsApi.applications(tenant.id), [tenant.id, revision]);
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
      {hasPermission('cgo.entitlement.override') && <button className="btn btn-secondary" onClick={() => setEditing(true)}>Add / Edit Seats</button>}
      <Modal open={editing} title="Configure Seats" onClose={() => setEditing(false)}>
        <SeatForm tenantId={tenant.id} applications={(apps.data ?? []).filter((app) => (data ?? []).some((seat) => seat.application_code === app.application_code && seat.controlled))} seats={data ?? []} onSaved={() => { setEditing(false); reload(); }} />
      </Modal>
      <DataTable columns={columns} rows={data ?? []} rowKey={(s) => s.application_code} emptyMessage={t('seats.empty')} />
      {blocked.map((s) => {
        const key = `seats.hint.${s.blocked_reason}`;
        return hasMessage(key) ? <p key={s.application_code} className="muted"><strong>{s.application_code}:</strong> {t(key)}</p> : null;
      })}
    </div>
  );
}

function SeatForm({ tenantId, applications, seats, onSaved }: { tenantId: string; applications: import('../../api/types').Application[]; seats: SeatStatus[]; onSaved: () => void }) {
  const [app, setApp] = useState('');
  const [limit, setLimit] = useState('');
  const [reason, setReason] = useState('');
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  async function submit(event: FormEvent) {
    event.preventDefault(); setBusy(true); setError(null);
    try { await tenantsApi.updateSeats(tenantId, app, { limit: Number(limit), reason }); onSaved(); }
    catch (err) { setError(unwrapError(err).message); } finally { setBusy(false); }
  }
  return <form className="form" onSubmit={submit}><ErrorAlert message={error} />
    <HelpLabel>Application<HelpSelect help="Choose an assigned application with a seat limit." required value={app} onChange={(e) => { setApp(e.target.value); const selected = applications.find((a) => a.id === e.target.value); const seat = seats.find((s) => s.application_code === selected?.application_code); setLimit(seat?.limit == null ? '' : String(seat.limit)); }}><option value="">Select application...</option>{applications.map((a) => <option key={a.id} value={a.id}>{a.name}</option>)}</HelpSelect></HelpLabel>
    <HelpLabel>Seat Limit<HelpInput help="Enter the total allowed users. Used and reserved seats are calculated from assignments and invitations. Reducing the limit never removes users." type="number" min={0} max={2000000000} required value={limit} onChange={(e) => setLimit(e.target.value)} /></HelpLabel>
    <HelpLabel>Reason<HelpInput help="Explain the seat adjustment for the audit trail, at least 10 characters." required minLength={10} maxLength={500} value={reason} onChange={(e) => setReason(e.target.value)} /></HelpLabel>
    <button className="btn btn-primary" disabled={busy || !app}>Save Seats</button>
  </form>;
}
