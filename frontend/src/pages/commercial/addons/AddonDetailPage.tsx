import { HelpLabel, HelpInput } from '../../../components/FieldControls';
import { useState } from 'react';
import { useParams } from 'react-router-dom';
import { addonsApi } from '../../../api/endpoints';
import { useApiResource } from '../../../api/useApi';
import { LoadingSpinner } from '../../../components/LoadingSpinner';
import { ErrorAlert } from '../../../components/ErrorAlert';
import { StatusBadge } from '../../../components/StatusBadge';
import { LifecycleActions } from '../../../components/LifecycleActions';
import { ConfirmDialog } from '../../../components/ConfirmDialog';
import { CapabilityPicker } from '../../../components/CapabilityPicker';
import { PermissionGuard } from '../../../auth/PermissionGuard';
import { useAuth } from '../../../auth/useAuth';
import { unwrapError } from '../../../api/client';
import type { Addon, AddonLimit, Capability } from '../../../api/types';

export function AddonDetailPage() {
  const { id } = useParams<{ id: string }>();
  const { hasPermission } = useAuth();
  const { data: addon, loading, error, reload } = useApiResource<Addon>(() => addonsApi.get(id!), [id]);
  const { data: limits, reload: reloadLimits } = useApiResource<AddonLimit[]>(() => addonsApi.limits(id!), [id]);

  const [pendingLimit, setPendingLimit] = useState<AddonLimit | null>(null);
  const [removingLimit, setRemovingLimit] = useState(false);
  const [limitError, setLimitError] = useState<string | null>(null);
  async function removeLimit() {
    if (!pendingLimit || !addon || removingLimit) return;
    setRemovingLimit(true);
    setLimitError(null);
    try {
      await addonsApi.removeLimit(addon.id, pendingLimit.id);
      setPendingLimit(null);
      reloadLimits();
    } catch (err) { setLimitError(unwrapError(err).message); }
    finally { setRemovingLimit(false); }
  }

  if (loading) return <LoadingSpinner />;
  if (error) return <ErrorAlert message={error} />;
  if (!addon) return null;

  return (
    <div>
      <div className="page-header">
        <h1>{addon.name}</h1>
        <StatusBadge status={addon.status} />
      </div>

      <LifecycleActions
        status={addon.status}
        hasPermission={hasPermission}
        onDone={reload}
        actions={[
          { key: 'activate', label: 'Activate', allowedFrom: ['DRAFT', 'INACTIVE'], permission: 'cgo.addon.activate', action: () => addonsApi.activate(addon.id) },
          { key: 'deactivate', label: 'Deactivate', allowedFrom: ['ACTIVE'], permission: 'cgo.addon.deactivate', action: () => addonsApi.deactivate(addon.id) },
          { key: 'retire', label: 'Retire', allowedFrom: ['ACTIVE', 'INACTIVE'], permission: 'cgo.addon.retire', danger: true, action: () => addonsApi.retire(addon.id) },
        ]}
      />

      <div className="detail-grid">
        <div className="card">
          <h3>Details</h3>
          <dl className="definition-list">
            <dt>Addon Code</dt><dd>{addon.addon_code}</dd>
            <dt>Product Name</dt><dd>{addon.product_name ?? '—'}</dd>
            <dt>Description</dt><dd>{addon.description ?? '—'}</dd>
          </dl>
        </div>

        <div className="card">
          <h3>Limits</h3>
          <ErrorAlert message={limitError} />
          {(limits ?? []).length === 0 ? (
            <p className="muted">No limit deltas configured.</p>
          ) : (
            <table className="data-table">
              <thead>
                <tr><th>Key</th><th>Delta</th><th>Unit</th>{hasPermission('cgo.addon.limit.configure') && <th>Action</th>}</tr>
              </thead>
              <tbody>
                {limits!.map((l) => (
                  <tr key={l.id}>
                    <td>{l.limit_key}</td>
                    <td>{l.limit_delta}</td>
                    <td>{l.unit ?? '—'}</td>
                    {hasPermission('cgo.addon.limit.configure') && <td><button className="btn btn-danger btn-sm" disabled={removingLimit} aria-label={`Remove limit ${l.limit_key}`} onClick={() => { setLimitError(null); setPendingLimit(l); }}>Remove</button></td>}
                  </tr>
                ))}
              </tbody>
            </table>
          )}
          <PermissionGuard permission="cgo.addon.limit.configure">
            <AddLimitForm addonId={addon.id} onAdded={reloadLimits} />
          </PermissionGuard>
        </div>

        <ConfirmDialog open={!!pendingLimit} title="Remove Add-on Limit" message={<>Remove limit "{pendingLimit?.limit_key}" from this add-on and its existing subscriptions?<ErrorAlert message={limitError} /></>} confirmLabel="Remove" danger busy={removingLimit} onConfirm={removeLimit} onCancel={() => { if (!removingLimit) setPendingLimit(null); }} />
        <AddonCapabilities addonId={addon.id} productId={addon.product_id} />
      </div>
    </div>
  );
}

