import { DownloadInvoice } from '../../../components/DownloadInvoice';
import { formatDateTime } from '../../../i18n';
import { useEffect } from 'react';
import { Link, useNavigate, useParams } from 'react-router-dom';
import { invoicesApi } from '../../../api/endpoints';
import { useApiResource } from '../../../api/useApi';
import { LoadingSpinner } from '../../../components/LoadingSpinner';
import { ErrorAlert } from '../../../components/ErrorAlert';
import { StatusBadge } from '../../../components/StatusBadge';
import { LifecycleActions } from '../../../components/LifecycleActions';
import { useAuth } from '../../../auth/useAuth';
import type { Invoice } from '../../../api/types';

export function InvoiceDetailPage() {
  const navigate = useNavigate();
  const { id } = useParams<{ id: string }>();
  const { hasPermission } = useAuth();
  const { data: invoice, loading, error, reload } = useApiResource<Invoice>(() => invoicesApi.get(id!), [id]);

  useEffect(() => { const timer = window.setInterval(reload, 30000); return () => window.clearInterval(timer); }, [reload]);

  if (loading) return <LoadingSpinner />;
  if (error) return <ErrorAlert message={error} />;
  if (!invoice) return null;


  return (
    <div>
      <div className="page-header">
        <h1>{invoice.invoice_number}</h1>
        <StatusBadge status={invoice.status} />
      </div>

      <DownloadInvoice id={invoice.id} number={invoice.invoice_number} />
      <LifecycleActions
        status={invoice.status}
        hasPermission={hasPermission}
        onDone={reload}
        actions={[
          { key: 'issue', label: 'Issue', allowedFrom: ['DRAFT'], permission: 'cgo.invoice.issue', action: () => invoicesApi.issue(invoice.id) },
          { key: 'void', label: 'Void', allowedFrom: ['ISSUED', 'OVERDUE'], permission: 'cgo.invoice.void', danger: true, action: () => invoicesApi.void(invoice.id) },
          { key: 'cancel', label: 'Cancel', allowedFrom: ['DRAFT', 'ISSUED', 'OVERDUE'], permission: 'cgo.invoice.cancel', danger: true, action: () => invoicesApi.cancel(invoice.id) },
        ]}
      />

      <div className="detail-grid">
        <div className="card">
          <h3>Details</h3>
          <dl className="definition-list">
            <dt>Billing#</dt><dd>{invoice.billing_number ?? '—'}</dd>
            <dt>Customer</dt><dd>{invoice.customer_name ?? '—'}</dd>
            <dt>Tenant Name</dt><dd>{invoice.tenant_name ?? '—'}</dd>
            <dt>Subscription#</dt><dd>{invoice.subscription_number ?? '—'}</dd>
            <dt>Issue Date</dt><dd>{formatDateTime(invoice.issue_date)}</dd>
            <dt>Due Date</dt><dd>{formatDateTime(invoice.due_date?.slice(0, 10))}</dd>
            <dt>Currency</dt><dd>{invoice.currency}</dd>
            <dt>Subtotal</dt><dd>{invoice.subtotal}</dd>
            <dt>Discount Total</dt><dd>{invoice.discount_total}</dd>
            <dt>Tax Total</dt><dd>{invoice.tax_total}</dd>
            <dt>Adjustment Total</dt><dd>{invoice.adjustment_total}</dd>
            <dt>Total</dt><dd><strong>{invoice.total}</strong></dd>
            <dt>Balance Due</dt><dd><strong>{invoice.balance_due}</strong></dd>
            <dt>Issued At</dt><dd>{formatDateTime(invoice.issued_at)}</dd>
            <dt>Paid At</dt><dd>{formatDateTime(invoice.paid_at)}</dd>
            <dt>Cancelled At</dt><dd>{formatDateTime(invoice.cancelled_at)}</dd>
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
          <h3>Payments</h3><h4>List Recorded Payments</h4>
          {invoice.payments.length === 0 ? (
            <p className="muted">No payments recorded.</p>
          ) : (
            <table className="data-table">
              <thead>
                <tr><th>Payment Date</th><th>Amount</th><th>Payment Method</th><th>Transaction Reference</th><th>Status</th></tr>
              </thead>
              <tbody>
                {invoice.payments.map((p) => (
                  <tr key={p.id} onClick={() => navigate(`/invoice-payments/${p.id}`)} style={{ cursor: 'pointer' }}>
                    <td><Link to={`/invoice-payments/${p.id}`}>{formatDateTime(p.paid_at)}</Link></td>
                    <td>{p.amount}</td>
                    <td>{p.method ?? '—'}</td>
                    <td>{p.reference ?? '—'}</td>
                    <td><StatusBadge status={p.status ?? 'VERIFIED'} /></td>
                  </tr>
                ))}
              </tbody>
            </table>
          )}


        </div>
      </div>
    </div>
  );
}
