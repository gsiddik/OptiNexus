import { useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { notificationTemplatesApi } from '../../../api/endpoints';
import { useApiList } from '../../../api/useApi';
import { DataTable } from '../../../components/DataTable';
import { Pagination } from '../../../components/Pagination';
import { StatusBadge } from '../../../components/StatusBadge';
import { LoadingSpinner } from '../../../components/LoadingSpinner';
import { ErrorAlert } from '../../../components/ErrorAlert';
import { Modal } from '../../../components/Modal';
import { PermissionGuard } from '../../../auth/PermissionGuard';
import { unwrapError } from '../../../api/client';
import type { NotificationTemplate } from '../../../api/types';

export function NotificationTemplatesListPage() {
  const navigate = useNavigate();
  const { rows, meta, setPage, loading, error, reload } = useApiList<NotificationTemplate>((p) => notificationTemplatesApi.list({ page: p }));
  const [showCreate, setShowCreate] = useState(false);

  return (
    <div>
      <div className="page-header">
        <h1>Notification Templates</h1>
        <PermissionGuard permission="cgo.notification.template.manage">
          <button className="btn btn-primary" onClick={() => setShowCreate(true)}>+ New Template</button>
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
            onRowClick={(r) => navigate(`/notification-templates/${r.id}`)}
            columns={[
              { key: 'code', header: 'Code', render: (r) => r.template_code },
              { key: 'name', header: 'Name', render: (r) => r.name },
              { key: 'channel', header: 'Channel', render: (r) => r.channel },
              { key: 'version', header: 'Version', render: (r) => r.version },
              { key: 'status', header: 'Status', render: (r) => <StatusBadge status={r.status} /> },
            ]}
          />
          <Pagination meta={meta} onPageChange={setPage} />
        </>
      )}

      <Modal open={showCreate} title="New Notification Template" onClose={() => setShowCreate(false)}>
        <CreateForm onCreated={() => { setShowCreate(false); reload(); }} />
      </Modal>
    </div>
  );
}

function CreateForm({ onCreated }: { onCreated: () => void }) {
  const [form, setForm] = useState({ template_code: '', name: '', channel: 'IN_APP', subject_template: '', body_template: 'Hello {{data.name}}.' });
  const [error, setError] = useState<string | null>(null);
  const [submitting, setSubmitting] = useState(false);

  async function handleSubmit() {
    setSubmitting(true);
    setError(null);
    try {
      await notificationTemplatesApi.create(form);
      onCreated();
    } catch (err) {
      setError(unwrapError(err).message);
    } finally {
      setSubmitting(false);
    }
  }

  return (
    <div className="form">
      <ErrorAlert message={error} />
      <label>Template Code<input value={form.template_code} onChange={(e) => setForm({ ...form, template_code: e.target.value })} /></label>
      <label>Name<input value={form.name} onChange={(e) => setForm({ ...form, name: e.target.value })} /></label>
      <label>
        Channel
        <select value={form.channel} onChange={(e) => setForm({ ...form, channel: e.target.value })}>
          <option value="IN_APP">IN_APP</option>
          <option value="EMAIL">EMAIL</option>
          <option value="WEBHOOK">WEBHOOK</option>
        </select>
      </label>
      <label>Subject Template (optional)<input value={form.subject_template} onChange={(e) => setForm({ ...form, subject_template: e.target.value })} /></label>
      <label>Body Template<textarea rows={6} value={form.body_template} onChange={(e) => setForm({ ...form, body_template: e.target.value })} /></label>
      <p className="muted">Only {'{{'} dot.path {'}}'} placeholders are substituted as plain text - no code execution.</p>
      <div className="modal-actions">
        <button className="btn btn-primary" onClick={handleSubmit} disabled={submitting}>Create</button>
      </div>
    </div>
  );
}
