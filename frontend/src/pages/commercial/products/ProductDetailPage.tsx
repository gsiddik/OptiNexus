import { useState } from 'react';
import { useParams, Link } from 'react-router-dom';
import { applicationsApi, productsApi } from '../../../api/endpoints';
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
import type { Application, Capability, Product } from '../../../api/types';

export function ProductDetailPage() {
  const { id } = useParams<{ id: string }>();
  const { hasPermission } = useAuth();
  const { data: product, loading, error, reload } = useApiResource<Product>(() => productsApi.get(id!), [id]);

  if (loading) return <LoadingSpinner />;
  if (error) return <ErrorAlert message={error} />;
  if (!product) return null;

  return (
    <div>
      <Link to="/products" className="back-link">&larr; Back to Products</Link>
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

        <ProductApplications productId={product.id} />
        <ProductCapabilities productId={product.id} />
      </div>
    </div>
  );
}

function ProductApplications({ productId }: { productId: string }) {
  const { data: attached, loading, error, reload } = useApiResource<Application[]>(() => productsApi.applications(productId), [productId]);
  const { data: allApplications } = useApiResource(() => applicationsApi.list({ per_page: 100 }).then((e) => e.data), []);
  const [selected, setSelected] = useState('');
  const [pendingDetach, setPendingDetach] = useState<Application | null>(null);
  const [actionError, setActionError] = useState<string | null>(null);

  const attachedIds = new Set((attached ?? []).map((a) => a.id));
  const assignable = ((allApplications as Application[] | null) ?? []).filter((a) => !attachedIds.has(a.id));

  async function attach() {
    if (!selected) return;
    setActionError(null);
    try {
      await productsApi.attachApplication(productId, selected);
      setSelected('');
      reload();
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
    } catch (err) {
      setActionError(unwrapError(err).message);
    } finally {
      setPendingDetach(null);
    }
  }

  return (
    <div className="card">
      <h3>Attached Applications</h3>
      <ErrorAlert message={error ?? actionError} />
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
          <select value={selected} onChange={(e) => setSelected(e.target.value)}>
            <option value="">Select application...</option>
            {assignable.map((a) => (
              <option key={a.id} value={a.id}>{a.name}</option>
            ))}
          </select>
          <button className="btn btn-secondary" onClick={attach} disabled={!selected}>Attach Application</button>
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
  const { data: capabilities, loading, error, reload } = useApiResource<Capability[]>(() => productsApi.capabilities(productId), [productId]);
  const { data: applications } = useApiResource<Application[]>(() => productsApi.applications(productId), [productId]);
  const [pendingDetach, setPendingDetach] = useState<Capability | null>(null);
  const [actionError, setActionError] = useState<string | null>(null);

  async function attach(capabilityId: string) {
    setActionError(null);
    try {
      await productsApi.attachCapability(productId, capabilityId);
      reload();
    } catch (err) {
      setActionError(unwrapError(err).message);
    }
  }

  async function confirmDetach() {
    if (!pendingDetach) return;
    setActionError(null);
    try {
      await productsApi.detachCapability(productId, pendingDetach.id);
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
      <ErrorAlert message={error ?? actionError} />
      {loading ? (
        <LoadingSpinner />
      ) : (
        <ul className="chip-list">
          {(capabilities ?? []).map((c) => (
            <li key={c.id} className="chip removable">
              {c.name} <code>{c.code}</code>
              <PermissionGuard permission="cgo.product.capability.revoke">
                <button onClick={() => setPendingDetach(c)} aria-label={`Detach ${c.name}`}>×</button>
              </PermissionGuard>
            </li>
          ))}
          {!capabilities?.length && <span className="muted">No capabilities attached.</span>}
        </ul>
      )}

      <PermissionGuard permission="cgo.product.capability.assign">
        <CapabilityPicker applications={applications ?? []} onAttach={attach} />
      </PermissionGuard>

      <ConfirmDialog
        open={!!pendingDetach}
        title="Detach Capability"
        message={`Detach "${pendingDetach?.name}" from this product?`}
        confirmLabel="Detach"
        danger
        onConfirm={confirmDetach}
        onCancel={() => setPendingDetach(null)}
      />
    </div>
  );
}
