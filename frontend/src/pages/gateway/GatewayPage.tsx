import { useMemo, useState } from 'react';
import { useParams } from 'react-router-dom';
import { describeError, type ApiRequestError } from '../../api/client';
import { gatewayAdminApi, tenantsApi } from '../../api/endpoints';
import { useApiList, useApiResource } from '../../api/useApi';
import type { GatewayLink, GatewayRequestLog, GatewayVehicle, Tenant } from '../../api/types';
import { useAuth } from '../../auth/useAuth';
import { ConfirmDialog } from '../../components/ConfirmDialog';
import { DataTable } from '../../components/DataTable';
import { ErrorAlert } from '../../components/ErrorAlert';
import { LoadingSpinner } from '../../components/LoadingSpinner';
import { Modal } from '../../components/Modal';
import { Pagination } from '../../components/Pagination';
import { EmptyState, ErrorState, ForbiddenState } from '../../components/StateViews';
import { Badge, StatusBadge } from '../../components/StatusBadge';
import { formatDateTime, formatRelative, useI18n, type MessageKey } from '../../i18n';
import { classifyAll, countByClass, matchesSearch, onlyTenant, syncHealth, type Classified, type LinkClass, type SyncState } from './gatewayMapping';

export const GATEWAY_LINK_VIEW = 'cgo.gateway.link.view';
export const GATEWAY_LINK_MANAGE = 'cgo.gateway.link.manage';
export const GATEWAY_LOG_VIEW = 'cgo.gateway.log.view';

const CLASS_TONE: Record<LinkClass, 'success' | 'warning' | 'danger' | 'neutral'> = { matched: 'success', unmatched: 'warning', ambiguous: 'danger', unlinked: 'neutral' };
const HEALTH_TONE: Record<SyncState, 'success' | 'warning' | 'danger' | 'neutral'> = { healthy: 'success', degraded: 'danger', stale: 'warning', none: 'neutral' };

/** Per-tenant device <-> vehicle mapping and gateway traffic. Needs `cgo.gateway.link.view` and/or `cgo.gateway.log.view`. */
export function GatewayPage() {
  const { tenantId = '' } = useParams<{ tenantId: string }>();
  const { hasPermission } = useAuth();
  const { t } = useI18n();
  const canLinks = hasPermission(GATEWAY_LINK_VIEW);
  const canLogs = hasPermission(GATEWAY_LOG_VIEW);
  const tenant = useApiResource<Tenant>(() => tenantsApi.get(tenantId), [tenantId]);

  const title = tenant.data?.name ?? t('gw.title');
  if (!canLinks && !canLogs) {
    return (
      <div>
        <div className="page-header"><h1>{t('gw.title')}</h1></div>
        <ForbiddenState permission={GATEWAY_LINK_VIEW} />
      </div>
    );
  }
  return (
    <div>
      <div className="page-header">
        <h1>{title}</h1>
        {tenant.data && <StatusBadge status={tenant.data.status} />}
      </div>
      <p className="lead">{t('gw.title')}</p>
      <p className="muted">{t('gw.intro')}</p>
      {tenant.errorInfo && tenant.errorInfo.kind !== 'forbidden' && <ErrorAlert message={t('gw.tenantFailed')} />}
      {/* Keyed by tenant: switching tenant discards everything loaded for the previous one. */}
      <GatewayView key={tenantId} tenantId={tenantId} canLinks={canLinks} canLogs={canLogs} canManage={hasPermission(GATEWAY_LINK_MANAGE)} />
    </div>
  );
}

