import { useSearchParams, useNavigate } from 'react-router-dom';
import { approvalRequestsApi } from '../../../api/endpoints';
import { useApiList } from '../../../api/useApi';
import { DataTable } from '../../../components/DataTable';
import { Pagination } from '../../../components/Pagination';
import { StatusBadge } from '../../../components/StatusBadge';
import { LoadingSpinner } from '../../../components/LoadingSpinner';
import { ErrorAlert } from '../../../components/ErrorAlert';
import type { ApprovalRequest } from '../../../api/types';

export function ApprovalRequestsListPage() {
  const navigate = useNavigate();
  const [params, setParams] = useSearchParams();
  const status = params.get('status') ?? '';

  const { rows, meta, setPage, loading, error } = useApiList<ApprovalRequest>(
    (p) => approvalRequestsApi.list({ page: p, status: status || undefined }),
    [status],
  );

  return (
    <div>
      <div className="page-header"><h1>Approval Requests</h1></div>

      <div className="toolbar">
        <select value={status} onChange={(e) => setParams((p) => { p.set('status', e.target.value); return p; })}>
          <option value="">Any status</option>
          {['PENDING', 'IN_PROGRESS', 'APPROVED', 'REJECTED', 'RETURNED', 'CANCELLED', 'EXPIRED'].map((s) => <option key={s} value={s}>{s}</option>)}
        </select>
      </div>

      <ErrorAlert message={error} />
      {loading ? (
        <LoadingSpinner />
      ) : (
        <>
          <DataTable
            rows={rows}
            rowKey={(r) => r.id}
            onRowClick={(r) => navigate(`/approval-requests/${r.id}`)}
            columns={[
              { key: 'id', header: 'Request', render: (r) => <code>{r.id.slice(0, 8)}</code> },
              { key: 'level', header: 'Current Level', render: (r) => r.current_level },
              { key: 'correlation', header: 'Correlation', render: (r) => r.correlation_id ? <code>{r.correlation_id.slice(0, 8)}</code> : '—' },
              { key: 'status', header: 'Status', render: (r) => <StatusBadge status={r.status} /> },
              { key: 'created', header: 'Created', render: (r) => new Date(r.created_at).toLocaleString() },
            ]}
          />
          <Pagination meta={meta} onPageChange={setPage} />
        </>
      )}
    </div>
  );
}
