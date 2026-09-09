import { useState } from 'react';
import { Link, useParams } from 'react-router-dom';
import { integrationsApi } from '../../../api/endpoints';
import { useApiResource } from '../../../api/useApi';
import { LoadingSpinner } from '../../../components/LoadingSpinner';
import { ErrorAlert } from '../../../components/ErrorAlert';
import { StatusBadge } from '../../../components/StatusBadge';
import { LifecycleActions } from '../../../components/LifecycleActions';
import { Modal } from '../../../components/Modal';
import { PermissionGuard } from '../../../auth/PermissionGuard';
import { useAuth } from '../../../auth/useAuth';
import { unwrapError } from '../../../api/client';
import type { IntegrationLog } from '../../../api/types';

export function IntegrationDetailPage() {
  const { id } = useParams<{ id: string }>();
  const { hasPermission } = useAuth();
  const { data: integration, loading, error, reload } = useApiResource(() => integrationsApi.get(id!), [id]);
  const [testResult, setTestResult] = useState<{ ok: boolean; status?: number; error?: string } | null>(null);
  const [testError, setTestError] = useState<string | null>(null);
  const [showCredential, setShowCredential] = useState<'store' | 'rotate' | null>(null);

  if (loading) return <LoadingSpinner />;
  if (error) return <ErrorAlert message={error} />;
  if (!integration) return null;

  async function runTest() {
    setTestError(null);
    setTestResult(null);
    try {
      setTestResult(await integrationsApi.test(integration!.id));
    } catch (err) {
      setTestError(unwrapError(err).message);
    }
  }

  async function revokeCredential() {
    setTestError(null);
    try {
      await integrationsApi.revokeCredential(integration!.id);
      reload();
    } catch (err) {
      setTestError(unwrapError(err).message);
    }
  }

  return (
    <div>
      <Link to="/integrations" className="back-link">&larr; Back to Integrations</Link>
      <div className="page-header">
        <h1>{integration.name}</h1>
        <StatusBadge status={integration.status} />
      </div>

      <ErrorAlert message={testError} />

      <LifecycleActions
        status={integration.status}
        hasPermission={hasPermission}
        onDone={reload}
        actions={[
          { key: 'activate', label: 'Activate', allowedFrom: ['DRAFT', 'INACTIVE'], permission: 'cgo.integration.activate', action: () => integrationsApi.activate(integration.id) },
          { key: 'deactivate', label: 'Deactivate', allowedFrom: ['ACTIVE'], permission: 'cgo.integration.activate', danger: true, action: () => integrationsApi.deactivate(integration.id) },
        ]}
      />

      <div className="toolbar">
        <PermissionGuard permission="cgo.integration.test">
          <button className="btn btn-secondary" onClick={runTest}>Test Connection</button>
        </PermissionGuard>
        <PermissionGuard permission="cgo.integration.credential.rotate">
          <button className="btn btn-secondary" onClick={() => setShowCredential(integration.has_active_credential ? 'rotate' : 'store')}>
            {integration.has_active_credential ? 'Rotate Credential' : 'Add Credential'}
          </button>
          {integration.has_active_credential && <button className="btn btn-danger" onClick={revokeCredential}>Revoke Credential</button>}
        </PermissionGuard>
      </div>

      {testResult && (
        <div className={`alert ${testResult.ok ? 'alert-success' : 'alert-error'}`}>
          {testResult.ok ? `Connection succeeded (HTTP ${testResult.status}).` : `Connection failed: ${testResult.error ?? 'unknown error'}`}
        </div>
      )}

      <div className="detail-grid">
        <div className="card">
          <h3>Details</h3>
          <dl className="definition-list">
            <dt>Integration Code</dt><dd>{integration.integration_code}</dd>
            <dt>Type</dt><dd>{integration.integration_type}</dd>
            <dt>Base URL</dt><dd>{integration.base_url ?? '—'}</dd>
            <dt>Timeout</dt><dd>{integration.timeout_seconds}s</dd>
            <dt>Active Credential</dt><dd>{integration.has_active_credential ? 'Yes (secret never displayed)' : 'None'}</dd>
          </dl>
        </div>

        <div className="card">
          <h3>Endpoints</h3>
          {!integration.endpoints?.length && <p className="muted">No endpoints registered.</p>}
          <table className="data-table">
            <thead><tr><th>Key</th><th>Method</th><th>Path</th></tr></thead>
            <tbody>
              {integration.endpoints?.map((e) => <tr key={e.id}><td>{e.endpoint_key}</td><td>{e.method}</td><td>{e.path}</td></tr>)}
            </tbody>
          </table>
        </div>

        <DeliveryLogs integrationId={integration.id} />
      </div>

      <Modal open={!!showCredential} title={showCredential === 'rotate' ? 'Rotate Credential' : 'Add Credential'} onClose={() => setShowCredential(null)}>
        <CredentialForm
          isRotate={showCredential === 'rotate'}
          onSaved={() => { setShowCredential(null); reload(); }}
          onSubmit={(payload) => (showCredential === 'rotate' ? integrationsApi.rotateCredential(integration.id, payload) : integrationsApi.storeCredential(integration.id, payload))}
        />
      </Modal>
    </div>
  );
}

