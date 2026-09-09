import { Link, useParams } from 'react-router-dom';
import { notificationRulesApi } from '../../../api/endpoints';
import { useApiResource } from '../../../api/useApi';
import { LoadingSpinner } from '../../../components/LoadingSpinner';
import { ErrorAlert } from '../../../components/ErrorAlert';
import { StatusBadge } from '../../../components/StatusBadge';
import { PermissionGuard } from '../../../auth/PermissionGuard';
import { unwrapError } from '../../../api/client';
import { useState } from 'react';

export function NotificationRuleDetailPage() {
  const { id } = useParams<{ id: string }>();
  const { data: rule, loading, error, reload } = useApiResource(() => notificationRulesApi.get(id!), [id]);
  const [statusError, setStatusError] = useState<string | null>(null);

  if (loading) return <LoadingSpinner />;
  if (error) return <ErrorAlert message={error} />;
  if (!rule) return null;

  async function setStatus(status: string) {
    setStatusError(null);
    try {
      await notificationRulesApi.update(rule!.id, { status });
      reload();
    } catch (err) {
      setStatusError(unwrapError(err).message);
    }
  }

  return (
    <div>
      <Link to="/notification-rules" className="back-link">&larr; Back to Rules</Link>
      <div className="page-header">
        <h1>{rule.name}</h1>
        <StatusBadge status={rule.status} />
      </div>

      <ErrorAlert message={statusError} />
      <div className="toolbar">
        <PermissionGuard permission="cgo.notification.rule.manage">
          {rule.status !== 'ACTIVE' && <button className="btn btn-primary" onClick={() => setStatus('ACTIVE')}>Activate</button>}
          {rule.status === 'ACTIVE' && <button className="btn btn-secondary" onClick={() => setStatus('INACTIVE')}>Deactivate</button>}
        </PermissionGuard>
      </div>

      <div className="card">
        <h3>Details</h3>
        <dl className="definition-list">
          <dt>Rule Code</dt><dd>{rule.rule_code}</dd>
          <dt>Trigger Event</dt><dd>{rule.trigger_event_key ?? 'Manual/programmatic only'}</dd>
          <dt>Condition</dt><dd><code>{rule.condition ? JSON.stringify(rule.condition) : 'None (always matches)'}</code></dd>
          <dt>Recipient Type</dt><dd>{rule.recipient_type}</dd>
          <dt>Recipient Reference</dt><dd>{rule.recipient_reference ?? '—'}</dd>
          <dt>Channel</dt><dd>{rule.channel}</dd>
          <dt>Tenant</dt><dd>{rule.tenant_id ?? 'Global'}</dd>
        </dl>
      </div>
    </div>
  );
}