/**
 * Add-ons have no direct Application relationship of their own. When the
 * add-on belongs to a product, the picker is scoped to that product's
 * attached applications (mirroring PlanCapabilities); an unassigned
 * add-on falls back to the full published application list so it still
 * has something to attach from.
 */
function AddonCapabilities({ addonId, productId }: { addonId: string; productId: string | null }) {
  const { data: capabilities, loading, error, reload } = useApiResource<Capability[]>(() => addonsApi.capabilities(addonId), [addonId]);
  const { data: groups, error: optionsError } = useApiResource(() => addonsApi.capabilityOptions(addonId), [addonId, productId]);
  const [pendingDetach, setPendingDetach] = useState<Capability | null>(null);
  const [actionError, setActionError] = useState<string | null>(null);


  async function attach(capabilityId: string) {
    setActionError(null);
    try {
      await addonsApi.attachCapability(addonId, capabilityId);
      reload();
    } catch (err) {
      setActionError(unwrapError(err).message);
      throw err;
    }
  }

  async function confirmDetach() {
    if (!pendingDetach) return;
    setActionError(null);
    try {
      await addonsApi.detachCapability(addonId, pendingDetach.id);
      reload();
    } catch (err) {
      setActionError(unwrapError(err).message);
    } finally {
      setPendingDetach(null);
    }
  }

  return (
    <div className="card">
      <h3>Capabilities</h3>
      <ErrorAlert message={error ?? optionsError ?? actionError} />
      {loading ? (
        <LoadingSpinner />
      ) : (
        <ul className="chip-list">
          {(capabilities ?? []).map((c) => (
            <li key={c.id} className="chip removable">
              {c.name} <code>{c.code}</code>
              <PermissionGuard permission="cgo.addon.capability.revoke">
                <button onClick={() => setPendingDetach(c)} aria-label={`Detach ${c.name}`}>×</button>
              </PermissionGuard>
            </li>
          ))}
          {!capabilities?.length && <span className="muted">No capabilities included.</span>}
        </ul>
      )}

      <PermissionGuard permission="cgo.addon.capability.assign">
        <CapabilityPicker groups={groups ?? []} attachedIds={(capabilities ?? []).map((c) => c.id)} onAttach={attach} />
      </PermissionGuard>

      <ConfirmDialog
        open={!!pendingDetach}
        title="Detach Capability"
        message={`Remove "${pendingDetach?.name}" from this add-on?`}
        confirmLabel="Detach"
        danger
        onConfirm={confirmDetach}
        onCancel={() => setPendingDetach(null)}
      />
    </div>
  );
}

function AddLimitForm({ addonId, onAdded }: { addonId: string; onAdded: () => void }) {
  const [form, setForm] = useState({ limit_key: '', limit_delta: '', unit: '' });
  const [error, setError] = useState<string | null>(null);
  const [submitting, setSubmitting] = useState(false);

  async function submit() {
    setSubmitting(true);
    setError(null);
    try {
      await addonsApi.addLimit(addonId, { limit_key: form.limit_key, limit_delta: form.limit_delta, unit: form.unit || null });
      setForm({ limit_key: '', limit_delta: '', unit: '' });
      onAdded();
    } catch (err) {
      setError(unwrapError(err).message);
    } finally {
      setSubmitting(false);
    }
  }

  return (
    <div className="form form-inline" style={{ marginTop: '1rem' }}>
      <ErrorAlert message={error} />
      <HelpLabel>Key<HelpInput help="Enter the limit key used by the consuming application, for example seats or vehicle_limit." value={form.limit_key} onChange={(e) => setForm({ ...form, limit_key: e.target.value })} /></HelpLabel>
      <HelpLabel>Delta<HelpInput help="Enter the quantity added by this add-on; a negative number reduces the limit." value={form.limit_delta} onChange={(e) => setForm({ ...form, limit_delta: e.target.value })} /></HelpLabel>
      <HelpLabel>Unit<HelpInput help="Enter a measurement unit, for example seat, vehicle, or device." value={form.unit} onChange={(e) => setForm({ ...form, unit: e.target.value })} /></HelpLabel>
      <button className="btn btn-secondary" onClick={submit} disabled={submitting || !form.limit_key}>Add Limit</button>
    </div>
  );
}
