import { useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { subscriptionsApi } from '../../../api/endpoints';
import { useApiList } from '../../../api/useApi';
import { DataTable } from '../../../components/DataTable';
import { Pagination } from '../../../components/Pagination';
import { StatusBadge } from '../../../components/StatusBadge';
import { LoadingSpinner } from '../../../components/LoadingSpinner';
import { ErrorAlert } from '../../../components/ErrorAlert';
import { Modal } from '../../../components/Modal';
import { PermissionGuard } from '../../../auth/PermissionGuard';
import { unwrapError } from '../../../api/client';
import type { Subscription } from '../../../api/types';

export function SubscriptionsListPage() {
  const navigate = useNavigate();
  const { rows, meta, setPage, loading, error, reload } = useApiList<Subscription>((p) => subscriptionsApi.list({ page: p }));
  const [showCreate, setShowCreate] = useState(false);

  return (
    <div>
      <div className="page-header">
        <h1>Subscriptions</h1>
        <PermissionGuard permission="cgo.subscription.create">
          <button className="btn btn-primary" onClick={() => setShowCreate(true)}>+ New Subscription</button>
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
            onRowClick={(r) => navigate(`/subscriptions/${r.id}`)}
            columns={[
              { key: 'number', header: 'Subscription #', render: (r) => r.subscription_number },
              { key: 'tenant', header: 'Tenant', render: (r) => r.tenant_id },
              { key: 'plan', header: 'Plan', render: (r) => r.plan_id },
              { key: 'status', header: 'Status', render: (r) => <StatusBadge status={r.status} /> },
              { key: 'period_end', header: 'Current Period End', render: (r) => r.current_period_end ?? '—' },
            ]}
          />
          <Pagination meta={meta} onPageChange={setPage} />
        </>
      )}

      <Modal open={showCreate} title="New Subscription" onClose={() => setShowCreate(false)}>
        <CreateSubscriptionForm onCreated={() => { setShowCreate(false); reload(); }} />
      </Modal>
    </div>
  );
}

function CreateSubscriptionForm({ onCreated }: { onCreated: () => void }) {
  const [form, setForm] = useState({ customer_id: '', tenant_id: '', plan_id: '', idempotency_key: '', auto_renew: true });
  const [error, setError] = useState<string | null>(null);
  const [submitting, setSubmitting] = useState(false);

  async function handleSubmit() {
    setSubmitting(true);
    setError(null);
    try {
      await subscriptionsApi.create({
        customer_id: form.customer_id,
        tenant_id: form.tenant_id,
        plan_id: form.plan_id,
        idempotency_key: form.idempotency_key || undefined,
        auto_renew: form.auto_renew,
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
      <label>Customer ID<input value={form.customer_id} onChange={(e) => setForm({ ...form, customer_id: e.target.value })} /></label>
      <label>Tenant ID<input value={form.tenant_id} onChange={(e) => setForm({ ...form, tenant_id: e.target.value })} /></label>
      <label>Plan ID<input value={form.plan_id} onChange={(e) => setForm({ ...form, plan_id: e.target.value })} /></label>
      <label>Idempotency Key (optional)<input value={form.idempotency_key} onChange={(e) => setForm({ ...form, idempotency_key: e.target.value })} /></label>
      <label>
        <input type="checkbox" checked={form.auto_renew} onChange={(e) => setForm({ ...form, auto_renew: e.target.checked })} /> Auto Renew
      </label>
      <div className="modal-actions">
        <button className="btn btn-primary" onClick={handleSubmit} disabled={submitting}>Create</button>
      </div>
    </div>
  );
}
