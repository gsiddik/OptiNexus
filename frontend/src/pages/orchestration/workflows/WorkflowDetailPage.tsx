import { useState } from 'react';
import { useParams, Link, useNavigate } from 'react-router-dom';
import { workflowsApi } from '../../../api/endpoints';
import { useApiResource } from '../../../api/useApi';
import { LoadingSpinner } from '../../../components/LoadingSpinner';
import { ErrorAlert } from '../../../components/ErrorAlert';
import { StatusBadge } from '../../../components/StatusBadge';
import { LifecycleActions } from '../../../components/LifecycleActions';
import { Modal } from '../../../components/Modal';
import { PermissionGuard } from '../../../auth/PermissionGuard';
import { useAuth } from '../../../auth/useAuth';
import { unwrapError } from '../../../api/client';

export function WorkflowDetailPage() {
  const { id } = useParams<{ id: string }>();
  const navigate = useNavigate();
  const { hasPermission } = useAuth();
  const { data: workflow, loading, error, reload } = useApiResource(() => workflowsApi.get(id!), [id]);
  const [showEdit, setShowEdit] = useState(false);
  const [showExecute, setShowExecute] = useState(false);
  const [validation, setValidation] = useState<{ valid: boolean; errors: string[] } | null>(null);
  const [actionError, setActionError] = useState<string | null>(null);

  if (loading) return <LoadingSpinner />;
  if (error) return <ErrorAlert message={error} />;
  if (!workflow) return null;

  const version = workflow.draft_version;

  async function validate() {
    setActionError(null);
    try {
      setValidation(await workflowsApi.validate(workflow!.id));
    } catch (err) {
      setActionError(unwrapError(err).message);
    }
  }

  return (
    <div>
      <div className="page-header">
        <h1>{workflow.name}</h1>
        <StatusBadge status={workflow.status} />
      </div>

      <ErrorAlert message={actionError} />

      <LifecycleActions
        status={workflow.status}
        hasPermission={hasPermission}
        onDone={reload}
        actions={[
          { key: 'activate', label: 'Activate', allowedFrom: ['DRAFT', 'INACTIVE'], permission: 'cgo.workflow.activate', action: () => workflowsApi.activate(workflow.id) },
          { key: 'deactivate', label: 'Deactivate', allowedFrom: ['ACTIVE'], permission: 'cgo.workflow.activate', danger: true, action: () => workflowsApi.deactivate(workflow.id) },
        ]}
      />
      <div className="toolbar">
        <PermissionGuard permission="cgo.workflow.update">
          <button className="btn btn-secondary" onClick={validate}>Validate Draft</button>
        </PermissionGuard>
        <PermissionGuard permission="cgo.workflow.update">
          <button className="btn btn-secondary" onClick={() => setShowEdit(true)}>Edit Draft</button>
        </PermissionGuard>
        {workflow.status === 'ACTIVE' && (
          <PermissionGuard permission="cgo.workflow.execute">
            <button className="btn btn-primary" onClick={() => setShowExecute(true)}>Execute Manually</button>
          </PermissionGuard>
        )}
        <Link to={`/workflow-instances?workflow_id=${workflow.id}`} className="btn btn-ghost">View Instances</Link>
      </div>

      {validation && (
        <div className={`alert ${validation.valid ? 'alert-success' : 'alert-error'}`}>
          {validation.valid ? 'Draft is valid.' : `Errors: ${validation.errors.join('; ')}`}
        </div>
      )}

      <div className="detail-grid">
        <div className="card">
          <h3>Details</h3>
          <dl className="definition-list">
            <dt>Workflow Code</dt><dd>{workflow.workflow_code}</dd>
            <dt>Trigger</dt><dd>{workflow.trigger_type}{workflow.trigger_event_key ? ` (${workflow.trigger_event_key})` : ''}</dd>
            <dt>Current Version</dt><dd>{workflow.current_version}</dd>
            <dt>Tenant</dt><dd>{workflow.tenant_id ?? 'Global'}</dd>
          </dl>
        </div>

        <div className="card">
          <h3>Draft Steps {version ? `(v${version.version})` : ''}</h3>
          {!version?.steps?.length && <p className="muted">No draft steps.</p>}
          <table className="data-table">
            <thead><tr><th>Key</th><th>Type</th><th>Config</th></tr></thead>
            <tbody>
              {version?.steps?.map((s) => (
                <tr key={s.id}><td>{s.step_key}</td><td>{s.step_type}</td><td><code>{JSON.stringify(s.config)}</code></td></tr>
              ))}
            </tbody>
          </table>
        </div>

        <div className="card">
          <h3>Draft Transitions</h3>
          {!version?.transitions?.length && <p className="muted">No draft transitions.</p>}
          <table className="data-table">
            <thead><tr><th>From</th><th>To</th><th>Condition</th></tr></thead>
            <tbody>
              {version?.transitions?.map((t) => {
                const from = version.steps?.find((s) => s.id === t.from_step_id)?.step_key ?? t.from_step_id;
                const to = version.steps?.find((s) => s.id === t.to_step_id)?.step_key ?? t.to_step_id;
                return <tr key={t.id}><td>{from}</td><td>{to}</td><td><code>{t.condition ? JSON.stringify(t.condition) : 'always'}</code></td></tr>;
              })}
            </tbody>
          </table>
        </div>
      </div>

      <Modal open={showEdit} title="Edit Draft Definition" onClose={() => setShowEdit(false)}>
        <EditDraftForm workflow={workflow} onSaved={() => { setShowEdit(false); reload(); }} />
      </Modal>

      <Modal open={showExecute} title="Execute Workflow" onClose={() => setShowExecute(false)}>
        <ExecuteForm
          onExecuted={(instanceId) => { setShowExecute(false); navigate(`/workflow-instances/${instanceId}`); }}
          onExecute={(payload, idempotencyKey) => workflowsApi.execute(workflow.id, { payload, idempotency_key: idempotencyKey || undefined })}
        />
      </Modal>
    </div>
  );
}