function DeliveryLogs({ integrationId }: { integrationId: string }) {
  const { data, loading, error } = useApiResource(() => integrationsApi.logs(integrationId, { per_page: 20 }).then((e) => e.data), [integrationId]);
  const logs = (data as IntegrationLog[] | null) ?? [];

  return (
    <div className="card">
      <h3>Delivery Logs</h3>
      <ErrorAlert message={error} />
      {loading ? <LoadingSpinner /> : (
        <table className="data-table">
          <thead><tr><th>Status</th><th>HTTP</th><th>Duration</th><th>Correlation</th><th>When</th></tr></thead>
          <tbody>
            {logs.map((l) => (
              <tr key={l.id}>
                <td><StatusBadge status={l.status} /></td>
                <td>{l.response_status ?? '—'}</td>
                <td>{l.duration_ms ? `${l.duration_ms}ms` : '—'}</td>
                <td>{l.correlation_id ? <code>{l.correlation_id.slice(0, 8)}</code> : '—'}</td>
                <td>{new Date(l.created_at).toLocaleString()}</td>
              </tr>
            ))}
            {!logs.length && <tr><td colSpan={5} className="empty-cell">No delivery logs yet.</td></tr>}
          </tbody>
        </table>
      )}
    </div>
  );
}

function CredentialForm({ isRotate, onSubmit, onSaved }: { isRotate: boolean; onSubmit: (payload: { credential_type: string; secret: string; reference_label: string }) => Promise<unknown>; onSaved: () => void }) {
  const [form, setForm] = useState({ credential_type: 'API_KEY', secret: '', reference_label: '' });
  const [error, setError] = useState<string | null>(null);
  const [submitting, setSubmitting] = useState(false);

  async function save() {
    setSubmitting(true);
    setError(null);
    try {
      await onSubmit(form);
      onSaved();
    } catch (err) {
      setError(unwrapError(err).message);
    } finally {
      setSubmitting(false);
    }
  }

  return (
    <div className="form">
      <ErrorAlert message={error} />
      {isRotate && <p className="muted">The current active credential will be marked ROTATED; this becomes the new active one.</p>}
      <label>
        Credential Type
        <select value={form.credential_type} onChange={(e) => setForm({ ...form, credential_type: e.target.value })}>
          <option value="API_KEY">API_KEY</option>
          <option value="BEARER">BEARER</option>
          <option value="BASIC">BASIC</option>
          <option value="OAUTH2">OAUTH2</option>
        </select>
      </label>
      <label>Secret<input type="password" value={form.secret} onChange={(e) => setForm({ ...form, secret: e.target.value })} /></label>
      <label>Reference Label<input value={form.reference_label} onChange={(e) => setForm({ ...form, reference_label: e.target.value })} /></label>
      <p className="muted">The secret is encrypted at rest and never returned by the API again after this submission.</p>
      <div className="modal-actions">
        <button className="btn btn-primary" onClick={save} disabled={submitting}>Save</button>
      </div>
    </div>
  );
}