function GatewayView({ tenantId, canLinks, canLogs, canManage }: { tenantId: string; canLinks: boolean; canLogs: boolean; canManage: boolean }) {
  const { t } = useI18n();
  const [tab, setTab] = useState<'links' | 'logs'>(canLinks ? 'links' : 'logs');

  // The vehicle directory is needed for the ambiguity rule and the link picker, so both are fetched together.
  const links = useApiResource<GatewayLink[]>(() => (canLinks ? gatewayAdminApi.links(tenantId) : Promise.resolve([])), [tenantId, canLinks]);
  const vehicles = useApiResource<GatewayVehicle[]>(() => (canLinks ? gatewayAdminApi.vehicles(tenantId) : Promise.resolve([])), [tenantId, canLinks]);
  const recent = useApiResource<GatewayRequestLog[]>(
    () => (canLogs ? gatewayAdminApi.logs(tenantId, { page: 1, per_page: 50 }).then((e) => e.data) : Promise.resolve([])),
    [tenantId, canLogs],
  );

  const vehicleRows = useMemo(() => onlyTenant(vehicles.data ?? [], tenantId), [vehicles.data, tenantId]);
  const classified = useMemo(() => classifyAll(links.data ?? [], vehicleRows), [links.data, vehicleRows]);
  const counts = useMemo(() => countByClass(classified), [classified]);
  const health = useMemo(() => syncHealth(onlyTenant(recent.data ?? [], tenantId)), [recent.data, tenantId]);

  const reloadAll = () => {
    links.reload();
    vehicles.reload();
    recent.reload();
  };

  return (
    <>
      <Summary counts={counts} vehicleCount={vehicleRows.length} health={health} loading={recent.loading} canLogs={canLogs} canLinks={canLinks} />
      {counts.unmatched + counts.ambiguous > 0 && (
        <div className="alert alert-warning" role="status">{t('gw.attention.unmatched', { n: counts.unmatched + counts.ambiguous })}</div>
      )}
      <div className="tabs" role="tablist">
        {canLinks && (
          <button type="button" role="tab" aria-selected={tab === 'links'} className={tab === 'links' ? 'tab active' : 'tab'} onClick={() => setTab('links')}>{t('gw.tab.links')}</button>
        )}
        {canLogs && (
          <button type="button" role="tab" aria-selected={tab === 'logs'} className={tab === 'logs' ? 'tab active' : 'tab'} onClick={() => setTab('logs')}>{t('gw.tab.logs')}</button>
        )}
      </div>
      {tab === 'links' && canLinks && (
        <LinksPanel
          tenantId={tenantId}
          rows={classified}
          vehicles={vehicleRows}
          loading={links.loading || vehicles.loading}
          error={links.errorInfo ?? vehicles.errorInfo}
          canManage={canManage}
          onRetry={reloadAll}
          onChanged={reloadAll}
        />
      )}
      {tab === 'logs' && canLogs && <LogsPanel tenantId={tenantId} health={health} />}
    </>
  );
}

function Summary({ counts, vehicleCount, health, loading, canLogs, canLinks }: { counts: Record<LinkClass, number>; vehicleCount: number; health: ReturnType<typeof syncHealth>; loading: boolean; canLogs: boolean; canLinks: boolean }) {
  const { t } = useI18n();
  return (
    <div className="card-grid summary-grid" data-testid="gateway-summary">
      {canLinks && (['matched', 'unmatched', 'ambiguous', 'unlinked'] as LinkClass[]).map((cls) => (
        <div className="card summary-card" key={cls} data-testid={`count-${cls}`}>
          <div className="summary-value">{counts[cls]}</div>
          <div><Badge tone={CLASS_TONE[cls]}>{t(`gw.class.${cls}` as MessageKey)}</Badge></div>
          <div className="muted summary-note">{t(`gw.class.${cls}Hint` as MessageKey)}</div>
        </div>
      ))}
      {canLinks && (
        <div className="card summary-card">
          <div className="summary-value">{vehicleCount}</div>
          <div className="summary-label">{t('gw.summary.vehicles')}</div>
        </div>
      )}
      <div className="card summary-card" data-testid="sync-health">
        <div className="summary-label">{t('gw.summary.health')}</div>
        {!canLogs ? (
          <div className="muted summary-note">{t('gw.summary.noLogPermission')}</div>
        ) : loading ? (
          <div className="muted summary-note">{t('common.loading')}</div>
        ) : (
          <>
            <div><Badge tone={HEALTH_TONE[health.state]}>{t(`gw.health.${health.state}` as MessageKey)}</Badge></div>
            <div className="muted summary-note">{t(`gw.health.${health.state}Help` as MessageKey)}</div>
            {health.lastAt ? (
              <div className="summary-note" title={formatDateTime(health.lastAt)}>{t('gw.summary.lastRequest')}: {formatRelative(health.lastAt)}</div>
            ) : (
              <div className="muted summary-note">{t('gw.summary.noLogs')}</div>
            )}
            {health.total > 0 && <div className="summary-note">{t('gw.summary.errors')}: {t('gw.summary.errorsOf', { errors: health.errors, total: health.total })}</div>}
          </>
        )}
      </div>
    </div>
  );
}