function EditDraftForm({ workflow, onSaved }: { workflow: { id: string; draft_version?: { steps?: unknown[]; transitions?: unknown[] } | null }; onSaved: () => void }) {
  const [steps, setSteps] = useState(JSON.stringify(workflow.draft_version?.steps ?? [], null, 2));
  const [transitions, setTransitions] = useState(JSON.stringify(workflow.draft_version?.transitions ?? [], null, 2));
  const [error, setError] = useState<string | null>(null);
  const [submitting, setSubmitting] = useState(false);

  async function save() {
    setSubmitting(true);
    setError(null);
    try {
      await workflowsApi.update(workflow.id, { steps: JSON.parse(steps), transitions: JSON.parse(transitions) });
      onSaved();
    } catch (err) {
      setError(err instanceof SyntaxError ? 'Steps/transitions must be valid JSON.' : unwrapError(err).message);
    } finally {
      setSubmitting(false);
    }
  }

  return (
    <div className="form">
      <ErrorAlert message={error} />
      <p className="muted">Note: submit steps keyed by step_key/step_type/config (not the persisted UUIDs shown above).</p>
      <label>Steps (JSON)<textarea rows={10} value={steps} onChange={(e) => setSteps(e.target.value)} /></label>
      <label>Transitions (JSON, from_step_key/to_step_key/condition)<textarea rows={10} value={transitions} onChange={(e) => setTransitions(e.target.value)} /></label>
      <div className="modal-actions">
        <button className="btn btn-primary" onClick={save} disabled={submitting}>Save Draft</button>
      </div>
    </div>
  );
}

function ExecuteForm({ onExecute, onExecuted }: { onExecute: (payload: Record<string, unknown>, idempotencyKey: string) => Promise<{ id: string }>; onExecuted: (id: string) => void }) {
  const [payload, setPayload] = useState('{}');
  const [idempotencyKey, setIdempotencyKey] = useState('');
  const [error, setError] = useState<string | null>(null);
  const [submitting, setSubmitting] = useState(false);

  async function run() {
    setSubmitting(true);
    setError(null);
    try {
      const parsed = JSON.parse(payload);
      const instance = await onExecute(parsed, idempotencyKey);
      onExecuted(instance.id);
    } catch (err) {
      setError(err instanceof SyntaxError ? 'Payload must be valid JSON.' : unwrapError(err).message);
    } finally {
      setSubmitting(false);
    }
  }

  return (
    <div className="form">
      <ErrorAlert message={error} />
      <label>Trigger Payload (JSON)<textarea rows={6} value={payload} onChange={(e) => setPayload(e.target.value)} /></label>
      <label>Idempotency Key (optional)<input value={idempotencyKey} onChange={(e) => setIdempotencyKey(e.target.value)} /></label>
      <div className="modal-actions">
        <button className="btn btn-primary" onClick={run} disabled={submitting}>Execute</button>
      </div>
    </div>
  );
}
