import { useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { eventCatalogApi } from '../../../api/endpoints';
import { useApiList } from '../../../api/useApi';
import { DataTable } from '../../../components/DataTable';
import { Pagination } from '../../../components/Pagination';
import { StatusBadge } from '../../../components/StatusBadge';
import { LoadingSpinner } from '../../../components/LoadingSpinner';
import { ErrorAlert } from '../../../components/ErrorAlert';
import { Modal } from '../../../components/Modal';
import { PermissionGuard } from '../../../auth/PermissionGuard';
import { unwrapError } from '../../../api/client';
import type { EventCatalogEntry } from '../../../api/types';

export function EventCatalogListPage() {
  const navigate = useNavigate();
  const { rows, meta, setPage, loading, error, reload } = useApiList<EventCatalogEntry>((p) => eventCatalogApi.list({ page: p }));
  const [showCreate, setShowCreate] = useState(false);

  return (
    <div>
      <div className="page-header">
        <h1>Event Catalog</h1>
        <PermissionGuard permission="cgo.event.catalog.manage">
          <button className="btn btn-primary" onClick={() => setShowCreate(true)}>+ Register Event</button>
        </PermissionGuard>
      </div>

      <ErrorAlert message={error} />
      {loading ? (
        <LoadingSpinner />
      ) : (
        <>
          <DataTable
            rows={rows}
            rowKey={(r) => r.id}
            onRowClick={(r) => navigate(`/event-catalog/${r.id}`)}
            columns={[
              { key: 'key', header: 'Event Key', render: (r) => <code>{r.event_key}</code> },
              { key: 'name', header: 'Name', render: (r) => r.name },
              { key: 'version', header: 'Schema Version', render: (r) => r.schema_version },
              { key: 'status', header: 'Status', render: (r) => <StatusBadge status={r.status} /> },
            ]}
          />
          <Pagination meta={meta} onPageChange={setPage} />
        </>
      )}

      <Modal open={showCreate} title="Register Event" onClose={() => setShowCreate(false)}>
        <CreateForm onCreated={() => { setShowCreate(false); reload(); }} />
      </Modal>
    </div>
  );
}

function CreateForm({ onCreated }: { onCreated: () => void }) {
  const [form, setForm] = useState({
    event_key: '', name: '', schema_version: '1',
    payload_schema: '{\n  "required": [],\n  "properties": {}\n}',
  });
  const [error, setError] = useState<string | null>(null);
  const [submitting, setSubmitting] = useState(false);

  async function handleSubmit() {
    setSubmitting(true);
    setError(null);
    try {
      const payload_schema = JSON.parse(form.payload_schema);
      await eventCatalogApi.create({ event_key: form.event_key, name: form.name, schema_version: form.schema_version, payload_schema });
      onCreated();
    } catch (err) {
      setError(err instanceof SyntaxError ? 'payload_schema must be valid JSON.' : unwrapError(err).message);
    } finally {
      setSubmitting(false);
    }
  }

  return (
    <div className="form">
      <ErrorAlert message={error} />
      <label>Event Key<input value={form.event_key} onChange={(e) => setForm({ ...form, event_key: e.target.value })} placeholder="e.g. subscription.expiring" /></label>
      <label>Name<input value={form.name} onChange={(e) => setForm({ ...form, name: e.target.value })} /></label>
      <label>Schema Version<input value={form.schema_version} onChange={(e) => setForm({ ...form, schema_version: e.target.value })} /></label>
      <label>Payload Schema (JSON)<textarea rows={8} value={form.payload_schema} onChange={(e) => setForm({ ...form, payload_schema: e.target.value })} /></label>
      <div className="modal-actions">
        <button className="btn btn-primary" onClick={handleSubmit} disabled={submitting}>Register</button>
      </div>
    </div>
  );
}
