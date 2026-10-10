import { useParams } from 'react-router-dom';
import { workflowInstancesApi } from '../../../api/endpoints';
import { useApiResource } from '../../../api/useApi';
import { LoadingSpinner } from '../../../components/LoadingSpinner';
import { ErrorAlert } from '../../../components/ErrorAlert';
import { StatusBadge } from '../../../components/StatusBadge';
import { LifecycleActions } from '../../../components/LifecycleActions';
import { useAuth } from '../../../auth/useAuth';

export function WorkflowInstanceDetailPage() {
  const { id } = useParams<{ id: string }>();
  const { hasPermission } = useAuth();
  const { data: instance, loading, error, reload } = useApiResource(() => workflowInstancesApi.get(id!), [id]);

  if (loading) return <LoadingSpinner />;
  if (error) return <ErrorAlert message={error} />;
  if (!instance) return null;

  return (
    <div>
      <div className="page-header">
        <h1>Workflow Instance</h1>
        <StatusBadge status={instance.status} />
      </div>

      <LifecycleActions
        status={instance.status}
        hasPermission={hasPermission}
        onDone={reload}
        actions={[
          { key: 'retry', label: 'Retry', allowedFrom: ['FAILED'], permission: 'cgo.workflow.retry', action: () => workflowInstancesApi.retry(instance.id) },
          { key: 'cancel', label: 'Cancel', allowedFrom: ['PENDING', 'RUNNING', 'WAITING', 'WAITING_APPROVAL'], permission: 'cgo.workflow.cancel', danger: true, action: () => workflowInstancesApi.cancel(instance.id) },
        ]}
      />

      <div className="detail-grid">
        <div className="card">
          <h3>Correlation</h3>
          <dl className="definition-list">
            <dt>Instance ID</dt><dd><code>{instance.id}</code></dd>
            <dt>Correlation ID</dt><dd><code>{instance.correlation_id ?? '—'}</code></dd>
            <dt>Causation ID</dt><dd><code>{instance.causation_id ?? '—'}</code></dd>
            <dt>Trigger Type</dt><dd>{instance.trigger_type}</dd>
            <dt>Trigger Event</dt><dd>{instance.trigger_event_id ?? '—'}</dd>
            <dt>Started</dt><dd>{new Date(instance.started_at).toLocaleString()}</dd>
            <dt>Completed</dt><dd>{instance.completed_at ? new Date(instance.completed_at).toLocaleString() : '—'}</dd>
            {instance.last_error_code && <><dt>Last Error</dt><dd>{instance.last_error_code}: {instance.last_error_message}</dd></>}
          </dl>
        </div>

        <div className="card">
          <h3>Step History</h3>
          <table className="data-table">
            <thead><tr><th>Status</th><th>Attempts</th><th>Output</th><th>Started</th></tr></thead>
            <tbody>
              {instance.steps?.map((s) => (
                <tr key={s.id}>
                  <td><StatusBadge status={s.status} /></td>
                  <td>{s.attempt_count}</td>
                  <td><code>{s.output ? JSON.stringify(s.output) : s.last_error_message ?? '—'}</code></td>
                  <td>{s.started_at ? new Date(s.started_at).toLocaleString() : '—'}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      </div>
    </div>
  );
}
