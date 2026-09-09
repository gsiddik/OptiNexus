import { useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { policiesApi } from '../../../api/endpoints';
import { useApiList } from '../../../api/useApi';
import { DataTable } from '../../../components/DataTable';
import { Pagination } from '../../../components/Pagination';
import { StatusBadge } from '../../../components/StatusBadge';
import { LoadingSpinner } from '../../../components/LoadingSpinner';
import { ErrorAlert } from '../../../components/ErrorAlert';
import { Modal } from '../../../components/Modal';
import { PermissionGuard } from '../../../auth/PermissionGuard';
import { unwrapError } from '../../../api/client';
import type { Policy } from '../../../api/types';

const POLICY_TYPES = ['AUTHORIZATION', 'WORKFLOW_ROUTING', 'APPROVAL', 'FEATURE', 'INTEGRATION', 'COMMERCIAL', 'SECURITY'];

export function PoliciesListPage() {
  const navigate = useNavigate();
  const { rows, meta, setPage, loading, error, reload } = useApiList<Policy>((p) => policiesApi.list({ page: p }));
  const [showCreate, setShowCreate] = useState(false);

  return (
    <div>
      <div className="page-header">
        <h1>Policies</h1>
        <PermissionGuard permission="cgo.policy.create">
          <button className="btn btn-primary" onClick={() => setShowCreate(true)}>+ New Policy</button>
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
            onRowClick={(r) => navigate(`/policies/${r.id}`)}
            columns={[
              { key: 'code', header: 'Code', render: (r) => r.policy_code },
              { key: 'name', header: 'Name', render: (r) => r.name },
              { key: 'type', header: 'Type', render: (r) => r.policy_type },
              { key: 'effect', header: 'Effect', render: (r) => r.effect },
              { key: 'priority', header: 'Priority', render: (r) => r.priority },
              { key: 'status', header: 'Status', render: (r) => <StatusBadge status={r.status} /> },
            ]}
          />
          <Pagination meta={meta} onPageChange={setPage} />
        </>
      )}

      <Modal open={showCreate} title="New Policy" onClose={() => setShowCreate(false)}>
        <CreatePolicyForm onCreated={() => { setShowCreate(false); reload(); }} />
      </Modal>
    </div>
  );
}

function CreatePolicyForm({ onCreated }: { onCreated: () => void }) {
  const [form, setForm] = useState({
    policy_code: '', name: '', policy_type: 'AUTHORIZATION', effect: 'ALLOW', priority: 100,
    condition_definition: '{\n  "field": "context.amount",\n  "operator": "lte",\n  "value": 100\n}',
  });
  const [error, setError] = useState<string | null>(null);
  const [submitting, setSubmitting] = useState(false);

  async function handleSubmit() {
    setSubmitting(true);
    setError(null);
    try {
      const condition_definition = JSON.parse(form.condition_definition);
      await policiesApi.create({ ...form, priority: Number(form.priority), condition_definition } as Partial<Policy>);
      onCreated();
    } catch (err) {
      setError(err instanceof SyntaxError ? 'condition_definition must be valid JSON.' : unwrapError(err).message);
    } finally {
      setSubmitting(false);
    }
  }

  return (
    <div className="form">
      <ErrorAlert message={error} />
      <label>Policy Code<input value={form.policy_code} onChange={(e) => setForm({ ...form, policy_code: e.target.value })} /></label>
      <label>Name<input value={form.name} onChange={(e) => setForm({ ...form, name: e.target.value })} /></label>
      <label>
        Policy Type
        <select value={form.policy_type} onChange={(e) => setForm({ ...form, policy_type: e.target.value })}>
          {POLICY_TYPES.map((t) => <option key={t} value={t}>{t}</option>)}
        </select>
      </label>
      <label>
        Effect
        <select value={form.effect} onChange={(e) => setForm({ ...form, effect: e.target.value })}>
          <option value="ALLOW">ALLOW</option>
          <option value="DENY">DENY</option>
          <option value="MATCH">MATCH</option>
        </select>
      </label>
      <label>Priority<input type="number" value={form.priority} onChange={(e) => setForm({ ...form, priority: Number(e.target.value) })} /></label>
      <label>
        Condition Definition (JSON)
        <textarea rows={8} value={form.condition_definition} onChange={(e) => setForm({ ...form, condition_definition: e.target.value })} />
      </label>
      <p className="muted">Declarative only: {'{'}"all"|"any"|"not"|"field"/"operator"/"value"{'}'}. No expressions or code are ever executed.</p>
      <div className="modal-actions">
        <button className="btn btn-primary" onClick={handleSubmit} disabled={submitting}>Create</button>
      </div>
    </div>
  );
}
