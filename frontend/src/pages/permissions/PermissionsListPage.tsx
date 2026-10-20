import { HelpLabel, HelpSelect, HelpInput } from '../../components/FieldControls';
import { useState } from 'react';
import { applicationsApi, permissionsApi } from '../../api/endpoints';
import { useApiList, useApiResource } from '../../api/useApi';
import { DataTable } from '../../components/DataTable';
import { Pagination } from '../../components/Pagination';
import { StatusBadge } from '../../components/StatusBadge';
import { LoadingSpinner } from '../../components/LoadingSpinner';
import { ErrorAlert } from '../../components/ErrorAlert';
import { Modal } from '../../components/Modal';
import { PermissionGuard } from '../../auth/PermissionGuard';
import { useAuth } from '../../auth/useAuth';
import { unwrapError } from '../../api/client';
import type { Application, Permission } from '../../api/types';

export function PermissionsListPage() {
  const [applicationId, setApplicationId] = useState('');
  const { rows, meta, setPage, loading, error, reload } = useApiList<Permission>(
    (p) => permissionsApi.list({ page: p, application_id: applicationId || undefined }),
    [applicationId],
  );
  const { data: apps } = useApiResource(() => applicationsApi.list({ per_page: 100 }).then((e) => e.data), []);
  const [showCreate, setShowCreate] = useState(false);
  const { hasPermission } = useAuth();

  return (
    <div>
      <div className="page-header">
        <h1>Permissions</h1>
        <PermissionGuard permission="cgo.permission.create">
          <button className="btn btn-primary" onClick={() => setShowCreate(true)}>+ Register Permission</button>
        </PermissionGuard>
      </div>
      <div className="toolbar">
        <HelpSelect help="Select the registered application to which this configuration applies." value={applicationId} onChange={(e) => setApplicationId(e.target.value)}>
          <option value="">All applications</option>
          {(apps as Application[] | null)?.map((a) => <option key={a.id} value={a.id}>{a.name}</option>)}
        </HelpSelect>
      </div>
      <ErrorAlert message={error} />
      {loading ? (
        <LoadingSpinner />
      ) : (
        <>
          <DataTable
            rows={rows}
            rowKey={(r) => r.id}
            columns={[
              { key: 'key', header: 'Permission Key', render: (r) => <code>{r.permission_key}</code> },
              { key: 'name', header: 'Name', render: (r) => r.name },
              { key: 'status', header: 'Status', render: (r) => <StatusBadge status={r.status} /> },
              {
                key: 'actions', header: '', render: (r) => (
                  r.status === 'ACTIVE' && hasPermission('cgo.permission.update') ? (
                    <button className="btn btn-ghost" onClick={async () => { await permissionsApi.deprecate(r.id); reload(); }}>Deprecate</button>
                  ) : null
                ),
              },
            ]}
          />
          <Pagination meta={meta} onPageChange={setPage} />
        </>
      )}
      <Modal open={showCreate} title="Register Permission" onClose={() => setShowCreate(false)}>
        <CreatePermissionForm apps={(apps as Application[]) ?? []} onCreated={() => { setShowCreate(false); reload(); }} />
      </Modal>
    </div>
  );
}

function CreatePermissionForm({ apps, onCreated }: { apps: Application[]; onCreated: () => void }) {
  const [form, setForm] = useState({ application_id: '', permission_key: '', name: '' });
  const [error, setError] = useState<string | null>(null);

  async function submit() {
    setError(null);
    try {
      await permissionsApi.create(form);
      onCreated();
    } catch (err) {
      setError(unwrapError(err).message);
    }
  }

  return (
    <div className="form">
      <ErrorAlert message={error} />
      <HelpLabel>
        Application
        <HelpSelect help="Select the registered application to which this configuration applies." value={form.application_id} onChange={(e) => setForm({ ...form, application_id: e.target.value })}>
          <option value="">Select...</option>
          {apps.map((a) => <option key={a.id} value={a.id}>{a.name}</option>)}
        </HelpSelect>
      </HelpLabel>
      <HelpLabel>
        Permission Key
        <HelpInput help="Enter a unique permission key, for example optifleet.vehicle.update." placeholder="e.g. optifleet.vehicle.update" value={form.permission_key} onChange={(e) => setForm({ ...form, permission_key: e.target.value })} />
      </HelpLabel>
      <HelpLabel>
        Name
        <HelpInput help="Enter a readable name used to identify this record in lists and selections." value={form.name} onChange={(e) => setForm({ ...form, name: e.target.value })} />
      </HelpLabel>
      <div className="modal-actions">
        <button className="btn btn-primary" onClick={submit}>Register</button>
      </div>
    </div>
  );
}
