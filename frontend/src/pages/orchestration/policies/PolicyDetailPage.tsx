import { useState } from 'react';
import { useParams } from 'react-router-dom';
import { policiesApi } from '../../../api/endpoints';
import { useApiResource } from '../../../api/useApi';
import { LoadingSpinner } from '../../../components/LoadingSpinner';
import { ErrorAlert } from '../../../components/ErrorAlert';
import { StatusBadge } from '../../../components/StatusBadge';
import { LifecycleActions } from '../../../components/LifecycleActions';
import { PermissionGuard } from '../../../auth/PermissionGuard';
import { useAuth } from '../../../auth/useAuth';
import { unwrapError } from '../../../api/client';
import type { PolicySimulationResult } from '../../../api/types';

export function PolicyDetailPage() {
  const { id } = useParams<{ id: string }>();
  const { hasPermission } = useAuth();
  const { data: policy, loading, error, reload } = useApiResource(() => policiesApi.get(id!), [id]);

  if (loading) return <LoadingSpinner />;
  if (error) return <ErrorAlert message={error} />;
  if (!policy) return null;

  return (
    <div>
      <div className="page-header">
        <h1>{policy.name}</h1>
        <StatusBadge status={policy.status} />
      </div>

      <LifecycleActions
        status={policy.status}
        hasPermission={hasPermission}
        onDone={reload}
        actions={[
          { key: 'activate', label: 'Activate', allowedFrom: ['DRAFT', 'INACTIVE'], permission: 'cgo.policy.activate', action: () => policiesApi.activate(policy.id) },
          { key: 'deactivate', label: 'Deactivate', allowedFrom: ['ACTIVE'], permission: 'cgo.policy.deactivate', action: () => policiesApi.deactivate(policy.id) },
          { key: 'deprecate', label: 'Deprecate', allowedFrom: ['ACTIVE', 'INACTIVE'], permission: 'cgo.policy.deprecate', danger: true, action: () => policiesApi.deprecate(policy.id) },
        ]}
      />

      <div className="detail-grid">
        <div className="card">
          <h3>Details</h3>
          <dl className="definition-list">
            <dt>Policy Code</dt><dd>{policy.policy_code}</dd>
            <dt>Type</dt><dd>{policy.policy_type}</dd>
            <dt>Effect</dt><dd>{policy.effect}</dd>
            <dt>Priority</dt><dd>{policy.priority}</dd>
            <dt>Version</dt><dd>{policy.version}</dd>
            <dt>Tenant</dt><dd>{policy.tenant_id ?? 'Global'}</dd>
            <dt>Application</dt><dd>{policy.application_id ?? 'All applications'}</dd>
            <dt>Effective From</dt><dd>{policy.effective_from ? new Date(policy.effective_from).toLocaleString() : '—'}</dd>
            <dt>Effective Until</dt><dd>{policy.effective_until ? new Date(policy.effective_until).toLocaleString() : '—'}</dd>
          </dl>
        </div>

        <div className="card">
          <h3>Condition Definition</h3>
          <pre className="json-block">{JSON.stringify(policy.condition_definition, null, 2)}</pre>
        </div>

        <PermissionGuard permission="cgo.policy.simulate">
          <PolicySimulator policy={policy} />
        </PermissionGuard>
      </div>
    </div>
  );
}

function PolicySimulator({ policy }: { policy: { policy_type: string; tenant_id: string | null; application_id: string | null } }) {
  const [context, setContext] = useState('{\n  "amount": 50\n}');
  const [result, setResult] = useState<PolicySimulationResult | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [running, setRunning] = useState(false);

  async function run() {
    setRunning(true);
    setError(null);
    setResult(null);
    try {
      const parsedContext = JSON.parse(context);
      const res = await policiesApi.simulate({
        policy_type: policy.policy_type, tenant_id: policy.tenant_id, application_id: policy.application_id, context: parsedContext,
      });
      setResult(res);
    } catch (err) {
      setError(err instanceof SyntaxError ? 'Context must be valid JSON.' : unwrapError(err).message);
    } finally {
      setRunning(false);
    }
  }

  return (
    <div className="card">
      <h3>Simulate</h3>
      <p className="muted">Runs against every ACTIVE policy of this type/scope. Never executes side effects.</p>
      <ErrorAlert message={error} />
      <label>
        Context (JSON)
        <textarea rows={6} value={context} onChange={(e) => setContext(e.target.value)} />
      </label>
      <div className="modal-actions">
        <button className="btn btn-secondary" onClick={run} disabled={running}>Run Simulation</button>
      </div>
      {result && (
        <div className="simulation-result">
          <p><strong>Decision:</strong> <StatusBadgeInline value={result.decision} /></p>
          <p><strong>Reason:</strong> {result.reason_code}</p>
          <p><strong>Matched Policies:</strong> {result.matched_policies.length}</p>
          <ul>
            {result.matched_policies.map((m) => (
              <li key={m.id}>{m.policy_code} — {m.effect} (priority {m.priority}, v{m.version})</li>
            ))}
          </ul>
        </div>
      )}
    </div>
  );
}

function StatusBadgeInline({ value }: { value: string }) {
  const tone = value === 'DENY' ? 'danger' : value === 'ALLOW' ? 'success' : 'neutral';
  return <span className={`badge badge-${tone}`}>{value}</span>;
}
