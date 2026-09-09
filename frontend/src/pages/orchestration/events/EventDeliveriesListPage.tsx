import { useSearchParams } from 'react-router-dom';
import { eventDeliveriesApi } from '../../../api/endpoints';
import { useApiList } from '../../../api/useApi';
import { DataTable } from '../../../components/DataTable';
import { Pagination } from '../../../components/Pagination';
import { StatusBadge } from '../../../components/StatusBadge';
import { LoadingSpinner } from '../../../components/LoadingSpinner';
import { ErrorAlert } from '../../../components/ErrorAlert';
import { PermissionGuard } from '../../../auth/PermissionGuard';
import { unwrapError } from '../../../api/client';
import type { EventDelivery } from '../../../api/types';
import { useState } from 'react';

export function EventDeliveriesListPage() {
  const [params, setParams] = useSearchParams();
  const status = params.get('status') ?? '';
  const consumerType = params.get('consumer_type') ?? '';
  const [actionError, setActionError] = useState<string | null>(null);

  const { rows, meta, setPage, loading, error, reload } = useApiList<EventDelivery>(
    (p) => eventDeliveriesApi.list({ page: p, status: status || undefined, consumer_type: consumerType || undefined }),
    [status, consumerType],
  );

  async function retry(id: string) {
    setActionError(null);
    try {
      await eventDeliveriesApi.retry(id);
      reload();
    } catch (err) {
      setActionError(unwrapError(err).message);
    }
  }

  async function discard(id: string) {
    setActionError(null);
    try {
      await eventDeliveriesApi.discard(id);
      reload();
    } catch (err) {
      setActionError(unwrapError(err).message);
    }
  }

  return (
    <div>
      <div className="page-header"><h1>Event Deliveries</h1></div>

      <div className="toolbar">
        <select value={status} onChange={(e) => setParams((p) => { p.set('status', e.target.value); return p; })}>
          <option value="">Any status</option>
          {['PENDING', 'DELIVERED', 'FAILED', 'DISCARDED'].map((s) => <option key={s} value={s}>{s}</option>)}
        </select>
        <select value={consumerType} onChange={(e) => setParams((p) => { p.set('consumer_type', e.target.value); return p; })}>
          <option value="">Any consumer</option>
          {['WORKFLOW', 'NOTIFICATION', 'WEBHOOK', 'INTEGRATION'].map((c) => <option key={c} value={c}>{c}</option>)}
        </select>
      </div>

      <ErrorAlert message={error ?? actionError} />
      {loading ? (
        <LoadingSpinner />
      ) : (
        <>
          <DataTable
            rows={rows}
            rowKey={(r) => r.id}
            columns={[
              { key: 'event', header: 'Event', render: (r) => <code>{r.event_id.slice(0, 8)}</code> },
              { key: 'consumer', header: 'Consumer', render: (r) => `${r.consumer_type}${r.consumer_reference ? ` (${r.consumer_reference.slice(0, 8)})` : ''}` },
              { key: 'attempts', header: 'Attempts', render: (r) => r.attempt_count },
              { key: 'status', header: 'Status', render: (r) => <StatusBadge status={r.status} /> },
              { key: 'error', header: 'Last Error', render: (r) => r.last_error_message ?? '—' },
              {
                key: 'actions',
                header: 'Actions',
                render: (r) => (
                  <div className="toolbar-inline">
                    {r.status === 'FAILED' && (
                      <PermissionGuard permission="cgo.event.delivery.retry">
                        <button className="btn btn-secondary btn-sm" onClick={() => retry(r.id)}>Retry</button>
                      </PermissionGuard>
                    )}
                    {r.status !== 'DELIVERED' && r.status !== 'DISCARDED' && (
                      <PermissionGuard permission="cgo.event.delivery.discard">
                        <button className="btn btn-danger btn-sm" onClick={() => discard(r.id)}>Discard</button>
                      </PermissionGuard>
                    )}
                  </div>
                ),
              },
            ]}
          />
          <Pagination meta={meta} onPageChange={setPage} />
        </>
      )}
    </div>
  );
}
