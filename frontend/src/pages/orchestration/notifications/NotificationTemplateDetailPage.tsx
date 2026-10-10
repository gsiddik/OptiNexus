import { useState } from 'react';
import { useParams } from 'react-router-dom';
import { notificationTemplatesApi } from '../../../api/endpoints';
import { useApiResource } from '../../../api/useApi';
import { LoadingSpinner } from '../../../components/LoadingSpinner';
import { ErrorAlert } from '../../../components/ErrorAlert';
import { StatusBadge } from '../../../components/StatusBadge';
import { PermissionGuard } from '../../../auth/PermissionGuard';
import { unwrapError } from '../../../api/client';

export function NotificationTemplateDetailPage() {
  const { id } = useParams<{ id: string }>();
  const { data: template, loading, error, reload } = useApiResource(() => notificationTemplatesApi.get(id!), [id]);
  const [body, setBody] = useState('');
  const [subject, setSubject] = useState('');
  const [editing, setEditing] = useState(false);
  const [saveError, setSaveError] = useState<string | null>(null);

  if (loading) return <LoadingSpinner />;
  if (error) return <ErrorAlert message={error} />;
  if (!template) return null;

  function startEdit() {
    setBody(template!.body_template);
    setSubject(template!.subject_template ?? '');
    setEditing(true);
  }

  async function save() {
    setSaveError(null);
    try {
      await notificationTemplatesApi.update(template!.id, { body_template: body, subject_template: subject || null });
      setEditing(false);
      reload();
    } catch (err) {
      setSaveError(unwrapError(err).message);
    }
  }

  async function setStatus(status: string) {
    setSaveError(null);
    try {
      await notificationTemplatesApi.update(template!.id, { status });
      reload();
    } catch (err) {
      setSaveError(unwrapError(err).message);
    }
  }

  return (
    <div>
      <div className="page-header">
        <h1>{template.name}</h1>
        <StatusBadge status={template.status} />
      </div>

      <ErrorAlert message={saveError} />
      <div className="toolbar">
        <PermissionGuard permission="cgo.notification.template.manage">
          {template.status !== 'ACTIVE' && <button className="btn btn-primary" onClick={() => setStatus('ACTIVE')}>Activate</button>}
          {template.status === 'ACTIVE' && <button className="btn btn-secondary" onClick={() => setStatus('INACTIVE')}>Deactivate</button>}
          {!editing && <button className="btn btn-secondary" onClick={startEdit}>Edit</button>}
        </PermissionGuard>
      </div>

      <div className="detail-grid">
        <div className="card">
          <h3>Details</h3>
          <dl className="definition-list">
            <dt>Template Code</dt><dd>{template.template_code}</dd>
            <dt>Channel</dt><dd>{template.channel}</dd>
            <dt>Version</dt><dd>{template.version}</dd>
            <dt>Language</dt><dd>{template.language}</dd>
          </dl>
        </div>

        <div className="card">
          <h3>Template Content</h3>
          {editing ? (
            <div className="form">
              <label>Subject Template<input value={subject} onChange={(e) => setSubject(e.target.value)} /></label>
              <label>Body Template<textarea rows={8} value={body} onChange={(e) => setBody(e.target.value)} /></label>
              <div className="modal-actions">
                <button className="btn btn-ghost" onClick={() => setEditing(false)}>Cancel</button>
                <button className="btn btn-primary" onClick={save}>Save (bumps version)</button>
              </div>
            </div>
          ) : (
            <>
              {template.subject_template && <p><strong>Subject:</strong> {template.subject_template}</p>}
              <pre className="json-block">{template.body_template}</pre>
            </>
          )}
        </div>
      </div>
    </div>
  );
}
