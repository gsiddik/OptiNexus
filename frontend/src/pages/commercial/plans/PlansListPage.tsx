import { useState } from 'react';
import { useNavigate, useSearchParams } from 'react-router-dom';
import { plansApi } from '../../../api/endpoints';
import { useApiList } from '../../../api/useApi';
import { DataTable } from '../../../components/DataTable';
import { Pagination } from '../../../components/Pagination';
import { StatusBadge } from '../../../components/StatusBadge';
import { LoadingSpinner } from '../../../components/LoadingSpinner';
import { ErrorAlert } from '../../../components/ErrorAlert';
import { Modal } from '../../../components/Modal';
import { PermissionGuard } from '../../../auth/PermissionGuard';
import { unwrapError } from '../../../api/client';
import type { Plan } from '../../../api/types';

export function PlansListPage() {
  const navigate = useNavigate();
  const [searchParams] = useSearchParams();
  const productId = searchParams.get('product_id') ?? undefined;
  const { rows, meta, setPage, loading, error, reload } = useApiList<Plan>(
    (p) => plansApi.list({ page: p, product_id: productId }),
    [productId],
  );
  const [showCreate, setShowCreate] = useState(false);

  return (
    <div>
      <div className="page-header">
        <h1>Plans{productId ? ` (product ${productId})` : ''}</h1>
        <PermissionGuard permission="cgo.plan.create">
          <button className="btn btn-primary" onClick={() => setShowCreate(true)}>+ New Plan</button>
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
            onRowClick={(r) => navigate(`/plans/${r.id}`)}
            columns={[
              { key: 'code', header: 'Code', render: (r) => r.plan_code },
              { key: 'name', header: 'Name', render: (r) => r.name },
              { key: 'product', header: 'Product', render: (r) => r.product_id },
              { key: 'interval', header: 'Billing Interval', render: (r) => r.billing_interval },
              { key: 'status', header: 'Status', render: (r) => <StatusBadge status={r.status} /> },
            ]}
          />
          <Pagination meta={meta} onPageChange={setPage} />
        </>
      )}

      <Modal open={showCreate} title="New Plan" onClose={() => setShowCreate(false)}>
        <CreatePlanForm onCreated={() => { setShowCreate(false); reload(); }} defaultProductId={productId} />
      </Modal>
    </div>
  );
}

function CreatePlanForm({ onCreated, defaultProductId }: { onCreated: () => void; defaultProductId?: string }) {
  const [form, setForm] = useState({
    product_id: defaultProductId ?? '',
    plan_code: '',
    name: '',
    billing_interval: 'MONTHLY',
    currency: 'IDR',
    trial_days: '',
  });
  const [error, setError] = useState<string | null>(null);
  const [submitting, setSubmitting] = useState(false);

  async function handleSubmit() {
    setSubmitting(true);
    setError(null);
    try {
      await plansApi.create({
        product_id: form.product_id,
        plan_code: form.plan_code,
        name: form.name,
        billing_interval: form.billing_interval as Plan['billing_interval'],
        currency: form.currency,
        trial_days: form.trial_days ? Number(form.trial_days) : null,
      });
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
      <label>Product ID<input value={form.product_id} onChange={(e) => setForm({ ...form, product_id: e.target.value })} /></label>
      <label>Plan Code<input value={form.plan_code} onChange={(e) => setForm({ ...form, plan_code: e.target.value })} /></label>
      <label>Name<input value={form.name} onChange={(e) => setForm({ ...form, name: e.target.value })} /></label>
      <label>
        Billing Interval
        <select value={form.billing_interval} onChange={(e) => setForm({ ...form, billing_interval: e.target.value })}>
          <option value="MONTHLY">MONTHLY</option>
          <option value="QUARTERLY">QUARTERLY</option>
          <option value="SEMI_ANNUAL">SEMI_ANNUAL</option>
          <option value="ANNUAL">ANNUAL</option>
          <option value="CUSTOM">CUSTOM</option>
        </select>
      </label>
      <label>Currency<input value={form.currency} onChange={(e) => setForm({ ...form, currency: e.target.value })} /></label>
      <label>Trial Days<input type="number" value={form.trial_days} onChange={(e) => setForm({ ...form, trial_days: e.target.value })} /></label>
      <div className="modal-actions">
        <button className="btn btn-primary" onClick={handleSubmit} disabled={submitting}>Create</button>
      </div>
    </div>
  );
}
