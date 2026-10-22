import { formatDateTime } from '../../i18n';
import { useState, type FormEvent } from 'react';
import { HelpLabel, HelpInput, HelpSelect } from '../../components/FieldControls';
import { unwrapError } from '../../api/client';
import { useParams } from 'react-router-dom';
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
          {hasPermission('cgo.customer.update') && <ContactForm key={customer.updated_at} customer={customer} onSaved={reload} />}
          <dl className="definition-list">
            <dt>Customer Code</dt><dd>{customer.customer_code}</dd>
            <dt>Business Name</dt><dd>{customer.business_name ?? '—'}</dd>
            <dt>Tax ID</dt><dd>{customer.tax_id ?? '—'}</dd>
            <dt>Industry</dt><dd>{customer.industry ?? '—'}</dd>
            <dt>Email</dt><dd>{customer.email ?? '—'}</dd>
            <dt>Phone</dt><dd>{customer.phone ?? '—'}</dd>
            <dt>Created</dt><dd>{formatDateTime(customer.created_at)}</dd>
          </dl>
        </div>
      </div>
    </div>
  );
}

function ContactForm({ customer, onSaved }: { customer: Customer; onSaved: () => void }) {
  const [form, setForm] = useState({ business_name: customer.business_name ?? '', tax_id: customer.tax_id ?? '', industry: customer.industry ?? '', email: customer.email ?? '', phone: customer.phone ?? '' });
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  async function submit(event: FormEvent) {
    event.preventDefault(); setBusy(true); setError(null);
    try {
      await customersApi.update(customer.id, { ...form, business_name: form.business_name || null, tax_id: form.tax_id || null, industry: form.industry || null, phone: form.phone || null });
      onSaved();
    } catch (err) { setError(unwrapError(err).message); } finally { setBusy(false); }
  }
  return <form className="form" onSubmit={submit}><ErrorAlert message={error} />
    <HelpLabel>Business Name (optional)<HelpInput help="Enter the customer's trading or business name." value={form.business_name} maxLength={255} onChange={(e) => setForm({ ...form, business_name: e.target.value })} /></HelpLabel>
    <HelpLabel>Tax ID (optional)<HelpInput help="Enter the customer's tax registration number." value={form.tax_id} maxLength={64} onChange={(e) => setForm({ ...form, tax_id: e.target.value })} /></HelpLabel>
    <HelpLabel>Industry (optional)<HelpSelect help="Choose the customer's industry." value={form.industry} onChange={(e) => setForm({ ...form, industry: e.target.value })}><option value="">Select an industry...</option>{['Transportation', 'Logistic', 'Government', 'Other'].map((value) => <option key={value}>{value}</option>)}</HelpSelect></HelpLabel>
    <HelpLabel>Email<HelpInput help="Enter a valid contact email address. This field is required." type="email" required maxLength={255} value={form.email} onChange={(e) => setForm({ ...form, email: e.target.value })} /></HelpLabel>
    <HelpLabel>Phone (optional)<HelpInput help="Start with 0 or +, followed by digits only, at most 15 digits." type="tel" pattern="(?:0[0-9]{0,14}|[+][0-9]{1,15})" maxLength={16} value={form.phone} onChange={(e) => setForm({ ...form, phone: e.target.value })} /></HelpLabel>
    <button className="btn btn-primary" disabled={busy}>Save Customer</button>
  </form>;
}