function LinksPanel({ tenantId, rows, vehicles, loading, error, canManage, onRetry, onChanged }: {
  tenantId: string; rows: Classified[]; vehicles: GatewayVehicle[]; loading: boolean; error: ApiRequestError | null; canManage: boolean; onRetry: () => void; onChanged: () => void;
}) {
  const { t } = useI18n();
  const [filters, setFilters] = useState<{ cls: LinkClass | ''; source: string; search: string }>({ cls: '', source: '', search: '' });
  const [linking, setLinking] = useState<Classified | null>(null);
  const [unlinking, setUnlinking] = useState<Classified | null>(null);
  const [busy, setBusy] = useState(false);
  const [actionError, setActionError] = useState<string | null>(null);

  const sources = useMemo(() => [...new Set(rows.map((r) => r.link.telematics_source))].sort(), [rows]);
  const visible = useMemo(
    () => rows.filter((r) => (!filters.cls || r.cls === filters.cls) && (!filters.source || r.link.telematics_source === filters.source) && matchesSearch(r, filters.search)),
    [rows, filters],
  );

  async function doLink(vehicleId: string) {
    if (!linking) return;
    setBusy(true);
    try {
      await gatewayAdminApi.link(tenantId, linking.link.id, vehicleId);
      setLinking(null);
      onChanged();
    } finally {
      setBusy(false);
    }
  }

  async function doUnlink() {
    if (!unlinking) return;
    setBusy(true);
    setActionError(null);
    try {
      await gatewayAdminApi.unlink(tenantId, unlinking.link.id);
      onChanged();
    } catch (err) {
      setActionError(describeError(err));
    } finally {
      setUnlinking(null);
      setBusy(false);
    }
  }

  if (loading) return <LoadingSpinner />;
  if (error) return <ErrorState error={error} onRetry={onRetry} permission={GATEWAY_LINK_VIEW} />;
  if (rows.length === 0) return <EmptyState title={t('gw.empty.title')} hint={t('gw.empty.hint')} />;

  const deviceLabel = (r: Classified) => r.link.device_name || r.link.device_ref;
  return (
    <>
      <ErrorAlert message={actionError} />
      <div className="toolbar">
        <select aria-label={t('gw.filter.class')} value={filters.cls} onChange={(e) => setFilters({ ...filters, cls: e.target.value as LinkClass | '' })}>
          <option value="">{t('common.all')}</option>
          {(['matched', 'unmatched', 'ambiguous', 'unlinked'] as LinkClass[]).map((cls) => <option key={cls} value={cls}>{t(`gw.class.${cls}` as MessageKey)}</option>)}
        </select>
        <select aria-label={t('gw.filter.source')} value={filters.source} onChange={(e) => setFilters({ ...filters, source: e.target.value })}>
          <option value="">{t('gw.filter.allSources')}</option>
          {sources.map((s) => <option key={s} value={s}>{s}</option>)}
        </select>
        <input type="search" aria-label={t('gw.filter.search')} placeholder={t('gw.filter.search')} value={filters.search} onChange={(e) => setFilters({ ...filters, search: e.target.value })} />
      </div>
      <DataTable
        rows={visible}
        rowKey={(r) => r.link.id}
        emptyMessage={t('gw.empty.filtered')}
        columns={[
          { key: 'device', header: t('gw.col.device'), render: (r) => (<><strong>{deviceLabel(r)}</strong>{r.link.device_name && <><br /><code className="break-all">{r.link.device_ref}</code></>}</>) },
          { key: 'source', header: t('gw.col.source'), render: (r) => r.link.telematics_source },
          { key: 'plate', header: t('gw.col.plate'), render: (r) => r.link.device_registration ?? '—' },
          { key: 'state', header: t('gw.col.state'), render: (r) => <Badge tone={CLASS_TONE[r.cls]}>{t(`gw.class.${r.cls}` as MessageKey)}</Badge> },
          {
            key: 'vehicle',
            header: t('gw.col.vehicle'),
            render: (r) => {
              if (r.link.fleet_vehicle) return <span>{r.link.fleet_vehicle.registration_number} <span className="muted">({r.link.fleet_vehicle.external_vehicle_id})</span></span>;
              if (r.cls === 'ambiguous') return <span className="muted">{t('gw.candidates', { n: r.candidates.length })}</span>;
              if (r.suggestion) return <span className="muted">{t('gw.suggested', { plate: r.suggestion.registration_number })}</span>;
              return '—';
            },
          },
          { key: 'how', header: t('gw.col.how'), render: (r) => t(`gw.how.${r.link.link_type}` as MessageKey) },
          {
            key: 'actions',
            className: 'sticky-end',
            header: '',
            render: (r) =>
              canManage ? (
                <div className="row-actions">
                  <button type="button" className="btn btn-ghost" onClick={() => setLinking(r)}>{r.cls === 'matched' ? t('gw.action.relink') : t('gw.action.link')}</button>
                  {r.cls === 'matched' && <button type="button" className="btn btn-ghost" onClick={() => setUnlinking(r)}>{t('gw.action.unlink')}</button>}
                </div>
              ) : null,
          },
        ]}
      />

      <Modal open={!!linking} title={linking ? t('gw.link.title', { device: deviceLabel(linking) }) : ''} onClose={() => !busy && setLinking(null)} dismissible={false}>
        {linking && <LinkForm key={linking.link.id} row={linking} vehicles={vehicles} busy={busy} onSubmit={doLink} onCancel={() => setLinking(null)} />}
      </Modal>
      <ConfirmDialog
        open={!!unlinking}
        danger
        busy={busy}
        title={unlinking ? t('gw.unlink.title', { device: deviceLabel(unlinking) }) : ''}
        message={unlinking ? <p>{t('gw.unlink.body', { plate: unlinking.link.fleet_vehicle?.registration_number ?? '' })}</p> : null}
        confirmLabel={t('gw.unlink.confirm')}
        onConfirm={doUnlink}
        onCancel={() => setUnlinking(null)}
      />
    </>
  );
}

