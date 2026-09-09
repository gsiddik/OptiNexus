import { useState } from 'react';
import { useNavigate, useSearchParams } from 'react-router-dom';
import { notificationTemplatesApi, notificationsApi } from '../../../api/endpoints';
import { useApiList, useApiResource } from '../../../api/useApi';
import { DataTable } from '../../../components/DataTable';
import { Pagination } from '../../../components/Pagination';
import { StatusBadge } from '../../../components/StatusBadge';
import { LoadingSpinner } from '../../../components/LoadingSpinner';
import { ErrorAlert } from '../../../components/ErrorAlert';
import { Modal } from '../../../components/Modal';
import { PermissionGuard } from '../../../auth/PermissionGuard';
import { unwrapError } from '../../../api/client';
import type { Notification, NotificationTemplate } from '../../../api/types';

export function NotificationsListPage() {
  const navigate = useNavigate();
  const [params, setParams] = useSearchParams();
  const status = params.get('status') ?? '';
  const { rows, meta, setPage, loading, error, reload } = useApiList<Notification>((p) => notificationsApi.list({ page: p, status: status || undefined }), [status]);
  const [showSend, setShowSend] = useState(false);

  return (
    <div>
      <div className="page-header">
        <h1>Notifications</h1>
        <PermissionGuard permission="cgo.notification.send">
          <button className="btn btn-primary" onClick={() => setShowSend(true)}>Send Notification</button>
        </PermissionGuard>
      </div>

      <div className="toolbar">
        <select value={status} onChange={(e) => setParams((p) => { p.set('status', e.target.value); return p; })}>
          <option value="">Any status</option>
          {['QUEUED', 'PROCESSING', 'SENT', 'FAILED', 'CANCELLED'].map((s) => <option key={s} value={s}>{s}</option>)}
        </select>
      </div>

      <ErrorAlert message={error} />
      {loading ? (
        <LoadingSpinner />
      ) : (
        <>
          <DataTable
            rows={rows}
            rowKey={(r) => r.id}
            onRowClick={(r) => navigate(`/notifications/${r.id}`)}
            columns={[
              { key: 'channel', header: 'Channel', render: (r) => r.channel },
              { key: 'subject', header: 'Subject', render: (r) => r.subject ?? r.body.slice(0, 40) },
              { key: 'recipient', header: 'Recipient', render: (r) => r.recipient_user_id ?? '—' },
              { key: 'correlation', header: 'Correlation', render: (r) => r.correlation_id ? <code>{r.correlation_id.slice(0, 8)}</code> : '—' },
              { key: 'status', header: 'Status', render: (r) => <StatusBadge status={r.status} /> },
            ]}
          />
          <Pagination meta={meta} onPageChange={setPage} />
        </>
      )}

      <Modal open={showSend} title="Send Notification" onClose={() => setShowSend(false)}>
        <SendForm onSent={() => { setShowSend(false); reload(); }} />
      </Modal>
    </div>
  );
}

function SendForm({ onSent }: { onSent: () => void }) {
  const { data: templates } = useApiResource(() => notificationTemplatesApi.list({ per_page: 100, status: 'ACTIVE' }).then((e) => e.data), []);
  const [form, setForm] = useState({ template_code: '', recipient_user_ids: '', context: '{}', tenant_id: '' });
  const [error, setError] = useState<string | null>(null);
  const [submitting, setSubmitting] = useState(false);

  async function handleSubmit() {
    setSubmitting(true);
    setError(null);
    try {
      const context = JSON.parse(form.context);
      const recipient_user_ids = form.recipient_user_ids.split(',').map((s) => s.trim()).filter(Boolean);
      await notificationsApi.send({ template_code: form.template_code, recipient_user_ids, context, tenant_id: form.tenant_id || undefined });
      onSent();
    } catch (err) {
      setError(err instanceof SyntaxError ? 'Context must be valid JSON.' : unwrapError(err).message);
    } finally {
      setSubmitting(false);
    }
  }

  return (
    <div className="form">
      <ErrorAlert message={error} />
      <label>
        Template
        <select value={form.template_code} onChange={(e) => setForm({ ...form, template_code: e.target.value })}>
          <option value="">Select template...</option>
          {((templates as NotificationTemplate[] | null) ?? []).map((t) => <option key={t.id} value={t.template_code}>{t.name}</option>)}
        </select>
      </label>
      <label>Recipient User IDs (comma-separated)<input value={form.recipient_user_ids} onChange={(e) => setForm({ ...form, recipient_user_ids: e.target.value })} /></label>
      <label>Tenant ID (optional, enforces tenant membership)<input value={form.tenant_id} onChange={(e) => setForm({ ...form, tenant_id: e.target.value })} /></label>
      <label>Context (JSON, available as {'{{'} data.* {'}}'})<textarea rows={5} value={form.context} onChange={(e) => setForm({ ...form, context: e.target.value })} /></label>
      <div className="modal-actions">
        <button className="btn btn-primary" onClick={handleSubmit} disabled={submitting || !form.template_code}>Send</button>
      </div>
    </div>
  );
}
