import { formatDateTime } from '../../../i18n';
import { ActiveSelect } from '../../../components/ActiveSelect';
import { HelpLabel } from '../../../components/FieldControls';
import { useState } from 'react';
import { useParams, Link } from 'react-router-dom';
import { subscriptionsApi } from '../../../api/endpoints';
import { useApiResource } from '../../../api/useApi';
import { LoadingSpinner } from '../../../components/LoadingSpinner';
import { ErrorAlert } from '../../../components/ErrorAlert';
import { StatusBadge } from '../../../components/StatusBadge';
import { LifecycleActions } from '../../../components/LifecycleActions';
import { PermissionGuard } from '../../../auth/PermissionGuard';
import { useAuth } from '../../../auth/useAuth';
import { unwrapError } from '../../../api/client';
import type { Subscription } from '../../../api/types';

const ACTIVE_LIKE = ['TRIAL', 'ACTIVE', 'PAST_DUE', 'GRACE_PERIOD', 'SUSPENDED'];
const ALL_STATUSES = ['DRAFT', 'PENDING', 'TRIAL', 'ACTIVE', 'PAST_DUE', 'GRACE_PERIOD', 'SUSPENDED', 'EXPIRED', 'CANCELLED', 'TERMINATED'];
const TERMINATE_FROM = ALL_STATUSES.filter((s) => !['TERMINATED', 'CANCELLED'].includes(s));

export function SubscriptionDetailPage() {
  const { id } = useParams<{ id: string }>();
  const { hasPermission } = useAuth();
  const { data: subscription, loading, error, reload } = useApiResource<Subscription>(() => subscriptionsApi.get(id!), [id]);

  if (loading) return <LoadingSpinner />;
  if (error) return <ErrorAlert message={error} />;
  if (!subscription) return null;

  return (
    <div>
      <div className="page-header">
        <h1>{subscription.subscription_number}</h1>
        <StatusBadge status={subscription.status} />
      </div>

      <LifecycleActions
        status={subscription.status}
        hasPermission={hasPermission}
        onDone={reload}
        actions={[
          { key: 'start-trial', label: 'Start Trial', allowedFrom: ['DRAFT'], permission: 'cgo.subscription.activate', action: () => subscriptionsApi.startTrial(subscription.id) },
          { key: 'activate', label: 'Activate', allowedFrom: ['DRAFT', 'TRIAL', 'SUSPENDED'], permission: 'cgo.subscription.activate', action: () => subscriptionsApi.activate(subscription.id) },
          { key: 'renew', label: 'Renew', allowedFrom: ['EXPIRED'], permission: 'cgo.subscription.renew', action: () => subscriptionsApi.renew(subscription.id) },
          { key: 'reactivate', label: 'Reactivate', allowedFrom: ['SUSPENDED'], permission: 'cgo.subscription.reactivate', action: () => subscriptionsApi.reactivate(subscription.id) },
          { key: 'cancel', label: 'Cancel', allowedFrom: ACTIVE_LIKE, permission: 'cgo.subscription.cancel', danger: true, action: () => subscriptionsApi.cancel(subscription.id) },
          { key: 'terminate', label: 'Terminate', allowedFrom: TERMINATE_FROM, permission: 'cgo.subscription.terminate', danger: true, action: () => subscriptionsApi.terminate(subscription.id) },
        ]}
      />

      <div className="detail-grid">
        <div className="card">
          <h3>Details</h3>
          <dl className="definition-list">
            <dt>Customer</dt><dd>{subscription.customer_name ?? '—'}</dd>
            <dt>Tenant Name</dt><dd><Link to={`/tenants/${subscription.tenant_id}/entitlements`}>{subscription.tenant_name ?? '—'}</Link></dd>
            <dt>Product Name</dt><dd>{subscription.product_name ?? '—'}</dd>
            <dt>Plan Name</dt><dd>{subscription.plan_name ?? '—'}</dd>
            <dt>Currency</dt><dd>{subscription.currency}</dd>
            <dt>Billing Interval</dt><dd>{subscription.billing_interval}</dd>
            <dt>Auto Renew</dt><dd>{subscription.auto_renew ? 'Yes' : 'No'}</dd>
            <dt>Start</dt><dd>{formatDateTime(subscription.start_at)}</dd>
            <dt>Current Period</dt><dd>{formatDateTime(subscription.current_period_start)} &rarr; {formatDateTime(subscription.current_period_end)}</dd>
            <dt>Trial End</dt><dd>{formatDateTime(subscription.trial_end_at)}</dd>
            <dt>Grace Period (Days)</dt><dd>{subscription.grace_period_days ?? 0}</dd>
            <dt>Grace End</dt><dd>{formatDateTime(subscription.grace_end_at)}</dd>
            <dt>Cancel At</dt><dd>{formatDateTime(subscription.cancel_at)}</dd>
            <dt>Cancelled At</dt><dd>{formatDateTime(subscription.cancelled_at)}</dd>
          </dl>
        </div>

        <div className="card">
          <h3>Items</h3>
          {subscription.items.length === 0 ? (
            <p className="muted">No items.</p>
          ) : (
            <table className="data-table">
              <thead>
                <tr><th>Type</th><th>Plan/Addon</th><th>Quantity</th><th>Unit Price</th><th>Total Price</th></tr>
              </thead>
              <tbody>
                {subscription.items.map((it) => (
                  <tr key={it.id}>
                    <td>{it.price_type ?? it.item_type}</td>
                    <td>{it.plan_name ?? it.addon_name ?? '—'}</td>
                    <td>{it.quantity}</td>
                    <td>{it.unit_price ?? '—'}</td><td>{it.total_price ?? '—'}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          )}
        </div>

        <div className="card">
          <h3>Upgrade / Downgrade Plan</h3>
          <PlanChangeForm subscriptionId={subscription.id} onDone={reload} hasPermission={hasPermission} />
        </div>
      </div>
    </div>
  );
}

function PlanChangeForm({ subscriptionId, onDone, hasPermission }: { subscriptionId: string; onDone: () => void; hasPermission: (k: string) => boolean }) {
  const [planId, setPlanId] = useState('');
  const [error, setError] = useState<string | null>(null);
  const [submitting, setSubmitting] = useState(false);

  async function change(direction: 'upgrade' | 'downgrade') {
    setSubmitting(true);
    setError(null);
    try {
      if (direction === 'upgrade') {
        await subscriptionsApi.upgrade(subscriptionId, planId);
      } else {
        await subscriptionsApi.downgrade(subscriptionId, planId);
      }
      setPlanId('');
      onDone();
    } catch (err) {
      setError(unwrapError(err).message);
    } finally {
      setSubmitting(false);
    }
  }

  return (
    <div className="form form-inline">
      <ErrorAlert message={error} />
      <HelpLabel>New Plan ID<ActiveSelect kind="plan" value={planId} onChange={(e) => setPlanId(e.target.value)} /></HelpLabel>
      <PermissionGuard permission="cgo.subscription.upgrade">
        <button className="btn btn-secondary" disabled={submitting || !planId} onClick={() => change('upgrade')}>Upgrade</button>
      </PermissionGuard>
      <PermissionGuard permission="cgo.subscription.downgrade">
        <button className="btn btn-secondary" disabled={submitting || !planId} onClick={() => change('downgrade')}>Downgrade</button>
      </PermissionGuard>
      {!hasPermission('cgo.subscription.upgrade') && !hasPermission('cgo.subscription.downgrade') && (
        <p className="muted">You do not have permission to change this subscription's plan.</p>
      )}
    </div>
  );
}
