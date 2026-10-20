import { HelpLabel, HelpSelect, HelpInput } from '../../../components/FieldControls';
import { useState } from 'react';
import { useParams } from 'react-router-dom';
import { productsApi } from '../../../api/endpoints';
import { useApiResource } from '../../../api/useApi';
import { LoadingSpinner } from '../../../components/LoadingSpinner';
import { ErrorAlert } from '../../../components/ErrorAlert';
import { StatusBadge } from '../../../components/StatusBadge';
import { LifecycleActions } from '../../../components/LifecycleActions';
import { ConfirmDialog } from '../../../components/ConfirmDialog';
import { PermissionGuard } from '../../../auth/PermissionGuard';
import { useAuth } from '../../../auth/useAuth';
import { unwrapError } from '../../../api/client';
import type { Application, Product } from '../../../api/types';

export function ProductDetailPage() {
  const { id } = useParams<{ id: string }>();
  const { hasPermission } = useAuth();
  const [applicationVersion, setApplicationVersion] = useState(0);
  const { data: product, loading, error, reload } = useApiResource<Product>(() => productsApi.get(id!), [id]);

  if (loading) return <LoadingSpinner />;
  if (error) return <ErrorAlert message={error} />;
  if (!product) return null;

  return (
    <div>
      <div className="page-header">
        <h1>{product.name}</h1>
        <StatusBadge status={product.status} />
      </div>

      <LifecycleActions
        status={product.status}
        hasPermission={hasPermission}
        onDone={reload}
        actions={[
          { key: 'activate', label: 'Activate', allowedFrom: ['DRAFT', 'INACTIVE'], permission: 'cgo.product.activate', action: () => productsApi.activate(product.id) },
          { key: 'deactivate', label: 'Deactivate', allowedFrom: ['ACTIVE'], permission: 'cgo.product.deactivate', action: () => productsApi.deactivate(product.id) },
          { key: 'retire', label: 'Retire', allowedFrom: ['ACTIVE', 'INACTIVE'], permission: 'cgo.product.retire', danger: true, action: () => productsApi.retire(product.id) },
        ]}
      />

      <div className="detail-grid">
        <div className="card">
          <h3>Details</h3>
          <dl className="definition-list">
            <dt>Product Code</dt><dd>{product.product_code}</dd>
            <dt>Description</dt><dd>{product.description ?? '—'}</dd>
            <dt>Currency</dt><dd>{product.currency ?? '—'}</dd>
            <dt>Created</dt><dd>{new Date(product.created_at).toLocaleString()}</dd>
          </dl>
        </div>

        <ProductApplications productId={product.id} onChanged={() => setApplicationVersion((version) => version + 1)} />
        <ProductCapabilities key={`${product.id}:${applicationVersion}`} productId={product.id} />
      </div>
    </div>
  );
}

function ProductApplications({ productId, onChanged }: { productId: string; onChanged: () => void }) {
  const { hasPermission } = useAuth();
  const { data: attached, loading, error, reload } = useApiResource<Application[]>(() => productsApi.applications(productId), [productId]);
  const { data: allApplications, loading: optionsLoading, error: optionsError } = useApiResource(() => hasPermission('cgo.product.application.assign') ? productsApi.applicationOptions() : Promise.resolve([]), []);
  const [selected, setSelected] = useState('');
  const [pendingDetach, setPendingDetach] = useState<Application | null>(null);
  const [actionError, setActionError] = useState<string | null>(null);

  const attachedIds = new Set((attached ?? []).map((a) => a.id));
  const assignable = (allApplications ?? []).filter((a) => !attachedIds.has(a.id));

  async function attach() {
    if (!selected) return;
    setActionError(null);
    try {
      await productsApi.attachApplication(productId, selected);
      setSelected('');
      reload();
      onChanged();
    } catch (err) {
      setActionError(unwrapError(err).message);
    }
  }

  async function confirmDetach() {
    if (!pendingDetach) return;
    setActionError(null);
    try {
      await productsApi.detachApplication(productId, pendingDetach.id);
      reload();
      onChanged();
    } catch (err) {
      setActionError(unwrapError(err).message);
    } finally {
      setPendingDetach(null);
    }
  }

  return (
    <div className="card">
      <h3>Attached Applications</h3>
      <ErrorAlert message={error ?? actionError ?? optionsError} />
      {loading ? (
        <LoadingSpinner />
      ) : (
        <ul className="chip-list">
          {(attached ?? []).map((a) => (
            <li key={a.id} className="chip removable">
              {a.name} <code>{a.application_code}</code>
              <PermissionGuard permission="cgo.product.application.revoke">
                <button onClick={() => setPendingDetach(a)} aria-label={`Detach ${a.name}`}>×</button>
              </PermissionGuard>
            </li>
          ))}
          {!attached?.length && <span className="muted">No applications attached.</span>}
        </ul>
      )}

      <PermissionGuard permission="cgo.product.application.assign">
        <div className="toolbar">
          <HelpSelect aria-label="Application to attach" disabled={optionsLoading || !!optionsError} help="Select the registered application to which this configuration applies." value={selected} onChange={(e) => setSelected(e.target.value)}>
            <option value="">Select application...</option>
            {assignable.map((a) => (
              <option key={a.id} value={a.id}>{a.name}</option>
            ))}
          </HelpSelect>
          <button className="btn btn-secondary" onClick={attach} disabled={!selected || optionsLoading || !!optionsError}>Attach Application</button>
        </div>
      </PermissionGuard>

      <ConfirmDialog
        open={!!pendingDetach}
        title="Detach Application"
        message={`Detach "${pendingDetach?.name}" from this product? Plans/subscriptions built on it are not affected retroactively, but new entitlement generation will no longer include it.`}
        confirmLabel="Detach"
        danger
        onConfirm={confirmDetach}
        onCancel={() => setPendingDetach(null)}
      />
    </div>
  );
}

