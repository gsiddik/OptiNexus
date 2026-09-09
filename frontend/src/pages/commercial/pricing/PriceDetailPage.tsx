import { useState } from 'react';
import { useParams, Link } from 'react-router-dom';
import { pricesApi } from '../../../api/endpoints';
import { useApiResource } from '../../../api/useApi';
import { LoadingSpinner } from '../../../components/LoadingSpinner';
import { ErrorAlert } from '../../../components/ErrorAlert';
import { StatusBadge } from '../../../components/StatusBadge';
import { LifecycleActions } from '../../../components/LifecycleActions';
import { PermissionGuard } from '../../../auth/PermissionGuard';
import { useAuth } from '../../../auth/useAuth';
import { unwrapError } from '../../../api/client';
import type { Price } from '../../../api/types';

export function PriceDetailPage() {
  const { id } = useParams<{ id: string }>();
  const { hasPermission } = useAuth();
  const { data: price, loading, error, reload } = useApiResource<Price>(() => pricesApi.get(id!), [id]);
  const [approveError, setApproveError] = useState<string | null>(null);
  const [approving, setApproving] = useState(false);

  if (loading) return <LoadingSpinner />;
  if (error) return <ErrorAlert message={error} />;
  if (!price) return null;

  async function approve() {
    if (!price) return;
    setApproving(true);
    setApproveError(null);
    try {
      await pricesApi.activate(price.id);
      reload();
    } catch (err) {
      setApproveError(unwrapError(err).message);
    } finally {
      setApproving(false);
    }
  }

  return (
    <div>
      <Link to="/prices" className="back-link">&larr; Back to Pricing</Link>
      <div className="page-header">
        <h1>{price.price_type} Price</h1>
        <StatusBadge status={price.status} />
        <StatusBadge status={price.approval_status} />
        {price.tenant_id && <span className="badge badge-info">Tenant Override</span>}
      </div>

      <ErrorAlert message={approveError} />

      {price.approval_status === 'PENDING_APPROVAL' && (
        <PermissionGuard permission="cgo.pricing.activate">
          <div className="card" style={{ marginBottom: '1rem' }}>
            <h3>Approve This Price</h3>
            <p className="muted">
              Approving activates this pending override. The approver must be a different user than
              whoever created the price — the backend enforces this and returns a 403 UNAUTHORIZED if violated.
            </p>
            <button className="btn btn-primary" onClick={approve} disabled={approving}>Approve Price</button>
          </div>
        </PermissionGuard>
      )}

      <LifecycleActions
        status={price.status}
        hasPermission={hasPermission}
        onDone={reload}
        actions={[
          { key: 'retire', label: 'Retire', allowedFrom: ['ACTIVE'], permission: 'cgo.pricing.retire', danger: true, action: () => pricesApi.retire(price.id) },
        ]}
      />

      <div className="detail-grid">
        <div className="card">
          <h3>Details</h3>
          <dl className="definition-list">
            <dt>Plan ID</dt><dd>{price.plan_id ?? '—'}</dd>
            <dt>Addon ID</dt><dd>{price.addon_id ?? '—'}</dd>
            <dt>Tenant ID (override)</dt><dd>{price.tenant_id ?? '—'}</dd>
            <dt>Currency</dt><dd>{price.currency}</dd>
            <dt>Unit Amount</dt><dd>{price.unit_amount ?? '—'}</dd>
            <dt>Billing Interval</dt><dd>{price.billing_interval}</dd>
            <dt>Unit Name</dt><dd>{price.unit_name ?? '—'}</dd>
            <dt>Meter Key</dt><dd>{price.meter_key ?? '—'}</dd>
            <dt>Minimum Quantity</dt><dd>{price.minimum_quantity ?? '—'}</dd>
            <dt>Included Quantity</dt><dd>{price.included_quantity ?? '—'}</dd>
            <dt>Tax Code ID</dt><dd>{price.tax_code_id ?? '—'}</dd>
            <dt>Effective From</dt><dd>{price.effective_from}</dd>
            <dt>Effective Until</dt><dd>{price.effective_until ?? '—'}</dd>
            <dt>Created By</dt><dd>{price.created_by ?? '—'}</dd>
            <dt>Approved By</dt><dd>{price.approved_by ?? '—'}</dd>
          </dl>
        </div>

        {price.tiers && price.tiers.length > 0 && (
          <div className="card">
            <h3>Pricing Tiers</h3>
            <table className="data-table">
              <thead>
                <tr><th>Order</th><th>From</th><th>To</th><th>Unit Amount</th><th>Flat Amount</th></tr>
              </thead>
              <tbody>
                {price.tiers.map((t) => (
                  <tr key={t.id}>
                    <td>{t.tier_order}</td>
                    <td>{t.from_quantity}</td>
                    <td>{t.to_quantity ?? '—'}</td>
                    <td>{t.unit_amount}</td>
                    <td>{t.flat_amount ?? '—'}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </div>
    </div>
  );
}
