import { useState } from 'react';
import { useParams } from 'react-router-dom';
import { approvalRequestsApi } from '../../../api/endpoints';
import { useApiResource } from '../../../api/useApi';
import { LoadingSpinner } from '../../../components/LoadingSpinner';
import { ErrorAlert } from '../../../components/ErrorAlert';
import { StatusBadge } from '../../../components/StatusBadge';
import { ConfirmDialog } from '../../../components/ConfirmDialog';
import { PermissionGuard } from '../../../auth/PermissionGuard';
import { unwrapError } from '../../../api/client';

const OPEN_STATUSES = ['PENDING', 'IN_PROGRESS'];

export function ApprovalRequestDetailPage() {
  const { id } = useParams<{ id: string }>();
  const { data: request, loading, error, reload } = useApiResource(() => approvalRequestsApi.get(id!), [id]);
  const [pendingAction, setPendingAction] = useState<'approve' | 'reject' | 'return' | null>(null);
  const [comment, setComment] = useState('');
  const [actionError, setActionError] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);

  if (loading) return <LoadingSpinner />;
  if (error) return <ErrorAlert message={error} />;
  if (!request) return null;

  const isOpen = OPEN_STATUSES.includes(request.status);

  async function runAction() {
    if (!pendingAction) return;
    setBusy(true);
    setActionError(null);
    try {
      await approvalRequestsApi[pendingAction](request!.id, comment || undefined);
      setComment('');
      reload();
    } catch (err) {
      setActionError(unwrapError(err).message);
    } finally {
      setBusy(false);
      setPendingAction(null);
    }
  }

  return (
    <div>
      <div className="page-header">
        <h1>Approval Request</h1>
        <StatusBadge status={request.status} />
      </div>

      <ErrorAlert message={actionError} />

      {isOpen && (
        <div className="card">
          <h3>Decide</h3>
          <label>Comment (optional)<input value={comment} onChange={(e) => setComment(e.target.value)} /></label>
          <div className="toolbar">
            <PermissionGuard permission="cgo.approval.approve">
              <button className="btn btn-primary" disabled={busy} onClick={() => setPendingAction('approve')}>Approve</button>
            </PermissionGuard>
            <PermissionGuard permission="cgo.approval.reject">
              <button className="btn btn-danger" disabled={busy} onClick={() => setPendingAction('reject')}>Reject</button>
            </PermissionGuard>
            <PermissionGuard permission="cgo.approval.return">
              <button className="btn btn-secondary" disabled={busy} onClick={() => setPendingAction('return')}>Return</button>
            </PermissionGuard>
          </div>
        </div>
      )}

      <div className="detail-grid">
        <div className="card">
          <h3>Details</h3>
          <dl className="definition-list">
            <dt>Request ID</dt><dd><code>{request.id}</code></dd>
            <dt>Definition</dt><dd>{request.approval_definition_id}</dd>
            <dt>Current Level</dt><dd>{request.current_level}</dd>
            <dt>Correlation ID</dt><dd><code>{request.correlation_id ?? '—'}</code></dd>
            <dt>Workflow Instance</dt><dd>{request.workflow_instance_id ?? '—'}</dd>
            <dt>Expires At</dt><dd>{request.expires_at ? new Date(request.expires_at).toLocaleString() : 'Never'}</dd>
          </dl>
        </div>

        <div className="card">
          <h3>Decision History</h3>
          <table className="data-table">
            <thead><tr><th>Level</th><th>Status</th><th>Decision</th><th>Decided At</th></tr></thead>
            <tbody>
              {request.steps?.sort((a, b) => a.level_order - b.level_order).map((s) => (
                <tr key={s.id}>
                  <td>{s.level_order}</td>
                  <td><StatusBadge status={s.status} /></td>
                  <td>{s.decision?.decision ?? '—'}{s.decision?.comment ? `: ${s.decision.comment}` : ''}</td>
                  <td>{s.decision?.decided_at ? new Date(s.decision.decided_at).toLocaleString() : '—'}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      </div>

      <ConfirmDialog
        open={!!pendingAction}
        title={pendingAction ? pendingAction.charAt(0).toUpperCase() + pendingAction.slice(1) : ''}
        message={`Are you sure you want to ${pendingAction} this approval request? Decisions are immutable once recorded.`}
        confirmLabel={pendingAction ?? 'Confirm'}
        danger={pendingAction === 'reject'}
        onConfirm={runAction}
        onCancel={() => setPendingAction(null)}
      />
    </div>
  );
}
