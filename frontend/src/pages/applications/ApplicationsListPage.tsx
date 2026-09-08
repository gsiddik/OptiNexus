import { useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { applicationsApi } from '../../api/endpoints';
import { useApiList } from '../../api/useApi';
import { DataTable } from '../../components/DataTable';
import { Pagination } from '../../components/Pagination';
import { StatusBadge } from '../../components/StatusBadge';
import { LoadingSpinner } from '../../components/LoadingSpinner';
import { ErrorAlert } from '../../components/ErrorAlert';
import { Modal } from '../../components/Modal';
import { PermissionGuard } from '../../auth/PermissionGuard';
import { unwrapError } from '../../api/client';
import type { Application } from '../../api/types';

export function ApplicationsListPage() {
  const navigate = useNavigate();
  const [search, setSearch] = useState('');
  const { rows, meta, setPage, loading, error, reload } = useApiList<Application>(
    (p) => applicationsApi.list({ page: p, search: search || undefined }),
    [search],
  );
  const [showCreate, setShowCreate] = useState(false);

  return (
    <div>
      <div className="page-header">
        <h1>Applications</h1>
        <PermissionGuard permission="cgo.application.create">
          <button className="btn btn-primary" onClick={() => setShowCreate(true)}>+ Register Application</button>
        </PermissionGuard>
      </div>
      <div className="toolbar">
        <input placeholder="Search..." value={search} onChange={(e) => setSearch(e.target.value)} />
      </div>
      <ErrorAlert message={error} />
      {loading ? (
        <LoadingSpinner />
      ) : (
        <>
          <DataTable
            rows={rows}
            rowKey={(r) => r.id}
            onRowClick={(r) => navigate(`/applications/${r.id}`)}
            columns={[
              { key: 'code', header: 'Code', render: (r) => r.application_code },
              { key: 'name', header: 'Name', render: (r) => r.name },
              { key: 'owner', header: 'Owner', render: (r) => r.owner ?? '—' },
              { key: 'status', header: 'Status', render: (r) => <StatusBadge status={r.status} /> },
            ]}
          />
          <Pagination meta={meta} onPageChange={setPage} />
        </>
      )}
      <Modal open={showCreate} title="Register Application" onClose={() => setShowCreate(false)}>
        <CreateApplicationForm onCreated={() => { setShowCreate(false); reload(); }} />
      </Modal>
    </div>
  );
}

function CreateApplicationForm({ onCreated }: { onCreated: () => void }) {
  const [form, setForm] = useState({ application_code: '', name: '', description: '', owner: '' });
  const [error, setError] = useState<string | null>(null);
  const [submitting, setSubmitting] = useState(false);

  async function handleSubmit() {
    setSubmitting(true);
    setError(null);
    try {
      await applicationsApi.create(form);
      onCreated();
    } catch (err) {
      setError(unwrapError(err).message);
    } finally {
      setSubmitting(false);
    }
  }

  return (
    <div className="form">
      <ErrorAlert message={error} />
      <label>Application Code<input value={form.application_code} onChange={(e) => setForm({ ...form, application_code: e.target.value })} /></label>
      <label>Name<input value={form.name} onChange={(e) => setForm({ ...form, name: e.target.value })} /></label>
      <label>Owner<input value={form.owner} onChange={(e) => setForm({ ...form, owner: e.target.value })} /></label>
      <label>Description<textarea value={form.description} onChange={(e) => setForm({ ...form, description: e.target.value })} /></label>
      <div className="modal-actions">
        <button className="btn btn-primary" onClick={handleSubmit} disabled={submitting}>Register</button>
      </div>
    </div>
  );
}
