import { useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { usersApi } from '../../api/endpoints';
import { useApiList } from '../../api/useApi';
import { DataTable } from '../../components/DataTable';
import { Pagination } from '../../components/Pagination';
import { StatusBadge } from '../../components/StatusBadge';
import { LoadingSpinner } from '../../components/LoadingSpinner';
import { ErrorAlert } from '../../components/ErrorAlert';
import { Modal } from '../../components/Modal';
import { PermissionGuard } from '../../auth/PermissionGuard';
import { unwrapError } from '../../api/client';
import type { User } from '../../api/types';

export function UsersListPage() {
  const navigate = useNavigate();
  const [search, setSearch] = useState('');
  const { rows, meta, setPage, loading, error, reload } = useApiList<User>(
    (p) => usersApi.list({ page: p, search: search || undefined }),
    [search],
  );
  const [showCreate, setShowCreate] = useState(false);

  return (
    <div>
      <div className="page-header">
        <h1>Users</h1>
        <PermissionGuard permission="cgo.user.create">
          <button className="btn btn-primary" onClick={() => setShowCreate(true)}>+ Invite User</button>
        </PermissionGuard>
      </div>
      <div className="toolbar">
        <input placeholder="Search by name or email..." value={search} onChange={(e) => setSearch(e.target.value)} />
      </div>
      <ErrorAlert message={error} />
      {loading ? (
        <LoadingSpinner />
      ) : (
        <>
          <DataTable
            rows={rows}
            rowKey={(r) => r.id}
            onRowClick={(r) => navigate(`/users/${r.id}`)}
            columns={[
              { key: 'name', header: 'Name', render: (r) => r.name },
              { key: 'email', header: 'Email', render: (r) => r.email },
              { key: 'mfa', header: 'MFA', render: (r) => (r.mfa_enabled ? 'Enabled' : '—') },
              { key: 'status', header: 'Status', render: (r) => <StatusBadge status={r.status} /> },
            ]}
          />
          <Pagination meta={meta} onPageChange={setPage} />
        </>
      )}
      <Modal open={showCreate} title="Invite User" onClose={() => setShowCreate(false)}>
        <CreateUserForm onCreated={() => { setShowCreate(false); reload(); }} />
      </Modal>
    </div>
  );
}

function CreateUserForm({ onCreated }: { onCreated: () => void }) {
  const [form, setForm] = useState({ name: '', email: '' });
  const [error, setError] = useState<string | null>(null);

  async function submit() {
    setError(null);
    try {
      await usersApi.create(form);
      onCreated();
    } catch (err) {
      setError(unwrapError(err).message);
    }
  }

  return (
    <div className="form">
      <ErrorAlert message={error} />
      <label>Name<input value={form.name} onChange={(e) => setForm({ ...form, name: e.target.value })} /></label>
      <label>Email<input type="email" value={form.email} onChange={(e) => setForm({ ...form, email: e.target.value })} /></label>
      <div className="modal-actions">
        <button className="btn btn-primary" onClick={submit}>Invite</button>
      </div>
    </div>
  );
}
