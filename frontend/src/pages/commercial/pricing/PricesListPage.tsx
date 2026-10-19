import { HelpSelect, HelpInput } from '../../../components/FieldControls';
import { useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { pricesApi, plansApi, addonsApi, listAll } from '../../../api/endpoints';
import { useApiList, useApiResource } from '../../../api/useApi';
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
  const targets = useApiResource(async () => {
    const [plans, addons] = await Promise.all([listAll(plansApi.list), listAll(addonsApi.list)]);
    return { plans, addons };
  });
  const [form, setForm] = useState({
    target: '',
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
        ...(form.target.startsWith('plan:') ? { plan_id: form.target.slice(5) } : { addon_id: form.target.slice(6) }),
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
      <ErrorAlert message={error ?? targets.error} />
      <label>Plan ID / Addon ID<HelpSelect help="Select exactly one registered plan or add-on to price. The option group identifies its type." value={form.target} onChange={(e) => setForm({ ...form, target: e.target.value })} disabled={targets.loading}>
        <option value="">Select a plan or add-on...</option>
        <optgroup label="Plans">{targets.data?.plans.map((plan) => <option key={plan.id} value={`plan:${plan.id}`}>{plan.name} ({plan.plan_code})</option>)}</optgroup>
        <optgroup label="Add-ons">{targets.data?.addons.map((addon) => <option key={addon.id} value={`addon:${addon.id}`}>{addon.name} ({addon.addon_code})</option>)}</optgroup>
      </HelpSelect></label>
      <label>
        Price Type
        <HelpSelect help="Choose how charges are calculated: flat, per unit, usage, or tiered. Tiered pricing requires configured tiers." value={form.price_type} onChange={(e) => setForm({ ...form, price_type: e.target.value as PriceType })}>
          {PRICE_TYPES.map((t) => <option key={t} value={t}>{t}</option>)}
        </HelpSelect>
      </label>
      <label>Currency<HelpInput help="Enter a three-letter currency code, for example IDR or USD." value={form.currency} onChange={(e) => setForm({ ...form, currency: e.target.value })} /></label>
      <label>Unit Amount<HelpInput help="Enter the price per unit in the selected currency, using a non-negative number." value={form.unit_amount} onChange={(e) => setForm({ ...form, unit_amount: e.target.value })} /></label>
      <label>Billing Interval<HelpInput help="Choose the recurring billing period, for example MONTHLY or ANNUAL." value={form.billing_interval} onChange={(e) => setForm({ ...form, billing_interval: e.target.value })} /></label>
      <label>Unit Name<HelpInput help="Optional billable unit name, for example user or vehicle." value={form.unit_name} onChange={(e) => setForm({ ...form, unit_name: e.target.value })} /></label>
      <label>Meter Key<HelpInput help="Enter the usage meter key supplying billable quantities, for example vehicles." value={form.meter_key} onChange={(e) => setForm({ ...form, meter_key: e.target.value })} /></label>
      <label>Included Quantity<HelpInput help="Optional quantity included in the base price before additional usage is charged." value={form.included_quantity} onChange={(e) => setForm({ ...form, included_quantity: e.target.value })} /></label>
      <label>Effective From<HelpInput help="Select the date from which this price applies." type="date" value={form.effective_from} onChange={(e) => setForm({ ...form, effective_from: e.target.value })} /></label>
      <div className="modal-actions">
        <button className="btn btn-primary" onClick={handleSubmit} disabled={submitting || targets.loading || !!targets.error || !form.target}>Create</button>
      </div>
    </div>
  );
}
