import { useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { customersApi } from '../../api/endpoints';
import { useApiList } from '../../api/useApi';
import { DataTable } from '../../components/DataTable';
import { Pagination } from '../../components/Pagination';
import { StatusBadge } from '../../components/StatusBadge';
import { LoadingSpinner } from '../../components/LoadingSpinner';
import { ErrorAlert } from '../../components/ErrorAlert';
import { Modal } from '../../components/Modal';
import { PermissionGuard } from '../../auth/PermissionGuard';
import { unwrapError } from '../../api/client';
import type { Customer } from '../../api/types';

export function CustomersListPage() {
  const navigate = useNavigate();
  const [search, setSearch] = useState('');
  const { rows, meta, setPage, loading, error, reload } = useApiList<Customer>(
    (p) => customersApi.list({ page: p, search: search || undefined }),
    [search],
  );
  const [showCreate, setShowCreate] = useState(false);

  return (
    <div>
      <div className="page-header">
        <h1>Customers</h1>
        <PermissionGuard permission="cgo.customer.create">
          <button className="btn btn-primary" onClick={() => setShowCreate(true)}>+ New Customer</button>
        </PermissionGuard>
      </div>

      <div className="toolbar">
        <input placeholder="Search by name, code, or email..." value={search} onChange={(e) => setSearch(e.target.value)} />
      </div>

      <ErrorAlert message={error} />
      {loading ? (
        <LoadingSpinner />
      ) : (
        <>
          <DataTable
            rows={rows}
            rowKey={(r) => r.id}
            onRowClick={(r) => navigate(`/customers/${r.id}`)}
            columns={[
              { key: 'code', header: 'Code', render: (r) => r.customer_code },
              { key: 'name', header: 'Legal Name', render: (r) => r.legal_name },
              { key: 'email', header: 'Email', render: (r) => r.email ?? '—' },
              { key: 'status', header: 'Status', render: (r) => <StatusBadge status={r.status} /> },
            ]}
          />
          <Pagination meta={meta} onPageChange={setPage} />
        </>
      )}

      <Modal open={showCreate} title="New Customer" onClose={() => setShowCreate(false)}>
        <CreateCustomerForm
          onCreated={() => {
            setShowCreate(false);
            reload();
          }}
        />
      </Modal>
    </div>
  );
}

function CreateCustomerForm({ onCreated }: { onCreated: () => void }) {
  const [form, setForm] = useState({ customer_code: '', legal_name: '', email: '' });
  const [error, setError] = useState<string | null>(null);
  const [submitting, setSubmitting] = useState(false);

  async function handleSubmit() {
    setSubmitting(true);
    setError(null);
    try {
      await customersApi.create(form);
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
        Customer Code
        <input value={form.customer_code} onChange={(e) => setForm({ ...form, customer_code: e.target.value })} />
      </label>
      <label>
        Legal Name
        <input value={form.legal_name} onChange={(e) => setForm({ ...form, legal_name: e.target.value })} />
      </label>
      <label>
        Email
        <input type="email" value={form.email} onChange={(e) => setForm({ ...form, email: e.target.value })} />
      </label>
      <div className="modal-actions">
        <button className="btn btn-primary" onClick={handleSubmit} disabled={submitting}>Create</button>
      </div>
    </div>
  );
}
