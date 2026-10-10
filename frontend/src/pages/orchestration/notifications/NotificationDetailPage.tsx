import { useParams } from 'react-router-dom';
import { notificationsApi } from '../../../api/endpoints';
import { useApiResource } from '../../../api/useApi';
import { LoadingSpinner } from '../../../components/LoadingSpinner';
import { ErrorAlert } from '../../../components/ErrorAlert';
import { StatusBadge } from '../../../components/StatusBadge';
import { LifecycleActions } from '../../../components/LifecycleActions';
import { useAuth } from '../../../auth/useAuth';

export function NotificationDetailPage() {
  const { id } = useParams<{ id: string }>();
  const { hasPermission } = useAuth();
  const { data: notification, loading, error, reload } = useApiResource(() => notificationsApi.get(id!), [id]);

  if (loading) return <LoadingSpinner />;
  if (error) return <ErrorAlert message={error} />;
  if (!notification) return null;

  return (
    <div>
      <div className="page-header">
        <h1>Notification</h1>
        <StatusBadge status={notification.status} />
      </div>

      <LifecycleActions
        status={notification.status}
        hasPermission={hasPermission}
        onDone={reload}
        actions={[
          { key: 'retry', label: 'Retry', allowedFrom: ['FAILED'], permission: 'cgo.notification.delivery.retry', action: () => notificationsApi.retry(notification.id) },
          { key: 'cancel', label: 'Cancel', allowedFrom: ['QUEUED', 'PROCESSING'], permission: 'cgo.notification.delivery.retry', danger: true, action: () => notificationsApi.cancel(notification.id) },
        ]}
      />

      <div className="detail-grid">
        <div className="card">
          <h3>Details</h3>
          <dl className="definition-list">
            <dt>Channel</dt><dd>{notification.channel}</dd>
            <dt>Recipient</dt><dd>{notification.recipient_user_id ?? '—'}</dd>
            <dt>Correlation ID</dt><dd><code>{notification.correlation_id ?? '—'}</code></dd>
            <dt>Attempts</dt><dd>{notification.attempt_count}</dd>
            {notification.last_error_code && <><dt>Last Error</dt><dd>{notification.last_error_code}: {notification.last_error_message}</dd></>}
          </dl>
        </div>

        <div className="card">
          <h3>Rendered Content</h3>
          {notification.subject && <p><strong>Subject:</strong> {notification.subject}</p>}
          <pre className="json-block">{notification.body}</pre>
        </div>

        <div className="card">
          <h3>Delivery Attempts</h3>
          <table className="data-table">
            <thead><tr><th>#</th><th>Status</th><th>Error</th><th>When</th></tr></thead>
            <tbody>
              {notification.deliveries?.map((d) => (
                <tr key={d.id}>
                  <td>{d.attempt_number}</td>
                  <td><StatusBadge status={d.status} /></td>
                  <td>{d.error_message ?? '—'}</td>
                  <td>{new Date(d.created_at).toLocaleString()}</td>
                </tr>
              ))}
              {!notification.deliveries?.length && <tr><td colSpan={4} className="empty-cell">No delivery attempts yet.</td></tr>}
            </tbody>
          </table>
        </div>
      </div>
    </div>
  );
}
