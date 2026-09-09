import { useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { integrationsApi, applicationsApi } from '../../../api/endpoints';
import { useApiList, useApiResource } from '../../../api/useApi';
import { DataTable } from '../../../components/DataTable';
import { Pagination } from '../../../components/Pagination';
import { StatusBadge } from '../../../components/StatusBadge';
import { LoadingSpinner } from '../../../components/LoadingSpinner';
import { ErrorAlert } from '../../../components/ErrorAlert';
import { Modal } from '../../../components/Modal';
import { PermissionGuard } from '../../../auth/PermissionGuard';
import { unwrapError } from '../../../api/client';
import type { Application, Integration } from '../../../api/types';

export function IntegrationsListPage() {
  const navigate = useNavigate();
  const { rows, meta, setPage, loading, error, reload } = useApiList<Integration>((p) => integrationsApi.list({ page: p }));
  const [showCreate, setShowCreate] = useState(false);

  return (
    <div>
      <div className="page-header">
        <h1>Integrations</h1>
        <PermissionGuard permission="cgo.integration.create">
          <button className="btn btn-primary" onClick={() => setShowCreate(true)}>+ New Integration</button>
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
            onRowClick={(r) => navigate(`/integrations/${r.id}`)}
            columns={[
              { key: 'code', header: 'Code', render: (r) => r.integration_code },
              { key: 'name', header: 'Name', render: (r) => r.name },
              { key: 'type', header: 'Type', render: (r) => r.integration_type },
              { key: 'url', header: 'Base URL', render: (r) => r.base_url ?? '—' },
              { key: 'status', header: 'Status', render: (r) => <StatusBadge status={r.status} /> },
            ]}
          />
          <Pagination meta={meta} onPageChange={setPage} />
        </>
      )}

      <Modal open={showCreate} title="New Integration" onClose={() => setShowCreate(false)}>
        <CreateForm onCreated={(id) => { setShowCreate(false); navigate(`/integrations/${id}`); reload(); }} />
      </Modal>
    </div>
  );
}

function CreateForm({ onCreated }: { onCreated: (id: string) => void }) {
  const { data: applications } = useApiResource(() => applicationsApi.list({ per_page: 100 }).then((e) => e.data), []);
  const [form, setForm] = useState({ integration_code: '', name: '', source_application_id: '', integration_type: 'REST', base_url: '', timeout_seconds: 30 });
  const [error, setError] = useState<string | null>(null);
  const [submitting, setSubmitting] = useState(false);

  async function handleSubmit() {
    setSubmitting(true);
    setError(null);
    try {
      const result = await integrationsApi.create({ ...form, timeout_seconds: Number(form.timeout_seconds) });
      onCreated(result.id);
    } catch (err) {
      setError(unwrapError(err).message);
    } finally {
      setSubmitting(false);
    }
  }

  return (
    <div className="form">
      <ErrorAlert message={error} />
      <label>Integration Code<input value={form.integration_code} onChange={(e) => setForm({ ...form, integration_code: e.target.value })} /></label>
      <label>Name<input value={form.name} onChange={(e) => setForm({ ...form, name: e.target.value })} /></label>
      <label>
        Source Application
        <select value={form.source_application_id} onChange={(e) => setForm({ ...form, source_application_id: e.target.value })}>
          <option value="">Select application...</option>
          {((applications as Application[] | null) ?? []).map((a) => <option key={a.id} value={a.id}>{a.name}</option>)}
        </select>
      </label>
      <label>
        Type
        <select value={form.integration_type} onChange={(e) => setForm({ ...form, integration_type: e.target.value })}>
          <option value="REST">REST</option>
          <option value="WEBHOOK">WEBHOOK</option>
          <option value="EVENT">EVENT</option>
          <option value="INTERNAL">INTERNAL</option>
        </select>
      </label>
      <label>Base URL<input value={form.base_url} onChange={(e) => setForm({ ...form, base_url: e.target.value })} placeholder="https://..." /></label>
      <label>Timeout (seconds)<input type="number" value={form.timeout_seconds} onChange={(e) => setForm({ ...form, timeout_seconds: Number(e.target.value) })} /></label>
      <div className="modal-actions">
        <button className="btn btn-primary" onClick={handleSubmit} disabled={submitting || !form.source_application_id}>Create</button>
      </div>
    </div>
  );
}
