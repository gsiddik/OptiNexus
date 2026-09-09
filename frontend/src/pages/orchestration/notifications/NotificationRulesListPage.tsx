import { useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { notificationRulesApi, notificationTemplatesApi } from '../../../api/endpoints';
import { useApiList, useApiResource } from '../../../api/useApi';
import { DataTable } from '../../../components/DataTable';
import { Pagination } from '../../../components/Pagination';
import { StatusBadge } from '../../../components/StatusBadge';
import { LoadingSpinner } from '../../../components/LoadingSpinner';
import { ErrorAlert } from '../../../components/ErrorAlert';
import { Modal } from '../../../components/Modal';
import { PermissionGuard } from '../../../auth/PermissionGuard';
import { unwrapError } from '../../../api/client';
import type { NotificationRule, NotificationTemplate } from '../../../api/types';

const RECIPIENT_TYPES = ['SPECIFIC_USER', 'ROLE_MEMBERS', 'TENANT_ADMINS', 'CUSTOMER_ADMINS', 'APPLICATION_ADMINS', 'ACTOR', 'RESOURCE_OWNER', 'EVENT_CONTEXT'];

export function NotificationRulesListPage() {
  const navigate = useNavigate();
  const { rows, meta, setPage, loading, error, reload } = useApiList<NotificationRule>((p) => notificationRulesApi.list({ page: p }));
  const [showCreate, setShowCreate] = useState(false);

  return (
    <div>
      <div className="page-header">
        <h1>Notification Rules</h1>
        <PermissionGuard permission="cgo.notification.rule.manage">
          <button className="btn btn-primary" onClick={() => setShowCreate(true)}>+ New Rule</button>
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
            onRowClick={(r) => navigate(`/notification-rules/${r.id}`)}
            columns={[
              { key: 'code', header: 'Code', render: (r) => r.rule_code },
              { key: 'name', header: 'Name', render: (r) => r.name },
              { key: 'trigger', header: 'Trigger Event', render: (r) => r.trigger_event_key ?? '—' },
              { key: 'recipient', header: 'Recipient', render: (r) => r.recipient_type },
              { key: 'channel', header: 'Channel', render: (r) => r.channel },
              { key: 'status', header: 'Status', render: (r) => <StatusBadge status={r.status} /> },
            ]}
          />
          <Pagination meta={meta} onPageChange={setPage} />
        </>
      )}

      <Modal open={showCreate} title="New Notification Rule" onClose={() => setShowCreate(false)}>
        <CreateForm onCreated={() => { setShowCreate(false); reload(); }} />
      </Modal>
    </div>
  );
}

function CreateForm({ onCreated }: { onCreated: () => void }) {
  const { data: templates } = useApiResource(() => notificationTemplatesApi.list({ per_page: 100 }).then((e) => e.data), []);
  const [form, setForm] = useState({
    rule_code: '', name: '', trigger_event_key: '', recipient_type: 'SPECIFIC_USER', recipient_reference: '',
    notification_template_id: '', channel: 'IN_APP',
  });
  const [error, setError] = useState<string | null>(null);
  const [submitting, setSubmitting] = useState(false);

  async function handleSubmit() {
    setSubmitting(true);
    setError(null);
    try {
      await notificationRulesApi.create({ ...form, trigger_event_key: form.trigger_event_key || null });
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
      <label>Rule Code<input value={form.rule_code} onChange={(e) => setForm({ ...form, rule_code: e.target.value })} /></label>
      <label>Name<input value={form.name} onChange={(e) => setForm({ ...form, name: e.target.value })} /></label>
      <label>Trigger Event Key (optional)<input value={form.trigger_event_key} onChange={(e) => setForm({ ...form, trigger_event_key: e.target.value })} /></label>
      <label>
        Recipient Type
        <select value={form.recipient_type} onChange={(e) => setForm({ ...form, recipient_type: e.target.value })}>
          {RECIPIENT_TYPES.map((t) => <option key={t} value={t}>{t}</option>)}
        </select>
      </label>
      <label>
        Recipient Reference
        <input
          value={form.recipient_reference}
          onChange={(e) => setForm({ ...form, recipient_reference: e.target.value })}
          placeholder={form.recipient_type === 'EVENT_CONTEXT' ? 'data.recipient_user_id' : 'user id or role code'}
        />
      </label>
      <label>
        Template
        <select value={form.notification_template_id} onChange={(e) => setForm({ ...form, notification_template_id: e.target.value })}>
          <option value="">Select template...</option>
          {((templates as NotificationTemplate[] | null) ?? []).map((t) => <option key={t.id} value={t.id}>{t.name} ({t.channel})</option>)}
        </select>
      </label>
      <label>
        Channel
        <select value={form.channel} onChange={(e) => setForm({ ...form, channel: e.target.value })}>
          <option value="IN_APP">IN_APP</option>
          <option value="EMAIL">EMAIL</option>
          <option value="WEBHOOK">WEBHOOK</option>
        </select>
      </label>
      <div className="modal-actions">
        <button className="btn btn-primary" onClick={handleSubmit} disabled={submitting || !form.notification_template_id}>Create</button>
      </div>
    </div>
  );
}
