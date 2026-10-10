import { Link, useParams } from 'react-router-dom';
import { eventCatalogApi } from '../../../api/endpoints';
import { useApiResource } from '../../../api/useApi';
import { LoadingSpinner } from '../../../components/LoadingSpinner';
import { ErrorAlert } from '../../../components/ErrorAlert';
import { StatusBadge } from '../../../components/StatusBadge';

export function EventCatalogDetailPage() {
  const { id } = useParams<{ id: string }>();
  const { data: entry, loading, error } = useApiResource(() => eventCatalogApi.get(id!), [id]);

  if (loading) return <LoadingSpinner />;
  if (error) return <ErrorAlert message={error} />;
  if (!entry) return null;

  return (
    <div>
      <div className="page-header">
        <h1>{entry.name}</h1>
        <StatusBadge status={entry.status} />
      </div>

      <div className="detail-grid">
        <div className="card">
          <h3>Details</h3>
          <dl className="definition-list">
            <dt>Event Key</dt><dd><code>{entry.event_key}</code></dd>
            <dt>Schema Version</dt><dd>{entry.schema_version}</dd>
            <dt>Description</dt><dd>{entry.description ?? '—'}</dd>
          </dl>
        </div>
        <div className="card">
          <h3>Payload Schema</h3>
          <pre className="json-block">{JSON.stringify(entry.payload_schema, null, 2)}</pre>
        </div>
      </div>

      <Link to="/event-deliveries" className="btn btn-ghost">View Event Deliveries</Link>
    </div>
  );
}