function LinkForm({ row, vehicles, busy, onSubmit, onCancel }: { row: Classified; vehicles: GatewayVehicle[]; busy: boolean; onSubmit: (vehicleId: string) => Promise<void>; onCancel: () => void }) {
  const { t } = useI18n();
  const [vehicleId, setVehicleId] = useState(row.suggestion?.id ?? (row.candidates.length === 1 ? row.candidates[0].id : ''));
  const [needle, setNeedle] = useState('');
  const [error, setError] = useState<string | null>(null);
  const [missing, setMissing] = useState(false);

  const candidateIds = new Set(row.candidates.map((c) => c.id));
  const text = needle.trim().toLowerCase();
  const filtered = vehicles.filter((v) => !text || v.registration_number.toLowerCase().includes(text) || v.external_vehicle_id.toLowerCase().includes(text) || (v.vin ?? '').toLowerCase().includes(text));
  const candidates = filtered.filter((v) => candidateIds.has(v.id));
  const others = filtered.filter((v) => !candidateIds.has(v.id));
  const option = (v: GatewayVehicle) => <option key={v.id} value={v.id}>{v.registration_number} ({v.external_vehicle_id})</option>;

  async function submit(e: React.FormEvent) {
    e.preventDefault();
    if (!vehicleId) {
      setMissing(true);
      return;
    }
    setError(null);
    try {
      await onSubmit(vehicleId);
    } catch (err) {
      setError(describeError(err));
    }
  }

  if (vehicles.length === 0) return (
    <div>
      <p className="alert alert-warning">{t('gw.link.noVehicles')}</p>
      <div className="modal-actions"><button type="button" className="btn btn-ghost" onClick={onCancel}>{t('common.close')}</button></div>
    </div>
  );

  return (
    <form className="form" onSubmit={submit} noValidate>
      <p className="muted">{t('gw.link.help')}</p>
      <ErrorAlert message={error} />
      <label>
        {t('gw.link.search')}
        <input type="search" value={needle} onChange={(e) => setNeedle(e.target.value)} autoComplete="off" />
      </label>
      <label>
        {t('gw.link.vehicle')}
        <select value={vehicleId} onChange={(e) => { setVehicleId(e.target.value); setMissing(false); }} aria-invalid={missing || undefined}>
          <option value="">{t('gw.link.pick')}</option>
          {candidates.length > 0 && <optgroup label={t('gw.link.candidatesFirst')}>{candidates.map(option)}</optgroup>}
          <optgroup label={t('gw.link.others')}>{others.map(option)}</optgroup>
        </select>
      </label>
      {missing && <span className="field-error" role="alert">{t('gw.link.needVehicle')}</span>}
      <div className="modal-actions">
        <button type="button" className="btn btn-ghost" onClick={onCancel} disabled={busy}>{t('common.cancel')}</button>
        <button type="submit" className="btn btn-primary" disabled={busy}>{t('gw.link.confirm')}</button>
      </div>
    </form>
  );
}

