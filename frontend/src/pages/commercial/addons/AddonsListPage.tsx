import { useState } from 'react';
import { useNavigate, useSearchParams } from 'react-router-dom';
import { addonsApi } from '../../../api/endpoints';
import { useApiList } from '../../../api/useApi';
import { DataTable } from '../../../components/DataTable';
import { Pagination } from '../../../components/Pagination';
import { StatusBadge } from '../../../components/StatusBadge';
import { LoadingSpinner } from '../../../components/LoadingSpinner';
import { ErrorAlert } from '../../../components/ErrorAlert';
import { Modal } from '../../../components/Modal';
import { PermissionGuard } from '../../../auth/PermissionGuard';
import { unwrapError } from '../../../api/client';
import type { Addon } from '../../../api/types';

export function AddonsListPage() {
  const navigate = useNavigate();
  const [searchParams] = useSearchParams();
  const productId = searchParams.get('product_id') ?? undefined;
  const { rows, meta, setPage, loading, error, reload } = useApiList<Addon>(
    (p) => addonsApi.list({ page: p, product_id: productId }),
    [productId],
  );
  const [showCreate, setShowCreate] = useState(false);

  return (
    <div>
      <div className="page-header">
        <h1>Add-ons</h1>
        <PermissionGuard permission="cgo.addon.create">
          <button className="btn btn-primary" onClick={() => setShowCreate(true)}>+ New Add-on</button>
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
            onRowClick={(r) => navigate(`/addons/${r.id}`)}
            columns={[
              { key: 'code', header: 'Code', render: (r) => r.addon_code },
              { key: 'name', header: 'Name', render: (r) => r.name },
              { key: 'product', header: 'Product', render: (r) => r.product_id ?? '—' },
              { key: 'status', header: 'Status', render: (r) => <StatusBadge status={r.status} /> },
            ]}
          />
          <Pagination meta={meta} onPageChange={setPage} />
        </>
      )}

      <Modal open={showCreate} title="New Add-on" onClose={() => setShowCreate(false)}>
        <CreateAddonForm onCreated={() => { setShowCreate(false); reload(); }} defaultProductId={productId} />
      </Modal>
    </div>
  );
}

function CreateAddonForm({ onCreated, defaultProductId }: { onCreated: () => void; defaultProductId?: string }) {
  const [form, setForm] = useState({ product_id: defaultProductId ?? '', addon_code: '', name: '' });
  const [error, setError] = useState<string | null>(null);
  const [submitting, setSubmitting] = useState(false);

  async function handleSubmit() {
    setSubmitting(true);
    setError(null);
    try {
      await addonsApi.create({ product_id: form.product_id || null, addon_code: form.addon_code, name: form.name });
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
      <label>Product ID (optional)<input value={form.product_id} onChange={(e) => setForm({ ...form, product_id: e.target.value })} /></label>
      <label>Addon Code<input value={form.addon_code} onChange={(e) => setForm({ ...form, addon_code: e.target.value })} /></label>
      <label>Name<input value={form.name} onChange={(e) => setForm({ ...form, name: e.target.value })} /></label>
      <div className="modal-actions">
        <button className="btn btn-primary" onClick={handleSubmit} disabled={submitting}>Create</button>
      </div>
    </div>
  );
}
