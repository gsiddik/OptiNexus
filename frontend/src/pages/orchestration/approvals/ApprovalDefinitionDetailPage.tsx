import { useState } from 'react';
import { Link, useParams } from 'react-router-dom';
import { approvalDefinitionsApi } from '../../../api/endpoints';
import { useApiResource } from '../../../api/useApi';
import { LoadingSpinner } from '../../../components/LoadingSpinner';
import { ErrorAlert } from '../../../components/ErrorAlert';
import { StatusBadge } from '../../../components/StatusBadge';
import { PermissionGuard } from '../../../auth/PermissionGuard';
import { unwrapError } from '../../../api/client';

export function ApprovalDefinitionDetailPage() {
  const { id } = useParams<{ id: string }>();
  const { data: definition, loading, error, reload } = useApiResource(() => approvalDefinitionsApi.get(id!), [id]);
  const [statusError, setStatusError] = useState<string | null>(null);

  if (loading) return <LoadingSpinner />;
  if (error) return <ErrorAlert message={error} />;
  if (!definition) return null;

  async function setStatus(status: string) {
    setStatusError(null);
    try {
      await approvalDefinitionsApi.update(definition!.id, { status });
      reload();
    } catch (err) {
      setStatusError(unwrapError(err).message);
    }
  }

  return (
    <div>
      <Link to="/approval-definitions" className="back-link">&larr; Back to Approval Definitions</Link>
      <div className="page-header">
        <h1>{definition.name}</h1>
        <StatusBadge status={definition.status} />
      </div>

      <ErrorAlert message={statusError} />
      <div className="toolbar">
        <PermissionGuard permission="cgo.approval.definition.manage">
          {definition.status !== 'ACTIVE' && <button className="btn btn-primary" onClick={() => setStatus('ACTIVE')}>Activate</button>}
          {definition.status === 'ACTIVE' && <button className="btn btn-secondary" onClick={() => setStatus('INACTIVE')}>Deactivate</button>}
        </PermissionGuard>
      </div>

      <div className="detail-grid">
        <div className="card">
          <h3>Details</h3>
          <dl className="definition-list">
            <dt>Definition Code</dt><dd>{definition.definition_code}</dd>
            <dt>Rule Type</dt><dd>{definition.rule_type}</dd>
            <dt>Self-Approval</dt><dd>{definition.self_approval_allowed ? 'Allowed' : 'Denied'}</dd>
            <dt>Expires After</dt><dd>{definition.expires_after_hours ? `${definition.expires_after_hours}h` : 'Never'}</dd>
            <dt>Tenant</dt><dd>{definition.tenant_id ?? 'Global'}</dd>
          </dl>
        </div>

        <div className="card">
          <h3>Levels (Approval Matrix)</h3>
          <table className="data-table">
            <thead><tr><th>Order</th><th>Name</th><th>Approver Type</th><th>Reference</th></tr></thead>
            <tbody>
              {definition.levels?.sort((a, b) => a.level_order - b.level_order).map((l) => (
                <tr key={l.id}><td>{l.level_order}</td><td>{l.name}</td><td>{l.approver_type}</td><td>{l.approver_reference ?? '—'}</td></tr>
              ))}
            </tbody>
          </table>
        </div>
      </div>
    </div>
  );
}
