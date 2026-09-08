import { useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { rolesApi } from '../../api/endpoints';
import { useApiList } from '../../api/useApi';
import { DataTable } from '../../components/DataTable';
import { Pagination } from '../../components/Pagination';
import { StatusBadge } from '../../components/StatusBadge';
import { LoadingSpinner } from '../../components/LoadingSpinner';
import { ErrorAlert } from '../../components/ErrorAlert';
import { Modal } from '../../components/Modal';
import { PermissionGuard } from '../../auth/PermissionGuard';
import { unwrapError } from '../../api/client';
import type { Role } from '../../api/types';

export function RolesListPage() {
  const navigate = useNavigate();
  const { rows, meta, setPage, loading, error, reload } = useApiList<Role>((p) => rolesApi.list({ page: p }));
  const [showCreate, setShowCreate] = useState(false);

  return (
    <div>
      <div className="page-header">
        <h1>Roles</h1>
        <PermissionGuard permission="cgo.role.create">
          <button className="btn btn-primary" onClick={() => setShowCreate(true)}>+ New Role</button>
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
            onRowClick={(r) => navigate(`/roles/${r.id}`)}
            columns={[
              { key: 'name', header: 'Name', render: (r) => r.name },
              { key: 'code', header: 'Code', render: (r) => <code>{r.code}</code> },
              { key: 'type', header: 'Type', render: (r) => r.role_type },
              { key: 'system', header: 'System', render: (r) => (r.is_system ? 'Yes' : 'No') },
              { key: 'permissions', header: 'Permissions', render: (r) => r.permissions_count ?? '—' },
              { key: 'status', header: 'Status', render: (r) => <StatusBadge status={r.status} /> },
            ]}
          />
          <Pagination meta={meta} onPageChange={setPage} />
        </>
      )}
      <Modal open={showCreate} title="New Role" onClose={() => setShowCreate(false)}>
        <CreateRoleForm onCreated={() => { setShowCreate(false); reload(); }} />
      </Modal>
    </div>
  );
}

function CreateRoleForm({ onCreated }: { onCreated: () => void }) {
  const [form, setForm] = useState({ name: '', code: '', role_type: 'TENANT', tenant_id: '', application_id: '' });
  const [error, setError] = useState<string | null>(null);

  async function submit() {
    setError(null);
    try {
      await rolesApi.create({
        name: form.name,
        code: form.code,
        role_type: form.role_type as Role['role_type'],
        tenant_id: form.role_type === 'TENANT' ? form.tenant_id : null,
        application_id: form.role_type === 'APPLICATION' ? form.application_id : null,
      });
      onCreated();
    } catch (err) {
      setError(unwrapError(err).message);
    }
  }

  return (
    <div className="form">
      <ErrorAlert message={error} />
      <label>Name<input value={form.name} onChange={(e) => setForm({ ...form, name: e.target.value })} /></label>
      <label>Code<input value={form.code} onChange={(e) => setForm({ ...form, code: e.target.value })} /></label>
      <label>
        Type
        <select value={form.role_type} onChange={(e) => setForm({ ...form, role_type: e.target.value })}>
          <option value="TENANT">TENANT</option>
          <option value="APPLICATION">APPLICATION</option>
          <option value="SYSTEM">SYSTEM</option>
        </select>
      </label>
      {form.role_type === 'TENANT' && (
        <label>Tenant ID<input value={form.tenant_id} onChange={(e) => setForm({ ...form, tenant_id: e.target.value })} /></label>
      )}
      {form.role_type === 'APPLICATION' && (
        <label>Application ID<input value={form.application_id} onChange={(e) => setForm({ ...form, application_id: e.target.value })} /></label>
      )}
      <div className="modal-actions">
        <button className="btn btn-primary" onClick={submit}>Create</button>
      </div>
    </div>
  );
}
