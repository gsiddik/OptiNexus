import { useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { approvalDefinitionsApi } from '../../../api/endpoints';
import { useApiList } from '../../../api/useApi';
import { DataTable } from '../../../components/DataTable';
import { Pagination } from '../../../components/Pagination';
import { StatusBadge } from '../../../components/StatusBadge';
import { LoadingSpinner } from '../../../components/LoadingSpinner';
import { ErrorAlert } from '../../../components/ErrorAlert';
import { Modal } from '../../../components/Modal';
import { PermissionGuard } from '../../../auth/PermissionGuard';
import { unwrapError } from '../../../api/client';
import type { ApprovalDefinition } from '../../../api/types';

const DEFAULT_LEVELS = [{ level_order: 1, name: 'Level 1', approver_type: 'ROLE', approver_reference: 'SOME_ROLE_CODE' }];

export function ApprovalDefinitionsListPage() {
  const navigate = useNavigate();
  const { rows, meta, setPage, loading, error, reload } = useApiList<ApprovalDefinition>((p) => approvalDefinitionsApi.list({ page: p }));
  const [showCreate, setShowCreate] = useState(false);

  return (
    <div>
      <div className="page-header">
        <h1>Approval Definitions</h1>
        <PermissionGuard permission="cgo.approval.definition.manage">
          <button className="btn btn-primary" onClick={() => setShowCreate(true)}>+ New Definition</button>
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
            onRowClick={(r) => navigate(`/approval-definitions/${r.id}`)}
            columns={[
              { key: 'code', header: 'Code', render: (r) => r.definition_code },
              { key: 'name', header: 'Name', render: (r) => r.name },
              { key: 'rule', header: 'Rule', render: (r) => r.rule_type },
              { key: 'self', header: 'Self-approval', render: (r) => (r.self_approval_allowed ? 'Allowed' : 'Denied') },
              { key: 'status', header: 'Status', render: (r) => <StatusBadge status={r.status} /> },
            ]}
          />
          <Pagination meta={meta} onPageChange={setPage} />
        </>
      )}

      <Modal open={showCreate} title="New Approval Definition" onClose={() => setShowCreate(false)}>
        <CreateForm onCreated={(id) => { setShowCreate(false); navigate(`/approval-definitions/${id}`); reload(); }} />
      </Modal>
    </div>
  );
}

function CreateForm({ onCreated }: { onCreated: (id: string) => void }) {
  const [form, setForm] = useState({
    definition_code: '', name: '', rule_type: 'SEQUENTIAL', self_approval_allowed: false, expires_after_hours: '',
    levels: JSON.stringify(DEFAULT_LEVELS, null, 2),
  });
  const [error, setError] = useState<string | null>(null);
  const [submitting, setSubmitting] = useState(false);

  async function handleSubmit() {
    setSubmitting(true);
    setError(null);
    try {
      const levels = JSON.parse(form.levels);
      const result = await approvalDefinitionsApi.create({
        definition_code: form.definition_code, name: form.name, rule_type: form.rule_type,
        self_approval_allowed: form.self_approval_allowed,
        expires_after_hours: form.expires_after_hours ? Number(form.expires_after_hours) : null,
        levels,
      });
      onCreated(result.id);
    } catch (err) {
      setError(err instanceof SyntaxError ? 'Levels must be valid JSON.' : unwrapError(err).message);
    } finally {
      setSubmitting(false);
    }
  }

  return (
    <div className="form">
      <ErrorAlert message={error} />
      <label>Definition Code<input value={form.definition_code} onChange={(e) => setForm({ ...form, definition_code: e.target.value })} /></label>
      <label>Name<input value={form.name} onChange={(e) => setForm({ ...form, name: e.target.value })} /></label>
      <label>
        Rule Type
        <select value={form.rule_type} onChange={(e) => setForm({ ...form, rule_type: e.target.value })}>
          <option value="SEQUENTIAL">SEQUENTIAL</option>
          <option value="ANY_OF">ANY_OF</option>
          <option value="ALL_OF">ALL_OF</option>
        </select>
      </label>
      <label className="checkbox-label">
        <input type="checkbox" checked={form.self_approval_allowed} onChange={(e) => setForm({ ...form, self_approval_allowed: e.target.checked })} />
        Allow self-approval
      </label>
      <label>Expires After (hours, optional)<input type="number" value={form.expires_after_hours} onChange={(e) => setForm({ ...form, expires_after_hours: e.target.value })} /></label>
      <label>
        Levels (JSON: level_order, name, approver_type, approver_reference)
        <textarea rows={8} value={form.levels} onChange={(e) => setForm({ ...form, levels: e.target.value })} />
      </label>
      <p className="muted">approver_type: USER (a user id), ROLE/TENANT_ROLE/APPLICATION_ROLE (a role code), or DYNAMIC.</p>
      <div className="modal-actions">
        <button className="btn btn-primary" onClick={handleSubmit} disabled={submitting}>Create</button>
      </div>
    </div>
  );
}
