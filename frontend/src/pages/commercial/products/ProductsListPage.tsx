import { useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { productsApi } from '../../../api/endpoints';
import { useApiList } from '../../../api/useApi';
import { DataTable } from '../../../components/DataTable';
import { Pagination } from '../../../components/Pagination';
import { StatusBadge } from '../../../components/StatusBadge';
import { LoadingSpinner } from '../../../components/LoadingSpinner';
import { ErrorAlert } from '../../../components/ErrorAlert';
import { Modal } from '../../../components/Modal';
import { PermissionGuard } from '../../../auth/PermissionGuard';
import { unwrapError } from '../../../api/client';
import type { Product } from '../../../api/types';

export function ProductsListPage() {
  const navigate = useNavigate();
  const { rows, meta, setPage, loading, error, reload } = useApiList<Product>((p) => productsApi.list({ page: p }));
  const [showCreate, setShowCreate] = useState(false);

  return (
    <div>
      <div className="page-header">
        <h1>Products</h1>
        <PermissionGuard permission="cgo.product.create">
          <button className="btn btn-primary" onClick={() => setShowCreate(true)}>+ New Product</button>
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
            onRowClick={(r) => navigate(`/products/${r.id}`)}
            columns={[
              { key: 'code', header: 'Code', render: (r) => r.product_code },
              { key: 'name', header: 'Name', render: (r) => r.name },
              { key: 'status', header: 'Status', render: (r) => <StatusBadge status={r.status} /> },
              { key: 'currency', header: 'Currency', render: (r) => r.currency ?? '—' },
            ]}
          />
          <Pagination meta={meta} onPageChange={setPage} />
        </>
      )}

      <Modal open={showCreate} title="New Product" onClose={() => setShowCreate(false)}>
        <CreateProductForm onCreated={() => { setShowCreate(false); reload(); }} />
      </Modal>
    </div>
  );
}

function CreateProductForm({ onCreated }: { onCreated: () => void }) {
  const [form, setForm] = useState({ product_code: '', name: '', currency: 'IDR' });
  const [error, setError] = useState<string | null>(null);
  const [submitting, setSubmitting] = useState(false);

  async function handleSubmit() {
    setSubmitting(true);
    setError(null);
    try {
      await productsApi.create(form);
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
        Product Code
        <input value={form.product_code} onChange={(e) => setForm({ ...form, product_code: e.target.value })} />
      </label>
      <label>
        Name
        <input value={form.name} onChange={(e) => setForm({ ...form, name: e.target.value })} />
      </label>
      <label>
        Currency
        <input value={form.currency} onChange={(e) => setForm({ ...form, currency: e.target.value })} />
      </label>
      <div className="modal-actions">
        <button className="btn btn-primary" onClick={handleSubmit} disabled={submitting}>Create</button>
      </div>
    </div>
  );
}
