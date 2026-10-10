import { useState } from 'react';
import { useParams } from 'react-router-dom';
import { entitlementsApi } from '../../../api/endpoints';
import { useApiList, useApiResource } from '../../../api/useApi';
import { DataTable } from '../../../components/DataTable';
import { Pagination } from '../../../components/Pagination';
import { StatusBadge } from '../../../components/StatusBadge';
import { LoadingSpinner } from '../../../components/LoadingSpinner';
import { ErrorAlert } from '../../../components/ErrorAlert';
import { Modal } from '../../../components/Modal';
import { PermissionGuard } from '../../../auth/PermissionGuard';
import { unwrapError } from '../../../api/client';
import type { EffectiveEntitlement, Entitlement } from '../../../api/types';

export function TenantEntitlementsPage() {
  const { tenantId } = useParams<{ tenantId: string }>();
  const { rows, meta, setPage, loading, error, reload } = useApiList<Entitlement>(
    (p) => entitlementsApi.forTenant(tenantId!, { page: p }),
    [tenantId],
  );
  const { data: effective, loading: effectiveLoading, error: effectiveError } = useApiResource<{ tenant_id: string; entitlements: EffectiveEntitlement[] }>(
    () => entitlementsApi.effectiveForTenant(tenantId!),
    [tenantId],
  );
  const [showOverride, setShowOverride] = useState(false);
  const [rowError, setRowError] = useState<string | null>(null);

  async function suspend(e: Entitlement) {
    setRowError(null);
    try {
      await entitlementsApi.suspend(e.id);
      reload();
    } catch (err) {
      setRowError(unwrapError(err).message);
    }
  }

  async function restore(e: Entitlement) {
    setRowError(null);
    try {
      await entitlementsApi.restore(e.id);
      reload();
    } catch (err) {
      setRowError(unwrapError(err).message);
    }
  }

  return (
    <div>
      <div className="page-header">
        <h1>Entitlements for Tenant {tenantId}</h1>
        <PermissionGuard permission="cgo.entitlement.override">
          <button className="btn btn-primary" onClick={() => setShowOverride(true)}>+ Add Manual Override</button>
        </PermissionGuard>
      </div>

      <div className="card">
        <h3>Raw Entitlements</h3>
        <ErrorAlert message={error ?? rowError} />
        {loading ? (
          <LoadingSpinner />
        ) : (
          <>
            <DataTable
              rows={rows}
              rowKey={(r) => r.id}
              columns={[
                { key: 'type', header: 'Type', render: (r) => r.entitlement_type },
                { key: 'key', header: 'Key', render: (r) => r.entitlement_key },
                { key: 'value', header: 'Value', render: (r) => JSON.stringify(r.value) },
                { key: 'status', header: 'Status', render: (r) => <StatusBadge status={r.status} /> },
                { key: 'source', header: 'Source', render: (r) => r.source_type },
                {
                  key: 'actions',
                  header: 'Actions',
                  render: (r) => (
                    <>
                      {r.status === 'ACTIVE' && (
                        <PermissionGuard permission="cgo.entitlement.suspend">
                          <button className="btn btn-ghost" onClick={() => suspend(r)}>Suspend</button>
                        </PermissionGuard>
                      )}
                      {r.status === 'SUSPENDED' && (
                        <PermissionGuard permission="cgo.entitlement.restore">
                          <button className="btn btn-ghost" onClick={() => restore(r)}>Restore</button>
                        </PermissionGuard>
                      )}
                    </>
                  ),
                },
              ]}
            />
            <Pagination meta={meta} onPageChange={setPage} />
          </>
        )}
      </div>

      <div className="card" style={{ marginTop: '1rem' }}>
        <h3>Effective Entitlements (merged view)</h3>
        <ErrorAlert message={effectiveError} />
        {effectiveLoading ? (
          <LoadingSpinner />
        ) : (
          <DataTable
            rows={effective?.entitlements ?? []}
            rowKey={(r) => `${r.entitlement_type}-${r.entitlement_key}-${r.source}`}
            columns={[
              { key: 'type', header: 'Type', render: (r) => r.entitlement_type },
              { key: 'key', header: 'Key', render: (r) => r.entitlement_key },
              { key: 'value', header: 'Value', render: (r) => JSON.stringify(r.value) },
              { key: 'source', header: 'Source', render: (r) => r.source },
            ]}
          />
        )}
      </div>

      <Modal open={showOverride} title="Add Manual Override" onClose={() => setShowOverride(false)}>
        <OverrideForm tenantId={tenantId!} onCreated={() => { setShowOverride(false); reload(); }} />
      </Modal>
    </div>
  );
}

function OverrideForm({ tenantId, onCreated }: { tenantId: string; onCreated: () => void }) {
  const [form, setForm] = useState({ entitlement_type: 'LIMIT', entitlement_key: '', value: '', reason: '' });
  const [error, setError] = useState<string | null>(null);
  const [submitting, setSubmitting] = useState(false);

  async function submit() {
    setSubmitting(true);
    setError(null);
    try {
      let value: unknown = form.value;
      try {
        value = JSON.parse(form.value);
      } catch {
        // treat as plain string
      }
      await entitlementsApi.createOverride(tenantId, {
        entitlement_type: form.entitlement_type,
        entitlement_key: form.entitlement_key,
        value,
        reason: form.reason,
      });
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
      <label>
        Type
        <select value={form.entitlement_type} onChange={(e) => setForm({ ...form, entitlement_type: e.target.value })}>
          <option value="APPLICATION">APPLICATION</option>
          <option value="CAPABILITY">CAPABILITY</option>
          <option value="LIMIT">LIMIT</option>
        </select>
      </label>
      <label>Key<input value={form.entitlement_key} onChange={(e) => setForm({ ...form, entitlement_key: e.target.value })} /></label>
      <label>Value (JSON or plain text)<input value={form.value} onChange={(e) => setForm({ ...form, value: e.target.value })} /></label>
      <label>Reason (required)<input value={form.reason} onChange={(e) => setForm({ ...form, reason: e.target.value })} /></label>
      <div className="modal-actions">
        <button className="btn btn-primary" onClick={submit} disabled={submitting || !form.entitlement_key || !form.reason}>Create Override</button>
      </div>
    </div>
  );
}
