import { useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { pricesApi } from '../../../api/endpoints';
import { useApiList } from '../../../api/useApi';
import { DataTable } from '../../../components/DataTable';
import { Pagination } from '../../../components/Pagination';
import { StatusBadge } from '../../../components/StatusBadge';
import { LoadingSpinner } from '../../../components/LoadingSpinner';
import { ErrorAlert } from '../../../components/ErrorAlert';
import { Modal } from '../../../components/Modal';
import { PermissionGuard } from '../../../auth/PermissionGuard';
import { unwrapError } from '../../../api/client';
import type { Price, PriceType } from '../../../api/types';

const PRICE_TYPES: PriceType[] = ['FLAT', 'PER_USER', 'PER_DEVICE', 'PER_VEHICLE', 'PER_TRANSACTION', 'PER_API_CALL', 'USAGE', 'TIERED'];

export function PricesListPage() {
  const navigate = useNavigate();
  const { rows, meta, setPage, loading, error, reload } = useApiList<Price>((p) => pricesApi.list({ page: p }));
  const [showCreate, setShowCreate] = useState(false);

  return (
    <div>
      <div className="page-header">
        <h1>Pricing</h1>
        <PermissionGuard permission="cgo.pricing.create">
          <button className="btn btn-primary" onClick={() => setShowCreate(true)}>+ New Price</button>
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
            onRowClick={(r) => navigate(`/prices/${r.id}`)}
            columns={[
              { key: 'type', header: 'Type', render: (r) => r.price_type },
              { key: 'target', header: 'Plan / Addon', render: (r) => r.plan_id ?? r.addon_id ?? '—' },
              { key: 'override', header: 'Override', render: (r) => (r.tenant_id ? <span className="badge badge-info">Override</span> : '—') },
              { key: 'amount', header: 'Unit Amount', render: (r) => r.unit_amount ?? '—' },
              { key: 'status', header: 'Status', render: (r) => <StatusBadge status={r.status} /> },
              { key: 'approval', header: 'Approval', render: (r) => <StatusBadge status={r.approval_status} /> },
              { key: 'effective', header: 'Effective From', render: (r) => r.effective_from },
            ]}
          />
          <Pagination meta={meta} onPageChange={setPage} />
        </>
      )}

      <Modal open={showCreate} title="New Price" onClose={() => setShowCreate(false)}>
        <CreatePriceForm onCreated={() => { setShowCreate(false); reload(); }} />
      </Modal>
    </div>
  );
}

function CreatePriceForm({ onCreated }: { onCreated: () => void }) {
  const [form, setForm] = useState({
    plan_id: '',
    addon_id: '',
    price_type: 'FLAT' as PriceType,
    currency: 'IDR',
    unit_amount: '',
    billing_interval: 'MONTHLY',
    unit_name: '',
    meter_key: '',
    included_quantity: '',
    effective_from: new Date().toISOString().slice(0, 10),
  });
  const [error, setError] = useState<string | null>(null);
  const [submitting, setSubmitting] = useState(false);

  async function handleSubmit() {
    setSubmitting(true);
    setError(null);
    try {
      await pricesApi.create({
        plan_id: form.plan_id || null,
        addon_id: form.addon_id || null,
        price_type: form.price_type,
        currency: form.currency,
        unit_amount: form.unit_amount || null,
        billing_interval: form.billing_interval,
        unit_name: form.unit_name || null,
        meter_key: form.meter_key || null,
        included_quantity: form.included_quantity || null,
        effective_from: form.effective_from,
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
      <label>Plan ID (either plan or addon)<input value={form.plan_id} onChange={(e) => setForm({ ...form, plan_id: e.target.value })} /></label>
      <label>Addon ID<input value={form.addon_id} onChange={(e) => setForm({ ...form, addon_id: e.target.value })} /></label>
      <label>
        Price Type
        <select value={form.price_type} onChange={(e) => setForm({ ...form, price_type: e.target.value as PriceType })}>
          {PRICE_TYPES.map((t) => <option key={t} value={t}>{t}</option>)}
        </select>
      </label>
      <label>Currency<input value={form.currency} onChange={(e) => setForm({ ...form, currency: e.target.value })} /></label>
      <label>Unit Amount<input value={form.unit_amount} onChange={(e) => setForm({ ...form, unit_amount: e.target.value })} /></label>
      <label>Billing Interval<input value={form.billing_interval} onChange={(e) => setForm({ ...form, billing_interval: e.target.value })} /></label>
      <label>Unit Name<input value={form.unit_name} onChange={(e) => setForm({ ...form, unit_name: e.target.value })} /></label>
      <label>Meter Key<input value={form.meter_key} onChange={(e) => setForm({ ...form, meter_key: e.target.value })} /></label>
      <label>Included Quantity<input value={form.included_quantity} onChange={(e) => setForm({ ...form, included_quantity: e.target.value })} /></label>
      <label>Effective From<input type="date" value={form.effective_from} onChange={(e) => setForm({ ...form, effective_from: e.target.value })} /></label>
      <div className="modal-actions">
        <button className="btn btn-primary" onClick={handleSubmit} disabled={submitting}>Create</button>
      </div>
    </div>
  );
}
