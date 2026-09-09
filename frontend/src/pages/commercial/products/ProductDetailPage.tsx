import { useParams, Link } from 'react-router-dom';
import { productsApi } from '../../../api/endpoints';
import { useApiResource } from '../../../api/useApi';
import { LoadingSpinner } from '../../../components/LoadingSpinner';
import { ErrorAlert } from '../../../components/ErrorAlert';
import { StatusBadge } from '../../../components/StatusBadge';
import { LifecycleActions } from '../../../components/LifecycleActions';
import { useAuth } from '../../../auth/useAuth';
import type { Application, Capability, Product } from '../../../api/types';

export function ProductDetailPage() {
  const { id } = useParams<{ id: string }>();
  const { hasPermission } = useAuth();
  const { data: product, loading, error, reload } = useApiResource<Product>(() => productsApi.get(id!), [id]);
  const { data: applications } = useApiResource<Application[]>(() => productsApi.applications(id!), [id]);
  const { data: capabilities } = useApiResource<Capability[]>(() => productsApi.capabilities(id!), [id]);

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

        <div className="card">
          <h3>Attached Applications</h3>
          {(applications ?? []).length === 0 ? (
            <p className="muted">No applications attached.</p>
          ) : (
            <ul className="simple-list">
              {applications!.map((a) => (
                <li key={a.id}>{a.name} <code>{a.application_code}</code></li>
              ))}
            </ul>
          )}
        </div>

        <div className="card">
          <h3>Capabilities</h3>
          {(capabilities ?? []).length === 0 ? (
            <p className="muted">No capabilities attached.</p>
          ) : (
            <ul className="simple-list">
              {capabilities!.map((c) => (
                <li key={c.id}>{c.name} <code>{c.code}</code></li>
              ))}
            </ul>
          )}
        </div>
      </div>
    </div>
  );
}
