import { useState } from 'react';
import { useParams } from 'react-router-dom';
import { invoicesApi } from '../../../api/endpoints';
import { useApiResource } from '../../../api/useApi';
import { LoadingSpinner } from '../../../components/LoadingSpinner';
import { ErrorAlert } from '../../../components/ErrorAlert';
import { StatusBadge } from '../../../components/StatusBadge';
import { LifecycleActions } from '../../../components/LifecycleActions';
import { PermissionGuard } from '../../../auth/PermissionGuard';
import { useAuth } from '../../../auth/useAuth';
import { unwrapError } from '../../../api/client';
import type { Invoice } from '../../../api/types';

export function InvoiceDetailPage() {
  const { id } = useParams<{ id: string }>();
  const { hasPermission } = useAuth();
  const { data: invoice, loading, error, reload } = useApiResource<Invoice>(() => invoicesApi.get(id!), [id]);

  if (loading) return <LoadingSpinner />;
  if (error) return <ErrorAlert message={error} />;
  if (!invoice) return null;

  const hasBalance = Number(invoice.balance_due) > 0;

  return (
    <div>
      <div className="page-header">
        <h1>{invoice.invoice_number}</h1>
        <StatusBadge status={invoice.status} />
      </div>

      <LifecycleActions
        status={invoice.status}
        hasPermission={hasPermission}
        onDone={reload}
        actions={[
          { key: 'issue', label: 'Issue', allowedFrom: ['DRAFT'], permission: 'cgo.invoice.issue', action: () => invoicesApi.issue(invoice.id) },
          { key: 'void', label: 'Void', allowedFrom: ['ISSUED', 'OVERDUE'], permission: 'cgo.invoice.void', danger: true, action: () => invoicesApi.void(invoice.id) },
          { key: 'cancel', label: 'Cancel', allowedFrom: ['DRAFT'], permission: 'cgo.invoice.cancel', danger: true, action: () => invoicesApi.cancel(invoice.id) },
        ]}
      />

      <div className="detail-grid">
        <div className="card">
          <h3>Details</h3>
          <dl className="definition-list">
            <dt>Billing ID</dt><dd>{invoice.billing_id}</dd>
            <dt>Customer ID</dt><dd>{invoice.customer_id}</dd>
            <dt>Tenant ID</dt><dd>{invoice.tenant_id}</dd>
            <dt>Subscription ID</dt><dd>{invoice.subscription_id}</dd>
            <dt>Issue Date</dt><dd>{invoice.issue_date ?? '—'}</dd>
            <dt>Due Date</dt><dd>{invoice.due_date}</dd>
            <dt>Currency</dt><dd>{invoice.currency}</dd>
            <dt>Subtotal</dt><dd>{invoice.subtotal}</dd>
            <dt>Discount Total</dt><dd>{invoice.discount_total}</dd>
            <dt>Tax Total</dt><dd>{invoice.tax_total}</dd>
            <dt>Adjustment Total</dt><dd>{invoice.adjustment_total}</dd>
            <dt>Total</dt><dd><strong>{invoice.total}</strong></dd>
            <dt>Balance Due</dt><dd><strong>{invoice.balance_due}</strong></dd>
            <dt>Issued At</dt><dd>{invoice.issued_at ?? '—'}</dd>
            <dt>Paid At</dt><dd>{invoice.paid_at ?? '—'}</dd>
            <dt>Cancelled At</dt><dd>{invoice.cancelled_at ?? '—'}</dd>
          </dl>
        </div>

        <div className="card">
          <h3>Items</h3>
          {invoice.items.length === 0 ? (
            <p className="muted">No items.</p>
          ) : (
            <table className="data-table">
              <thead>
                <tr><th>Description</th><th>Qty</th><th>Unit</th><th>Subtotal</th><th>Tax</th><th>Total</th></tr>
              </thead>
              <tbody>
                {invoice.items.map((it) => (
                  <tr key={it.id}>
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
          <h3>Payments</h3>
          {invoice.payments.length === 0 ? (
            <p className="muted">No payments recorded.</p>
          ) : (
            <table className="data-table">
              <thead>
                <tr><th>Amount</th><th>Paid At</th><th>Method</th><th>Reference</th></tr>
              </thead>
              <tbody>
                {invoice.payments.map((p) => (
                  <tr key={p.id}>
                    <td>{p.amount}</td>
                    <td>{p.paid_at}</td>
                    <td>{p.method ?? '—'}</td>
                    <td>{p.reference ?? '—'}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          )}

          {hasBalance && (
            <PermissionGuard permission="cgo.invoice.payment.record">
              <RecordPaymentForm invoice={invoice} onRecorded={reload} />
            </PermissionGuard>
          )}
        </div>
      </div>
    </div>
  );
}

function RecordPaymentForm({ invoice, onRecorded }: { invoice: Invoice; onRecorded: () => void }) {
  const [form, setForm] = useState({ amount: '', method: '', reference: '' });
  const [error, setError] = useState<string | null>(null);
  const [submitting, setSubmitting] = useState(false);

  async function markFullyPaid() {
    setSubmitting(true);
    setError(null);
    try {
      await invoicesApi.markPaid(invoice.id, { method: form.method || undefined, reference: form.reference || undefined });
      onRecorded();
    } catch (err) {
      setError(unwrapError(err).message);
    } finally {
      setSubmitting(false);
    }
  }

  async function markPartial() {
    setSubmitting(true);
    setError(null);
    try {
      await invoicesApi.markPartiallyPaid(invoice.id, {
        amount: form.amount,
        method: form.method || undefined,
        reference: form.reference || undefined,
      });
      setForm({ amount: '', method: '', reference: '' });
      onRecorded();
    } catch (err) {
      setError(unwrapError(err).message);
    } finally {
      setSubmitting(false);
    }
  }

  return (
    <div className="form" style={{ marginTop: '1rem' }}>
      <h4>Record Payment</h4>
      <ErrorAlert message={error} />
      <label>Amount (for a partial payment)<input value={form.amount} onChange={(e) => setForm({ ...form, amount: e.target.value })} /></label>
      <label>Method<input value={form.method} onChange={(e) => setForm({ ...form, method: e.target.value })} /></label>
      <label>Reference<input value={form.reference} onChange={(e) => setForm({ ...form, reference: e.target.value })} /></label>
      <div className="modal-actions">
        <button className="btn btn-secondary" onClick={markPartial} disabled={submitting || !form.amount}>Record Partial Payment</button>
        <button className="btn btn-primary" onClick={markFullyPaid} disabled={submitting}>Mark Fully Paid</button>
      </div>
    </div>
  );
}
