import { useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { customersApi, tenantsApi } from '../../api/endpoints';
import { useApiList, useApiResource } from '../../api/useApi';
import { DataTable } from '../../components/DataTable';
import { Pagination } from '../../components/Pagination';
import { StatusBadge } from '../../components/StatusBadge';
import { LoadingSpinner } from '../../components/LoadingSpinner';
import { ErrorAlert } from '../../components/ErrorAlert';
import { Modal } from '../../components/Modal';
import { PermissionGuard } from '../../auth/PermissionGuard';
import { unwrapError } from '../../api/client';
import type { Tenant, Customer } from '../../api/types';

export function TenantsListPage() {
  const navigate = useNavigate();
  const [search, setSearch] = useState('');
  const { rows, meta, setPage, loading, error, reload } = useApiList<Tenant>(
    (p) => tenantsApi.list({ page: p, search: search || undefined }),
    [search],
  );
  const [showCreate, setShowCreate] = useState(false);

  return (
    <div>
      <div className="page-header">
        <h1>Tenants</h1>
        <PermissionGuard permission="cgo.tenant.create">
          <button className="btn btn-primary" onClick={() => setShowCreate(true)}>+ New Tenant</button>
        </PermissionGuard>
      </div>
      <div className="toolbar">
        <input placeholder="Search by name or code..." value={search} onChange={(e) => setSearch(e.target.value)} />
      </div>
      <ErrorAlert message={error} />
      {loading ? (
        <LoadingSpinner />
      ) : (
        <>
          <DataTable
            rows={rows}
            rowKey={(r) => r.id}
            onRowClick={(r) => navigate(`/tenants/${r.id}`)}
            columns={[
              { key: 'code', header: 'Code', render: (r) => r.tenant_code },
              { key: 'name', header: 'Name', render: (r) => r.name },
              { key: 'region', header: 'Region', render: (r) => r.region ?? '—' },
              { key: 'status', header: 'Status', render: (r) => <StatusBadge status={r.status} /> },
            ]}
          />
          <Pagination meta={meta} onPageChange={setPage} />
        </>
      )}
      <Modal open={showCreate} title="New Tenant" onClose={() => setShowCreate(false)}>
        <CreateTenantForm onCreated={() => { setShowCreate(false); reload(); }} />
      </Modal>
    </div>
  );
}

function CreateTenantForm({ onCreated }: { onCreated: () => void }) {
  const { data: customersEnvelope } = useApiResource(() => customersApi.list({ per_page: 100 }).then((e) => e.data), []);
  const [form, setForm] = useState({ tenant_code: '', customer_id: '', name: '', region: '' });
  const [error, setError] = useState<string | null>(null);
  const [submitting, setSubmitting] = useState(false);

  async function handleSubmit() {
    setSubmitting(true);
    setError(null);
    try {
      await tenantsApi.create(form);
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
      <label>
        Tenant Code
        <input value={form.tenant_code} onChange={(e) => setForm({ ...form, tenant_code: e.target.value })} />
      </label>
      <label>
        Customer
        <select value={form.customer_id} onChange={(e) => setForm({ ...form, customer_id: e.target.value })}>
          <option value="">Select a customer...</option>
          {(customersEnvelope as Customer[] | null)?.map((c) => (
            <option key={c.id} value={c.id}>{c.legal_name}</option>
          ))}
        </select>
      </label>
      <label>
        Name
        <input value={form.name} onChange={(e) => setForm({ ...form, name: e.target.value })} />
      </label>
      <label>
        Region
        <input value={form.region} onChange={(e) => setForm({ ...form, region: e.target.value })} />
      </label>
      <div className="modal-actions">
        <button className="btn btn-primary" onClick={handleSubmit} disabled={submitting}>Create</button>
      </div>
    </div>
  );
}
