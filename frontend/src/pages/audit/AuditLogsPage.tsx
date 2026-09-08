import { useState } from 'react';
import { auditApi } from '../../api/endpoints';
import { useApiList } from '../../api/useApi';
import { DataTable } from '../../components/DataTable';
import { Pagination } from '../../components/Pagination';
import { LoadingSpinner } from '../../components/LoadingSpinner';
import { ErrorAlert } from '../../components/ErrorAlert';
import { Modal } from '../../components/Modal';
import type { AuditLog } from '../../api/types';

export function AuditLogsPage() {
  const [action, setAction] = useState('');
  const [resourceType, setResourceType] = useState('');
  const { rows, meta, setPage, loading, error } = useApiList<AuditLog>(
    (p) => auditApi.list({ page: p, action: action || undefined, resource_type: resourceType || undefined }),
    [action, resourceType],
  );
  const [selected, setSelected] = useState<AuditLog | null>(null);

  return (
    <div>
      <div className="page-header">
        <h1>Audit Logs</h1>
      </div>
      <p className="muted">Immutable history of governance-critical events. No update or delete path exists for this data.</p>
      <div className="toolbar">
        <input placeholder="Filter by action (e.g. tenant.status_changed)" value={action} onChange={(e) => setAction(e.target.value)} />
        <input placeholder="Filter by resource type (e.g. Tenant)" value={resourceType} onChange={(e) => setResourceType(e.target.value)} />
      </div>
      <ErrorAlert message={error} />
      {loading ? (
        <LoadingSpinner />
      ) : (
        <>
          <DataTable
            rows={rows}
            rowKey={(r) => r.id}
            onRowClick={setSelected}
            columns={[
              { key: 'action', header: 'Action', render: (r) => r.action },
              { key: 'resource', header: 'Resource', render: (r) => `${r.resource_type ?? '—'} ${r.resource_id ?? ''}` },
              { key: 'actor', header: 'Actor', render: (r) => r.actor_identity ?? r.actor_user_id ?? 'system' },
              { key: 'source', header: 'Source', render: (r) => r.source ?? 'cgo' },
              { key: 'when', header: 'When', render: (r) => new Date(r.created_at).toLocaleString() },
            ]}
          />
          <Pagination meta={meta} onPageChange={setPage} />
        </>
      )}

      <Modal open={!!selected} title="Audit Event" onClose={() => setSelected(null)}>
        {selected && (
          <dl className="definition-list">
            <dt>Action</dt><dd>{selected.action}</dd>
            <dt>Resource</dt><dd>{selected.resource_type} {selected.resource_id}</dd>
            <dt>Tenant</dt><dd>{selected.tenant_id ?? '—'}</dd>
            <dt>Application</dt><dd>{selected.application_id ?? '—'}</dd>
            <dt>Actor</dt><dd>{selected.actor_identity ?? '—'}</dd>
            <dt>IP Address</dt><dd>{selected.ip_address ?? '—'}</dd>
            <dt>Request ID</dt><dd>{selected.request_id ?? '—'}</dd>
            <dt>Old Value</dt><dd><pre>{JSON.stringify(selected.old_value, null, 2)}</pre></dd>
            <dt>New Value</dt><dd><pre>{JSON.stringify(selected.new_value, null, 2)}</pre></dd>
          </dl>
        )}
      </Modal>
    </div>
  );
}
