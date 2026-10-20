import { ActiveSelect } from '../../../components/ActiveSelect';
import { useI18n } from '../../../i18n';
import { HelpLabel, HelpSelect, HelpInput } from '../../../components/FieldControls';
import { useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { subscriptionsApi } from '../../../api/endpoints';
import { useApiList, useApiResource } from '../../../api/useApi';
import { DataTable } from '../../../components/DataTable';
import { Pagination } from '../../../components/Pagination';
import { StatusBadge } from '../../../components/StatusBadge';
import { LoadingSpinner } from '../../../components/LoadingSpinner';
import { ErrorAlert } from '../../../components/ErrorAlert';
import { Modal } from '../../../components/Modal';
import { PermissionGuard } from '../../../auth/PermissionGuard';
import { unwrapError } from '../../../api/client';
import type { Subscription } from '../../../api/types';

export function SubscriptionsListPage() {
  const navigate = useNavigate();
  const { locale } = useI18n();
  const { rows, meta, setPage, loading, error, reload } = useApiList<Subscription>((p) => subscriptionsApi.list({ page: p }));
  const [showCreate, setShowCreate] = useState(false);

  return (
    <div>
      <div className="page-header">
        <h1>Subscriptions</h1>
        <PermissionGuard permission="cgo.subscription.create">
          <button className="btn btn-primary" onClick={() => setShowCreate(true)}>+ New Subscription</button>
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
            onRowClick={(r) => navigate(`/subscriptions/${r.id}`)}
            columns={[
              { key: 'number', header: 'Subscription #', render: (r) => r.subscription_number },
              { key: 'tenant', header: 'Tenant', render: (r) => r.tenant_name ?? '—' },
              { key: 'plan', header: 'Plan', render: (r) => r.plan_name ?? '—' },
              { key: 'status', header: 'Status', render: (r) => <StatusBadge status={r.status} /> },
              { key: 'period_end', header: 'Current Period End', render: (r) => r.current_period_end ? new Date(r.current_period_end).toLocaleString(locale === 'id' ? 'id-ID' : 'en-US') : '—' },
            ]}
          />
          <Pagination meta={meta} onPageChange={setPage} />
        </>
      )}

      <Modal open={showCreate} title="New Subscription" onClose={() => setShowCreate(false)}>
        <CreateSubscriptionForm onCreated={() => { setShowCreate(false); reload(); }} />
      </Modal>
    </div>
  );
}

function CreateSubscriptionForm({ onCreated }: { onCreated: () => void }) {
  const [form, setForm] = useState({ customer_id: '', tenant_id: '', plan_id: '', idempotency_key: '', auto_renew: true });
  const customers = useApiResource(() => subscriptionsApi.customerOptions());
  const tenants = useApiResource(() => form.customer_id
    ? subscriptionsApi.tenantOptions(form.customer_id)
    : Promise.resolve([]), [form.customer_id]);
  const tenantOptions = (tenants.data ?? []).filter((tenant) => tenant.customer_id === form.customer_id);
  const [error, setError] = useState<string | null>(null);
  const [submitting, setSubmitting] = useState(false);

  async function handleSubmit() {
    setSubmitting(true);
    setError(null);
    try {
      await subscriptionsApi.create({
        customer_id: form.customer_id,
        tenant_id: form.tenant_id,
        plan_id: form.plan_id,
        idempotency_key: form.idempotency_key || undefined,
        auto_renew: form.auto_renew,
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
      <ErrorAlert message={error ?? customers.error ?? tenants.error} />
      <HelpLabel>Customer ID<HelpSelect help="Select a registered customer. Subscription tenant choices are limited to the selected customer." value={form.customer_id} onChange={(e) => setForm({ ...form, customer_id: e.target.value, tenant_id: '' })} disabled={customers.loading}>
        <option value="">Select a customer...</option>
        {(customers.data ?? []).map((customer) => <option key={customer.id} value={customer.id}>{customer.legal_name} — {customer.customer_code} ({customer.id})</option>)}
      </HelpSelect></HelpLabel>
      <HelpLabel>Tenant ID<HelpSelect help="Select a tenant belonging to the selected customer. Changing the customer clears this choice." value={form.tenant_id} onChange={(e) => setForm({ ...form, tenant_id: e.target.value })} disabled={!form.customer_id || tenants.loading || !!tenants.error}>
        <option value="">{!form.customer_id ? 'Select a customer first...' : tenants.loading ? 'Loading tenants...' : !tenantOptions.length ? 'No tenants for this customer' : 'Select a tenant...'}</option>
        {tenantOptions.map((tenant) => <option key={tenant.id} value={tenant.id}>{tenant.name} — {tenant.tenant_code} ({tenant.id})</option>)}
      </HelpSelect></HelpLabel>
      <HelpLabel>Plan ID<ActiveSelect kind="plan" value={form.plan_id} onChange={(e) => setForm({ ...form, plan_id: e.target.value })} /></HelpLabel>
      <HelpLabel>Idempotency Key (optional)<HelpInput help="Optional: reuse the same key when retrying the same operation to prevent duplicate processing." value={form.idempotency_key} onChange={(e) => setForm({ ...form, idempotency_key: e.target.value })} /></HelpLabel>
      <HelpLabel>
        <HelpInput help="Enable automatic renewal at the end of each subscription billing period." type="checkbox" checked={form.auto_renew} onChange={(e) => setForm({ ...form, auto_renew: e.target.checked })} /> Auto Renew
      </HelpLabel>
      <div className="modal-actions">
        <button className="btn btn-primary" onClick={handleSubmit} disabled={submitting || customers.loading || tenants.loading || !!customers.error || !!tenants.error || !tenantOptions.some((tenant) => tenant.id === form.tenant_id)}>Create</button>
      </div>
    </div>
  );
}
