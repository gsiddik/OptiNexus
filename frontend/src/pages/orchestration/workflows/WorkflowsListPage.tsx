import { useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { workflowsApi } from '../../../api/endpoints';
import { useApiList } from '../../../api/useApi';
import { DataTable } from '../../../components/DataTable';
import { Pagination } from '../../../components/Pagination';
import { StatusBadge } from '../../../components/StatusBadge';
import { LoadingSpinner } from '../../../components/LoadingSpinner';
import { ErrorAlert } from '../../../components/ErrorAlert';
import { Modal } from '../../../components/Modal';
import { PermissionGuard } from '../../../auth/PermissionGuard';
import { unwrapError } from '../../../api/client';
import type { Workflow } from '../../../api/types';

const DEFAULT_STEPS = [
  { step_key: 'start', step_type: 'START' },
  { step_key: 'task1', step_type: 'TASK' },
  { step_key: 'end', step_type: 'END' },
];
const DEFAULT_TRANSITIONS = [
  { from_step_key: 'start', to_step_key: 'task1' },
  { from_step_key: 'task1', to_step_key: 'end' },
];

export function WorkflowsListPage() {
  const navigate = useNavigate();
  const { rows, meta, setPage, loading, error } = useApiList<Workflow>((p) => workflowsApi.list({ page: p }));
  const [showCreate, setShowCreate] = useState(false);

  return (
    <div>
      <div className="page-header">
        <h1>Workflows</h1>
        <PermissionGuard permission="cgo.workflow.create">
          <button className="btn btn-primary" onClick={() => setShowCreate(true)}>+ New Workflow</button>
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
            onRowClick={(r) => navigate(`/workflows/${r.id}`)}
            columns={[
              { key: 'code', header: 'Code', render: (r) => r.workflow_code },
              { key: 'name', header: 'Name', render: (r) => r.name },
              { key: 'trigger', header: 'Trigger', render: (r) => r.trigger_type + (r.trigger_event_key ? ` (${r.trigger_event_key})` : '') },
              { key: 'version', header: 'Version', render: (r) => r.current_version },
              { key: 'status', header: 'Status', render: (r) => <StatusBadge status={r.status} /> },
            ]}
          />
          <Pagination meta={meta} onPageChange={setPage} />
        </>
      )}

      <Modal open={showCreate} title="New Workflow" onClose={() => setShowCreate(false)}>
        <CreateWorkflowForm onCreated={(id) => { setShowCreate(false); navigate(`/workflows/${id}`); }} />
      </Modal>
    </div>
  );
}

function CreateWorkflowForm({ onCreated }: { onCreated: (id: string) => void }) {
  const [form, setForm] = useState({
    workflow_code: '', name: '', trigger_type: 'MANUAL', trigger_event_key: '',
    steps: JSON.stringify(DEFAULT_STEPS, null, 2),
    transitions: JSON.stringify(DEFAULT_TRANSITIONS, null, 2),
  });
  const [error, setError] = useState<string | null>(null);
  const [submitting, setSubmitting] = useState(false);

  async function handleSubmit() {
    setSubmitting(true);
    setError(null);
    try {
      const steps = JSON.parse(form.steps);
      const transitions = JSON.parse(form.transitions);
      const result = await workflowsApi.create({
        workflow_code: form.workflow_code, name: form.name, trigger_type: form.trigger_type,
        trigger_event_key: form.trigger_type === 'EVENT' ? form.trigger_event_key : null,
        steps, transitions,
      });
      onCreated(result.id);
    } catch (err) {
      setError(err instanceof SyntaxError ? 'Steps/transitions must be valid JSON.' : unwrapError(err).message);
    } finally {
      setSubmitting(false);
    }
  }

  return (
    <div className="form">
      <ErrorAlert message={error} />
      <label>Workflow Code<input value={form.workflow_code} onChange={(e) => setForm({ ...form, workflow_code: e.target.value })} /></label>
      <label>Name<input value={form.name} onChange={(e) => setForm({ ...form, name: e.target.value })} /></label>
      <label>
        Trigger Type
        <select value={form.trigger_type} onChange={(e) => setForm({ ...form, trigger_type: e.target.value })}>
          <option value="MANUAL">MANUAL</option>
          <option value="API">API</option>
          <option value="EVENT">EVENT</option>
          <option value="SCHEDULED">SCHEDULED</option>
        </select>
      </label>
      {form.trigger_type === 'EVENT' && (
        <label>Trigger Event Key<input value={form.trigger_event_key} onChange={(e) => setForm({ ...form, trigger_event_key: e.target.value })} placeholder="e.g. subscription.expiring" /></label>
      )}
      <label>
        Steps (JSON array of step_key/step_type/config)
        <textarea rows={8} value={form.steps} onChange={(e) => setForm({ ...form, steps: e.target.value })} />
      </label>
      <label>
        Transitions (JSON array of from_step_key/to_step_key/condition)
        <textarea rows={8} value={form.transitions} onChange={(e) => setForm({ ...form, transitions: e.target.value })} />
      </label>
      <p className="muted">Step types: START, CONDITION, TASK, APPROVAL, INTEGRATION, INTERNAL_ACTION, WAIT, END. No arbitrary code execution steps exist.</p>
      <div className="modal-actions">
        <button className="btn btn-primary" onClick={handleSubmit} disabled={submitting}>Create Draft</button>
      </div>
    </div>
  );
}
