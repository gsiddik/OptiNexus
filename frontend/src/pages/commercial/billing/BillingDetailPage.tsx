import { useState } from 'react';
import { useParams, useNavigate } from 'react-router-dom';
import { billingApi, invoicesApi } from '../../../api/endpoints';
import { useApiResource } from '../../../api/useApi';
import { LoadingSpinner } from '../../../components/LoadingSpinner';
import { ErrorAlert } from '../../../components/ErrorAlert';
import { StatusBadge } from '../../../components/StatusBadge';
import { LifecycleActions } from '../../../components/LifecycleActions';
import { PermissionGuard } from '../../../auth/PermissionGuard';
import { useAuth } from '../../../auth/useAuth';
import { unwrapError } from '../../../api/client';
import type { Billing } from '../../../api/types';

const ADJUSTMENT_TYPES = ['DISCOUNT', 'CREDIT', 'DEBIT', 'CORRECTION', 'ROUNDING'];

export function BillingDetailPage() {
  const { id } = useParams<{ id: string }>();
  const navigate = useNavigate();
  const { hasPermission } = useAuth();
  const { data: billing, loading, error, reload } = useApiResource<Billing>(() => billingApi.get(id!), [id]);
  const [genError, setGenError] = useState<string | null>(null);
  const [generating, setGenerating] = useState(false);

  if (loading) return <LoadingSpinner />;
  if (error) return <ErrorAlert message={error} />;
  if (!billing) return null;

  async function generateInvoice() {
    if (!billing) return;
    setGenerating(true);
    setGenError(null);
    try {
      const invoice = await invoicesApi.generateFromBilling(billing.id);
      navigate(`/invoices/${invoice.id}`);
    } catch (err) {
      setGenError(unwrapError(err).message);
    } finally {
      setGenerating(false);
    }
  }

  return (
    <div>
      <div className="page-header">
        <h1>{billing.billing_number}</h1>
        <StatusBadge status={billing.status} />
      </div>

      <LifecycleActions
        status={billing.status}
        hasPermission={hasPermission}
        onDone={reload}
        actions={[
          { key: 'review', label: 'Review', allowedFrom: ['DRAFT', 'CALCULATED'], permission: 'cgo.billing.review', action: () => billingApi.review(billing.id) },
          { key: 'finalize', label: 'Finalize', allowedFrom: ['CALCULATED', 'REVIEWED'], permission: 'cgo.billing.finalize', action: () => billingApi.finalize(billing.id) },
          { key: 'cancel', label: 'Cancel', allowedFrom: ['DRAFT', 'CALCULATED', 'REVIEWED'], permission: 'cgo.billing.cancel', danger: true, action: () => billingApi.cancel(billing.id) },
        ]}
      />

      {billing.status === 'FINALIZED' && (
        <PermissionGuard permission="cgo.invoice.create">
          <div className="card" style={{ marginBottom: '1rem' }}>
            <ErrorAlert message={genError} />
            <button className="btn btn-primary" onClick={generateInvoice} disabled={generating}>Generate Invoice</button>
          </div>
        </PermissionGuard>
      )}

      <div className="detail-grid">
        <div className="card">
          <h3>Details</h3>
          <dl className="definition-list">
            <dt>Customer ID</dt><dd>{billing.customer_id}</dd>
            <dt>Tenant ID</dt><dd>{billing.tenant_id}</dd>
            <dt>Subscription ID</dt><dd>{billing.subscription_id}</dd>
            <dt>Period</dt><dd>{billing.period_start} → {billing.period_end}</dd>
            <dt>Currency</dt><dd>{billing.currency}</dd>
            <dt>Subtotal</dt><dd>{billing.subtotal}</dd>
            <dt>Discount Total</dt><dd>{billing.discount_total}</dd>
            <dt>Tax Total</dt><dd>{billing.tax_total}</dd>
            <dt>Adjustment Total</dt><dd>{billing.adjustment_total}</dd>
            <dt>Total</dt><dd><strong>{billing.total}</strong></dd>
            <dt>Calculated At</dt><dd>{billing.calculated_at ?? '—'}</dd>
            <dt>Finalized At</dt><dd>{billing.finalized_at ?? '—'}</dd>
          </dl>
        </div>

        <div className="card">
          <h3>Items</h3>
          {billing.items.length === 0 ? (
            <p className="muted">No items.</p>
          ) : (
            <table className="data-table">
              <thead>
                <tr><th>Charge Type</th><th>Description</th><th>Qty</th><th>Unit</th><th>Subtotal</th><th>Tax</th><th>Total</th></tr>
              </thead>
              <tbody>
                {billing.items.map((it) => (
                  <tr key={it.id}>
                    <td>{it.charge_type}</td>
                    <td>{it.description}</td>
                    <td>{it.quantity}</td>
                    <td>{it.unit_amount}</td>
                    <td>{it.subtotal}</td>
                    <td>{it.tax_amount}</td>
                    <td>{it.total}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          )}
        </div>

        <div className="card">
          <h3>Adjustments</h3>
          {billing.adjustments.length === 0 ? (
            <p className="muted">No adjustments.</p>
          ) : (
            <table className="data-table">
              <thead>
                <tr><th>Type</th><th>Reason</th><th>Amount</th><th>Status</th></tr>
              </thead>
              <tbody>
                {billing.adjustments.map((a) => (
                  <tr key={a.id}>
                    <td>{a.adjustment_type}</td>
                    <td>{a.reason}</td>
                    <td>{a.amount}</td>
                    <td>{a.status}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          )}
          {billing.status !== 'CANCELLED' && (
            <PermissionGuard permission="cgo.billing.adjust">
              <AddAdjustmentForm billingId={billing.id} onAdded={reload} />
            </PermissionGuard>
          )}
        </div>
      </div>
    </div>
  );
}

function AddAdjustmentForm({ billingId, onAdded }: { billingId: string; onAdded: () => void }) {
  const [form, setForm] = useState({ adjustment_type: 'DISCOUNT', reason: '', amount: '' });
  const [error, setError] = useState<string | null>(null);
  const [submitting, setSubmitting] = useState(false);

  async function submit() {
    setSubmitting(true);
    setError(null);
    try {
      await billingApi.addAdjustment(billingId, form);
      setForm({ adjustment_type: 'DISCOUNT', reason: '', amount: '' });
      onAdded();
    } catch (err) {
      setError(unwrapError(err).message);
    } finally {
      setSubmitting(false);
    }
  }

  return (
    <div className="form form-inline" style={{ marginTop: '1rem' }}>
      <ErrorAlert message={error} />
      <label>
        Type
        <select value={form.adjustment_type} onChange={(e) => setForm({ ...form, adjustment_type: e.target.value })}>
          {ADJUSTMENT_TYPES.map((t) => <option key={t} value={t}>{t}</option>)}
        </select>
      </label>
      <label>Reason<input value={form.reason} onChange={(e) => setForm({ ...form, reason: e.target.value })} /></label>
      <label>Amount<input value={form.amount} onChange={(e) => setForm({ ...form, amount: e.target.value })} /></label>
      <button className="btn btn-secondary" onClick={submit} disabled={submitting || !form.reason || !form.amount}>Add Adjustment</button>
    </div>
  );
}