function ProductCapabilities({ productId }: { productId: string }) {
  const { hasPermission } = useAuth();
  const resource = useApiResource(async () => {
    const [selected, options] = await Promise.all([
      productsApi.capabilities(productId), productsApi.capabilityOptions(productId),
    ]);
    return { selected, groups: options };
  }, [productId]);
  const [draft, setDraft] = useState<string[] | null>(null);
  const [saving, setSaving] = useState(false);
  const [actionError, setActionError] = useState<string | null>(null);
  const [saved, setSaved] = useState(false);
  const selectedIds = draft ?? resource.data?.selected.map((capability) => capability.id) ?? [];

  async function save() {
    setSaving(true);
    setActionError(null);
    setSaved(false);
    try {
      const selected = await productsApi.saveCapabilities(productId, selectedIds);
      resource.setData((current) => current ? { ...current, selected } : current);
      setDraft(null);
      setSaved(true);
    } catch (err) {
      setActionError(unwrapError(err).message);
    } finally {
      setSaving(false);
    }
  }

  if (resource.loading) return <div className="card"><LoadingSpinner /></div>;
  return (
    <div className="card">
      <h3>Capabilities</h3>
      <p className="muted">Select capabilities for each attached application, then save your selection.</p>
      <ErrorAlert message={resource.error ?? actionError} />
      {saved && <p role="status">Capabilities saved.</p>}
      {!resource.error && !resource.data?.groups.length && <p className="muted">Attach an application to choose its capabilities.</p>}
      {resource.data?.groups.map(({ application, capabilities }) => (
        <fieldset key={application.id} className="capability-group" disabled={saving}>
          <legend>{application.name} <HelpLabel className="check-inline"><HelpInput type="checkbox" help="Select or clear all capabilities for this application. Save Capabilities applies the selection."
            checked={capabilities.length > 0 && capabilities.every((c) => selectedIds.includes(c.id))}
            disabled={!hasPermission('cgo.product.capability.assign') || !hasPermission('cgo.product.capability.revoke') || !capabilities.length}
            onChange={(event) => setDraft(event.target.checked ? [...new Set([...selectedIds, ...capabilities.map((c) => c.id)])] : selectedIds.filter((id) => !capabilities.some((c) => c.id === id)))} />Select All</HelpLabel></legend>
          {!capabilities.length && <p className="muted">No capabilities registered for this application.</p>}
          {capabilities.map((capability) => {
            const checked = selectedIds.includes(capability.id);
            return (
              <HelpLabel key={capability.id} className="checkbox-label">
                <HelpInput help="Check to include this capability in the product; uncheck to remove it. Save Capabilities applies all choices." type="checkbox" checked={checked}
                  disabled={!hasPermission(resource.data?.selected.some((selected) => selected.id === capability.id) ? 'cgo.product.capability.revoke' : 'cgo.product.capability.assign')}
                  onChange={(event) => {
                    setDraft(event.target.checked ? [...selectedIds, capability.id] : selectedIds.filter((id) => id !== capability.id));
                    setSaved(false);
                  }} />
                {capability.name} <code>{capability.code}</code>
              </HelpLabel>
            );
          })}
        </fieldset>
      ))}
      {(hasPermission('cgo.product.capability.assign') || hasPermission('cgo.product.capability.revoke')) && (
        <button className="btn btn-primary" onClick={save} disabled={saving || draft === null || !!resource.error}>
          {saving ? 'Saving...' : 'Save Capabilities'}
        </button>
      )}
    </div>
  );
}
