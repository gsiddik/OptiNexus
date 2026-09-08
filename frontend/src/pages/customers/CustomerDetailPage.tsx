import { useParams, Link } from 'react-router-dom';
import { customersApi } from '../../api/endpoints';
import { useApiResource } from '../../api/useApi';
import { LoadingSpinner } from '../../components/LoadingSpinner';
import { ErrorAlert } from '../../components/ErrorAlert';
import { StatusBadge } from '../../components/StatusBadge';
import { LifecycleActions } from '../../components/LifecycleActions';
import { useAuth } from '../../auth/useAuth';
import type { Customer } from '../../api/types';

export function CustomerDetailPage() {
  const { id } = useParams<{ id: string }>();
  const { hasPermission } = useAuth();
  const { data: customer, loading, error, reload } = useApiResource<Customer>(() => customersApi.get(id!), [id]);

  if (loading) return <LoadingSpinner />;
  if (error) return <ErrorAlert message={error} />;
  if (!customer) return null;

  return (
    <div>
      <Link to="/customers" className="back-link">&larr; Back to Customers</Link>
      <div className="page-header">
        <h1>{customer.legal_name}</h1>
        <StatusBadge status={customer.status} />
      </div>

      <LifecycleActions
        status={customer.status}
        hasPermission={hasPermission}
        onDone={reload}
        actions={[
          { key: 'activate', label: 'Activate', allowedFrom: ['SUSPENDED'], permission: 'cgo.customer.activate', action: () => customersApi.activate(customer.id) },
          { key: 'suspend', label: 'Suspend', allowedFrom: ['ACTIVE'], permission: 'cgo.customer.suspend', action: () => customersApi.suspend(customer.id) },
          { key: 'terminate', label: 'Terminate', allowedFrom: ['ACTIVE', 'SUSPENDED'], permission: 'cgo.customer.terminate', danger: true, action: () => customersApi.terminate(customer.id) },
          { key: 'archive', label: 'Archive', allowedFrom: ['ACTIVE', 'SUSPENDED', 'TERMINATED'], permission: 'cgo.customer.archive', danger: true, action: () => customersApi.archive(customer.id) },
        ]}
      />

      <div className="detail-grid">
        <div className="card">
          <h3>Details</h3>
          <dl className="definition-list">
            <dt>Customer Code</dt><dd>{customer.customer_code}</dd>
            <dt>Business Name</dt><dd>{customer.business_name ?? '—'}</dd>
            <dt>Tax ID</dt><dd>{customer.tax_id ?? '—'}</dd>
            <dt>Industry</dt><dd>{customer.industry ?? '—'}</dd>
            <dt>Email</dt><dd>{customer.email ?? '—'}</dd>
            <dt>Phone</dt><dd>{customer.phone ?? '—'}</dd>
            <dt>Created</dt><dd>{new Date(customer.created_at).toLocaleString()}</dd>
          </dl>
        </div>
      </div>
    </div>
  );
}