function statusTone(code: number): 'success' | 'warning' | 'danger' | 'neutral' {
  if (code >= 500) return 'danger';
  if (code >= 400) return 'warning';
  if (code >= 200 && code < 400) return 'success';
  return 'neutral';
}

function LogsPanel({ tenantId, health }: { tenantId: string; health: ReturnType<typeof syncHealth> }) {
  const { t } = useI18n();
  const [errorsOnly, setErrorsOnly] = useState(false);
  const { rows, meta, setPage, loading, errorInfo, reload } = useApiList<GatewayRequestLog>((page) => gatewayAdminApi.logs(tenantId, { page, per_page: 50 }), [tenantId]);

  const visible = useMemo(() => onlyTenant(rows, tenantId).filter((l) => !errorsOnly || l.status_code >= 400), [rows, tenantId, errorsOnly]);
  if (loading) return <LoadingSpinner />;
  if (errorInfo) return <ErrorState error={errorInfo} onRetry={reload} permission={GATEWAY_LOG_VIEW} />;
  if (rows.length === 0) return <EmptyState title={t('gw.logs.empty')} />;

  return (
    <>
      <p className="muted">{t('gw.health.basis', { n: health.total })}</p>
      <div className="toolbar">
        <label className="check-inline">
          <input type="checkbox" checked={errorsOnly} onChange={(e) => setErrorsOnly(e.target.checked)} />
          <span>{t('gw.logs.filterErrors')}</span>
        </label>
      </div>
      <DataTable
        rows={visible}
        rowKey={(l) => l.id}
        emptyMessage={t('gw.logs.emptyFiltered')}
        columns={[
          { key: 'time', header: t('gw.logs.col.time'), render: (l) => <span className="nowrap" title={formatDateTime(l.created_at)}>{formatDateTime(l.created_at)}</span> },
          { key: 'method', header: t('gw.logs.col.method'), render: (l) => <code>{l.method}</code> },
          { key: 'path', header: t('gw.logs.col.path'), render: (l) => <code className="break-all">{l.path}</code> },
          { key: 'status', header: t('gw.logs.col.status'), render: (l) => <Badge tone={statusTone(l.status_code)}>{String(l.status_code)}</Badge> },
          { key: 'duration', header: t('gw.logs.col.duration'), render: (l) => (l.duration_ms === null ? '—' : t('gw.logs.ms', { n: l.duration_ms })) },
          { key: 'corr', header: t('gw.logs.col.correlation'), render: (l) => (l.correlation_id ? <code className="break-all">{l.correlation_id}</code> : '—') },
        ]}
      />
      <Pagination meta={meta} onPageChange={setPage} />
    </>
  );
}
