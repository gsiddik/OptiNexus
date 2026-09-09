import { useState } from 'react';
import { useParams, useNavigate, Link } from 'react-router-dom';
import { plansApi } from '../../../api/endpoints';
import { useApiResource } from '../../../api/useApi';
import { LoadingSpinner } from '../../../components/LoadingSpinner';
import { ErrorAlert } from '../../../components/ErrorAlert';
import { StatusBadge } from '../../../components/StatusBadge';
import { LifecycleActions } from '../../../components/LifecycleActions';
import { Modal } from '../../../components/Modal';
import { PermissionGuard } from '../../../auth/PermissionGuard';
import { useAuth } from '../../../auth/useAuth';
import { unwrapError } from '../../../api/client';
import type { Plan, PlanLimit } from '../../../api/types';

export function PlanDetailPage() {
  const { id } = useParams<{ id: string }>();
  const { hasPermission } = useAuth();
  const { data: plan, loading, error, reload } = useApiResource<Plan>(() => plansApi.get(id!), [id]);
  const { data: limits, reload: reloadLimits } = useApiResource<PlanLimit[]>(() => plansApi.limits(id!), [id]);
  const [showClone, setShowClone] = useState(false);

  if (loading) return <LoadingSpinner />;
  if (error) return <ErrorAlert message={error} />;
  if (!plan) return null;

  return (
    <div>
      <Link to="/plans" className="back-link">&larr; Back to Plans</Link>
      <div className="page-header">
        <h1>{plan.name}</h1>
        <StatusBadge status={plan.status} />
      </div>

      <div className="lifecycle-actions">
        <LifecycleActions
          status={plan.status}
          hasPermission={hasPermission}
          onDone={reload}
          actions={[
            { key: 'activate', label: 'Activate', allowedFrom: ['DRAFT', 'INACTIVE'], permission: 'cgo.plan.activate', action: () => plansApi.activate(plan.id) },
            { key: 'deactivate', label: 'Deactivate', allowedFrom: ['ACTIVE'], permission: 'cgo.plan.deactivate', action: () => plansApi.deactivate(plan.id) },
            { key: 'retire', label: 'Retire', allowedFrom: ['ACTIVE', 'INACTIVE'], permission: 'cgo.plan.retire', danger: true, action: () => plansApi.retire(plan.id) },
          ]}
        />
        <PermissionGuard permission="cgo.plan.clone">
          <button className="btn btn-secondary" onClick={() => setShowClone(true)}>Clone Plan</button>
        </PermissionGuard>
      </div>

      <div className="detail-grid">
        <div className="card">
          <h3>Details</h3>
          <dl className="definition-list">
            <dt>Plan Code</dt><dd>{plan.plan_code}</dd>
            <dt>Product ID</dt><dd>{plan.product_id}</dd>
            <dt>Description</dt><dd>{plan.description ?? '—'}</dd>
            <dt>Billing Interval</dt><dd>{plan.billing_interval}</dd>
            <dt>Currency</dt><dd>{plan.currency}</dd>
            <dt>Trial Days</dt><dd>{plan.trial_days ?? '—'}</dd>
            <dt>Capabilities</dt><dd>{plan.capabilities_count ?? '—'}</dd>
          </dl>
        </div>

        <div className="card">
          <h3>Limits</h3>
          {(limits ?? []).length === 0 ? (
            <p className="muted">No limits configured.</p>
          ) : (
            <table className="data-table">
              <thead>
                <tr><th>Key</th><th>Value</th><th>Unlimited</th><th>Unit</th></tr>
              </thead>
              <tbody>
                {limits!.map((l) => (
                  <tr key={l.id}>
                    <td>{l.limit_key}</td>
                    <td>{l.limit_value ?? '—'}</td>
                    <td>{l.is_unlimited ? 'Yes' : 'No'}</td>
                    <td>{l.unit ?? '—'}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          )}
          <PermissionGuard permission="cgo.plan.limit.configure">
            <AddLimitForm planId={plan.id} onAdded={reloadLimits} />
          </PermissionGuard>
        </div>
      </div>

      <Modal open={showClone} title="Clone Plan" onClose={() => setShowClone(false)}>
        <ClonePlanForm planId={plan.id} onCloned={() => setShowClone(false)} />
      </Modal>
    </div>
  );
}

function AddLimitForm({ planId, onAdded }: { planId: string; onAdded: () => void }) {
  const [form, setForm] = useState({ limit_key: '', limit_value: '', is_unlimited: false, unit: '' });
  const [error, setError] = useState<string | null>(null);
  const [submitting, setSubmitting] = useState(false);

  async function submit() {
    setSubmitting(true);
    setError(null);
    try {
      await plansApi.addLimit(planId, {
        limit_key: form.limit_key,
        limit_value: form.is_unlimited ? null : form.limit_value || null,
        is_unlimited: form.is_unlimited,
        unit: form.unit || null,
      });
      setForm({ limit_key: '', limit_value: '', is_unlimited: false, unit: '' });
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
      <label>Key<input value={form.limit_key} onChange={(e) => setForm({ ...form, limit_key: e.target.value })} /></label>
      <label>Value<input value={form.limit_value} disabled={form.is_unlimited} onChange={(e) => setForm({ ...form, limit_value: e.target.value })} /></label>
      <label>
        <input type="checkbox" checked={form.is_unlimited} onChange={(e) => setForm({ ...form, is_unlimited: e.target.checked })} /> Unlimited
      </label>
      <label>Unit<input value={form.unit} onChange={(e) => setForm({ ...form, unit: e.target.value })} /></label>
      <button className="btn btn-secondary" onClick={submit} disabled={submitting || !form.limit_key}>Add Limit</button>
    </div>
  );
}

function ClonePlanForm({ planId, onCloned }: { planId: string; onCloned: () => void }) {
  const [form, setForm] = useState({ name: '', plan_code: '' });
  const [error, setError] = useState<string | null>(null);
  const [submitting, setSubmitting] = useState(false);
  const navigate = useNavigate();

  async function submit() {
    setSubmitting(true);
    setError(null);
    try {
      const cloned = await plansApi.clone(planId, form.name, form.plan_code);
      onCloned();
      navigate(`/plans/${cloned.id}`);
    } catch (err) {
      setError(unwrapError(err).message);
    } finally {
      setSubmitting(false);
    }
  }

  return (
    <div className="form">
      <ErrorAlert message={error} />
      <label>New Name<input value={form.name} onChange={(e) => setForm({ ...form, name: e.target.value })} /></label>
      <label>New Plan Code<input value={form.plan_code} onChange={(e) => setForm({ ...form, plan_code: e.target.value })} /></label>
      <div className="modal-actions">
        <button className="btn btn-primary" onClick={submit} disabled={submitting}>Clone</button>
      </div>
    </div>
  );
}
