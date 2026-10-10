import { useState } from 'react';
import { applicationsApi, serviceAccountsApi } from '../../api/endpoints';
import { useApiList, useApiResource } from '../../api/useApi';
import { DataTable } from '../../components/DataTable';
import { Pagination } from '../../components/Pagination';
import { StatusBadge } from '../../components/StatusBadge';
import { LoadingSpinner } from '../../components/LoadingSpinner';
import { ErrorAlert } from '../../components/ErrorAlert';
import { Modal } from '../../components/Modal';
import { PermissionGuard } from '../../auth/PermissionGuard';
import { unwrapError } from '../../api/client';
import type { Application, ServiceAccount } from '../../api/types';

export function ServiceAccountsPage() {
  const { rows, meta, setPage, loading, error, reload } = useApiList<ServiceAccount>((p) => serviceAccountsApi.list({ page: p }));
  const [showCreate, setShowCreate] = useState(false);
  const [newSecret, setNewSecret] = useState<{ client_id: string; client_secret: string } | null>(null);
  const [actionError, setActionError] = useState<string | null>(null);

  async function revoke(id: string) {
    setActionError(null);
    try {
      await serviceAccountsApi.revoke(id);
      reload();
    } catch (err) {
      setActionError(unwrapError(err).message);
    }
  }

  async function rotate(id: string) {
    setActionError(null);
    try {
      const result = await serviceAccountsApi.rotateSecret(id);
      setNewSecret(result);
    } catch (err) {
      setActionError(unwrapError(err).message);
    }
  }

  return (
    <div>
      <div className="page-header">
        <h1>Service Accounts</h1>
        <PermissionGuard permission="cgo.service_account.manage">
          <button className="btn btn-primary" onClick={() => setShowCreate(true)}>+ New Service Account</button>
        </PermissionGuard>
      </div>
      <p className="muted">OAuth2 client-credentials identities used by integrated applications (OptiFleet, OptiEntry, VMS, Taxi Management, ...) to call CGO's machine-to-machine APIs.</p>
      <ErrorAlert message={error ?? actionError} />
      {loading ? (
        <LoadingSpinner />
      ) : (
        <>
          <DataTable
            rows={rows}
            rowKey={(r) => r.id}
            columns={[
              { key: 'name', header: 'Name', render: (r) => r.name },
              { key: 'client', header: 'Client ID', render: (r) => <code>{r.client_id}</code> },
              { key: 'status', header: 'Status', render: (r) => <StatusBadge status={r.status} /> },
              { key: 'last_used', header: 'Last Used', render: (r) => (r.last_used_at ? new Date(r.last_used_at).toLocaleString() : 'Never') },
              {
                key: 'actions', header: '', render: (r) => r.status === 'ACTIVE' && (
                  <PermissionGuard permission="cgo.service_account.manage">
                    <button className="btn btn-ghost" onClick={() => rotate(r.id)}>Rotate Secret</button>
                    <button className="btn btn-ghost" onClick={() => revoke(r.id)}>Revoke</button>
                  </PermissionGuard>
                ),
              },
            ]}
          />
          <Pagination meta={meta} onPageChange={setPage} />
        </>
      )}

      <Modal open={showCreate} title="New Service Account" onClose={() => setShowCreate(false)}>
        <CreateServiceAccountForm
          onCreated={(secret) => {
            setShowCreate(false);
            setNewSecret(secret);
            reload();
          }}
        />
      </Modal>

      <Modal open={!!newSecret} title="Client Secret (shown once)" onClose={() => setNewSecret(null)}>
        {newSecret && (
          <div>
            <p className="alert alert-warning">Copy this secret now — it cannot be retrieved again.</p>
            <dl className="definition-list">
              <dt>Client ID</dt><dd><code>{newSecret.client_id}</code></dd>
              <dt>Client Secret</dt><dd><code>{newSecret.client_secret}</code></dd>
            </dl>
          </div>
        )}
      </Modal>
    </div>
  );
}

function CreateServiceAccountForm({ onCreated }: { onCreated: (secret: { client_id: string; client_secret: string }) => void }) {
  const { data: apps } = useApiResource(() => applicationsApi.list({ per_page: 100 }).then((e) => e.data), []);
  const [form, setForm] = useState({ name: '', application_id: '' });
  const [error, setError] = useState<string | null>(null);

  async function submit() {
    setError(null);
    try {
      const result = await serviceAccountsApi.create({ name: form.name, application_id: form.application_id || undefined });
      onCreated({ client_id: result.client_id, client_secret: result.client_secret });
    } catch (err) {
      setError(unwrapError(err).message);
    }
  }

  return (
    <div className="form">
      <ErrorAlert message={error} />
      <label>Name<input value={form.name} onChange={(e) => setForm({ ...form, name: e.target.value })} /></label>
      <label>
        Application
        <select value={form.application_id} onChange={(e) => setForm({ ...form, application_id: e.target.value })}>
          <option value="">(none)</option>
          {(apps as Application[] | null)?.map((a) => <option key={a.id} value={a.id}>{a.name}</option>)}
        </select>
      </label>
      <div className="modal-actions">
        <button className="btn btn-primary" onClick={submit}>Create</button>
      </div>
    </div>
  );
}
