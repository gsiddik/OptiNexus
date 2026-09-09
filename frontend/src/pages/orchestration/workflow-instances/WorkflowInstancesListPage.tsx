import { useSearchParams, useNavigate } from 'react-router-dom';
import { workflowInstancesApi } from '../../../api/endpoints';
import { useApiList } from '../../../api/useApi';
import { DataTable } from '../../../components/DataTable';
import { Pagination } from '../../../components/Pagination';
import { StatusBadge } from '../../../components/StatusBadge';
import { LoadingSpinner } from '../../../components/LoadingSpinner';
import { ErrorAlert } from '../../../components/ErrorAlert';
import type { WorkflowInstance } from '../../../api/types';

export function WorkflowInstancesListPage() {
  const navigate = useNavigate();
  const [params, setParams] = useSearchParams();
  const workflowId = params.get('workflow_id') ?? '';
  const status = params.get('status') ?? '';
  const correlationId = params.get('correlation_id') ?? '';

  const { rows, meta, setPage, loading, error } = useApiList<WorkflowInstance>(
    (p) => workflowInstancesApi.list({ page: p, workflow_id: workflowId || undefined, status: status || undefined, correlation_id: correlationId || undefined }),
    [workflowId, status, correlationId],
  );

  return (
    <div>
      <div className="page-header"><h1>Workflow Instances</h1></div>

      <div className="toolbar">
        <input placeholder="Filter by correlation_id" value={correlationId} onChange={(e) => setParams((p) => { p.set('correlation_id', e.target.value); return p; })} />
        <select value={status} onChange={(e) => setParams((p) => { p.set('status', e.target.value); return p; })}>
          <option value="">Any status</option>
          {['PENDING', 'RUNNING', 'WAITING', 'WAITING_APPROVAL', 'COMPLETED', 'FAILED', 'CANCELLED'].map((s) => <option key={s} value={s}>{s}</option>)}
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
            onRowClick={(r) => navigate(`/workflow-instances/${r.id}`)}
            columns={[
              { key: 'id', header: 'Instance', render: (r) => <code>{r.id.slice(0, 8)}</code> },
              { key: 'trigger', header: 'Trigger', render: (r) => r.trigger_type },
              { key: 'correlation', header: 'Correlation', render: (r) => r.correlation_id ? <code>{r.correlation_id.slice(0, 8)}</code> : '—' },
              { key: 'status', header: 'Status', render: (r) => <StatusBadge status={r.status} /> },
              { key: 'started', header: 'Started', render: (r) => new Date(r.started_at).toLocaleString() },
            ]}
          />
          <Pagination meta={meta} onPageChange={setPage} />
        </>
      )}
    </div>
  );
}
