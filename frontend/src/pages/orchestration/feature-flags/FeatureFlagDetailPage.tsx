import { useState } from 'react';
import { Link, useParams } from 'react-router-dom';
import { featureFlagsApi } from '../../../api/endpoints';
import { useApiResource } from '../../../api/useApi';
import { LoadingSpinner } from '../../../components/LoadingSpinner';
import { ErrorAlert } from '../../../components/ErrorAlert';
import { StatusBadge } from '../../../components/StatusBadge';
import { LifecycleActions } from '../../../components/LifecycleActions';
import { ConfirmDialog } from '../../../components/ConfirmDialog';
import { PermissionGuard } from '../../../auth/PermissionGuard';
import { useAuth } from '../../../auth/useAuth';
import { unwrapError } from '../../../api/client';
import type { FeatureFlagEvaluation, FeatureFlagOverride } from '../../../api/types';

export function FeatureFlagDetailPage() {
  const { id } = useParams<{ id: string }>();
  const { hasPermission } = useAuth();
  const { data: flag, loading, error, reload } = useApiResource(() => featureFlagsApi.get(id!), [id]);
  const [pendingDelete, setPendingDelete] = useState<FeatureFlagOverride | null>(null);
  const [actionError, setActionError] = useState<string | null>(null);

  if (loading) return <LoadingSpinner />;
  if (error) return <ErrorAlert message={error} />;
  if (!flag) return null;

  async function confirmDelete() {
    if (!pendingDelete) return;
    setActionError(null);
    try {
      await featureFlagsApi.removeOverride(flag!.id, pendingDelete.id);
      reload();
    } catch (err) {
      setActionError(unwrapError(err).message);
    } finally {
      setPendingDelete(null);
    }
  }

  return (
    <div>
      <Link to="/feature-flags" className="back-link">&larr; Back to Feature Flags</Link>
      <div className="page-header">
        <h1>{flag.name}</h1>
        <StatusBadge status={flag.status} />
      </div>

      <ErrorAlert message={actionError} />

      <LifecycleActions
        status={flag.status}
        hasPermission={hasPermission}
        onDone={reload}
        actions={[
          { key: 'activate', label: 'Activate', allowedFrom: ['DRAFT', 'INACTIVE'], permission: 'cgo.featureflag.update', action: () => featureFlagsApi.activate(flag.id) },
          { key: 'deactivate', label: 'Deactivate', allowedFrom: ['ACTIVE'], permission: 'cgo.featureflag.update', danger: true, action: () => featureFlagsApi.deactivate(flag.id) },
        ]}
      />

      <div className="detail-grid">
        <div className="card">
          <h3>Details</h3>
          <dl className="definition-list">
            <dt>Flag Key</dt><dd><code>{flag.flag_key}</code></dd>
            <dt>Type</dt><dd>{flag.flag_type}</dd>
            <dt>Default Value</dt><dd><code>{JSON.stringify(flag.default_value)}</code></dd>
            <dt>Application</dt><dd>{flag.application_id ?? 'All applications'}</dd>
          </dl>
        </div>

        <div className="card">
          <h3>Overrides (precedence: USER &gt; TENANT &gt; APPLICATION &gt; GLOBAL default)</h3>
          <table className="data-table">
            <thead><tr><th>Scope</th><th>Scope ID</th><th>Value</th><th></th></tr></thead>
            <tbody>
              {flag.overrides?.map((o) => (
                <tr key={o.id}>
                  <td>{o.scope_type}</td>
                  <td>{o.scope_id ?? '—'}</td>
                  <td><code>{JSON.stringify(o.value)}</code></td>
                  <td>
                    <PermissionGuard permission="cgo.featureflag.override">
                      <button className="btn-icon" onClick={() => setPendingDelete(o)} aria-label="Remove override">×</button>
                    </PermissionGuard>
                  </td>
                </tr>
              ))}
              {!flag.overrides?.length && <tr><td colSpan={4} className="empty-cell">No overrides.</td></tr>}
            </tbody>
          </table>

          <PermissionGuard permission="cgo.featureflag.override">
            <AddOverrideForm flagId={flag.id} flagType={flag.flag_type} onAdded={reload} />
          </PermissionGuard>
        </div>

        <EvaluatePreview flagKey={flag.flag_key} />
      </div>

      <ConfirmDialog
        open={!!pendingDelete}
        title="Remove Override"
        message="Remove this override? Evaluation will fall back to the next scope in the precedence chain."
        confirmLabel="Remove"
        danger
        onConfirm={confirmDelete}
        onCancel={() => setPendingDelete(null)}
      />
    </div>
  );
}

function AddOverrideForm({ flagId, flagType, onAdded }: { flagId: string; flagType: string; onAdded: () => void }) {
  const [form, setForm] = useState({ scope_type: 'TENANT', scope_id: '', value: flagType === 'BOOLEAN' ? 'true' : '""' });
  const [error, setError] = useState<string | null>(null);
  const [submitting, setSubmitting] = useState(false);

  async function submit() {
    setSubmitting(true);
    setError(null);
    try {
      const value = JSON.parse(form.value);
      await featureFlagsApi.addOverride(flagId, { scope_type: form.scope_type, scope_id: form.scope_type === 'GLOBAL' ? null : form.scope_id, value });
      setForm({ ...form, scope_id: '' });
      onAdded();
    } catch (err) {
      setError(err instanceof SyntaxError ? 'Value must be valid JSON.' : unwrapError(err).message);
    } finally {
      setSubmitting(false);
    }
  }

  return (
    <div className="form">
      <ErrorAlert message={error} />
      <div className="toolbar">
        <select value={form.scope_type} onChange={(e) => setForm({ ...form, scope_type: e.target.value })}>
          {['GLOBAL', 'APPLICATION', 'TENANT', 'USER'].map((s) => <option key={s} value={s}>{s}</option>)}
        </select>
        {form.scope_type !== 'GLOBAL' && (
          <input placeholder="Scope ID (tenant/application/user id)" value={form.scope_id} onChange={(e) => setForm({ ...form, scope_id: e.target.value })} />
        )}
        <input placeholder="Value (JSON)" value={form.value} onChange={(e) => setForm({ ...form, value: e.target.value })} />
        <button className="btn btn-secondary" onClick={submit} disabled={submitting}>Add Override</button>
      </div>
    </div>
  );
}

function EvaluatePreview({ flagKey }: { flagKey: string }) {
  const [tenantId, setTenantId] = useState('');
  const [userId, setUserId] = useState('');
  const [result, setResult] = useState<FeatureFlagEvaluation | null>(null);
  const [error, setError] = useState<string | null>(null);

  async function evaluate() {
    setError(null);
    setResult(null);
    try {
      setResult(await featureFlagsApi.evaluate({ flag_key: flagKey, tenant_id: tenantId || undefined, user_id: userId || undefined }));
    } catch (err) {
      setError(unwrapError(err).message);
    }
  }

  return (
    <div className="card">
      <h3>Evaluate</h3>
      <ErrorAlert message={error} />
      <label>Tenant ID (optional)<input value={tenantId} onChange={(e) => setTenantId(e.target.value)} /></label>
      <label>User ID (optional)<input value={userId} onChange={(e) => setUserId(e.target.value)} /></label>
      <div className="modal-actions"><button className="btn btn-secondary" onClick={evaluate}>Evaluate</button></div>
      {result && (
        <p>
          <strong>Enabled:</strong> {String(result.enabled)} — <strong>Value:</strong> <code>{JSON.stringify(result.value)}</code> — <strong>Source:</strong> {result.source}
        </p>
      )}
    </div>
  );
}
