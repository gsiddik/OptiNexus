import { useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { featureFlagsApi } from '../../../api/endpoints';
import { useApiList } from '../../../api/useApi';
import { DataTable } from '../../../components/DataTable';
import { Pagination } from '../../../components/Pagination';
import { StatusBadge } from '../../../components/StatusBadge';
import { LoadingSpinner } from '../../../components/LoadingSpinner';
import { ErrorAlert } from '../../../components/ErrorAlert';
import { Modal } from '../../../components/Modal';
import { PermissionGuard } from '../../../auth/PermissionGuard';
import { unwrapError } from '../../../api/client';
import type { FeatureFlag } from '../../../api/types';

export function FeatureFlagsListPage() {
  const navigate = useNavigate();
  const { rows, meta, setPage, loading, error, reload } = useApiList<FeatureFlag>((p) => featureFlagsApi.list({ page: p }));
  const [showCreate, setShowCreate] = useState(false);

  return (
    <div>
      <div className="page-header">
        <h1>Feature Flags</h1>
        <PermissionGuard permission="cgo.featureflag.create">
          <button className="btn btn-primary" onClick={() => setShowCreate(true)}>+ New Flag</button>
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
            onRowClick={(r) => navigate(`/feature-flags/${r.id}`)}
            columns={[
              { key: 'key', header: 'Flag Key', render: (r) => <code>{r.flag_key}</code> },
              { key: 'name', header: 'Name', render: (r) => r.name },
              { key: 'type', header: 'Type', render: (r) => r.flag_type },
              { key: 'default', header: 'Default', render: (r) => JSON.stringify(r.default_value) },
              { key: 'status', header: 'Status', render: (r) => <StatusBadge status={r.status} /> },
            ]}
          />
          <Pagination meta={meta} onPageChange={setPage} />
        </>
      )}

      <Modal open={showCreate} title="New Feature Flag" onClose={() => setShowCreate(false)}>
        <CreateForm onCreated={() => { setShowCreate(false); reload(); }} />
      </Modal>
    </div>
  );
}

function CreateForm({ onCreated }: { onCreated: () => void }) {
  const [form, setForm] = useState({ flag_key: '', name: '', flag_type: 'BOOLEAN', default_value: 'false' });
  const [error, setError] = useState<string | null>(null);
  const [submitting, setSubmitting] = useState(false);

  async function handleSubmit() {
    setSubmitting(true);
    setError(null);
    try {
      const default_value = JSON.parse(form.default_value);
      await featureFlagsApi.create({ flag_key: form.flag_key, name: form.name, flag_type: form.flag_type, default_value });
      onCreated();
    } catch (err) {
      setError(err instanceof SyntaxError ? 'Default value must be valid JSON (e.g. true, "text", 5).' : unwrapError(err).message);
    } finally {
      setSubmitting(false);
    }
  }

  return (
    <div className="form">
      <ErrorAlert message={error} />
      <label>Flag Key<input value={form.flag_key} onChange={(e) => setForm({ ...form, flag_key: e.target.value })} /></label>
      <label>Name<input value={form.name} onChange={(e) => setForm({ ...form, name: e.target.value })} /></label>
      <label>
        Type
        <select value={form.flag_type} onChange={(e) => setForm({ ...form, flag_type: e.target.value, default_value: e.target.value === 'BOOLEAN' ? 'false' : '""' })}>
          <option value="BOOLEAN">BOOLEAN</option>
          <option value="STRING">STRING</option>
          <option value="NUMBER">NUMBER</option>
          <option value="JSON">JSON</option>
        </select>
      </label>
      <label>Default Value (JSON literal)<input value={form.default_value} onChange={(e) => setForm({ ...form, default_value: e.target.value })} /></label>
      <div className="modal-actions">
        <button className="btn btn-primary" onClick={handleSubmit} disabled={submitting}>Create</button>
      </div>
    </div>
  );
}
