import { HelpLabel, HelpSelect, HelpInput } from '../../../components/FieldControls';
import { useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { billingApi, formOptionsApi, type BillingRunResultRow } from '../../../api/endpoints';
import { useApiList, useApiResource } from '../../../api/useApi';
import { DataTable } from '../../../components/DataTable';
import { Pagination } from '../../../components/Pagination';
import { StatusBadge } from '../../../components/StatusBadge';
import { LoadingSpinner } from '../../../components/LoadingSpinner';
import { ErrorAlert } from '../../../components/ErrorAlert';
import { Modal } from '../../../components/Modal';
import { PermissionGuard } from '../../../auth/PermissionGuard';
import { unwrapError } from '../../../api/client';
import type { Billing } from '../../../api/types';

function isBilling(row: Billing | BillingRunResultRow): row is Billing {
  return 'billing_number' in row;
}

export function BillingsListPage() {
  const navigate = useNavigate();
  const { rows, meta, setPage, loading, error, reload } = useApiList<Billing>((p) => billingApi.list({ page: p }));
  const [showRun, setShowRun] = useState(false);
  const [runResults, setRunResults] = useState<Array<Billing | BillingRunResultRow> | null>(null);

  return (
    <div>
      <div className="page-header">
        <h1>Billings</h1>
        <PermissionGuard permission="cgo.billing.create">
          <button className="btn btn-primary" onClick={() => setShowRun(true)}>Run Billing</button>
        </PermissionGuard>
      </div>

      {runResults && (
        <div className="card" style={{ marginBottom: '1rem' }}>
          <h3>Last Billing Run Results</h3>
          <ul className="simple-list">
            {runResults.map((r, i) => (
              <li key={i}>
                {isBilling(r) ? (
                  <span>
                    <strong>{r.billing_number}</strong> — {r.status} — total {r.total} {r.currency}{' '}
                    <a href={`#`} onClick={(e) => { e.preventDefault(); navigate(`/billings/${r.id}`); }}>view</a>
                  </span>
                ) : (
                  <span className="alert alert-error" style={{ display: 'inline-block' }}>
                    subscription {r.subscription_id}: {r.error}
                  </span>
                )}
              </li>
            ))}
          </ul>
          <button className="btn btn-ghost" onClick={() => setRunResults(null)}>Dismiss</button>
        </div>
      )}

      <ErrorAlert message={error} />
      {loading ? (
        <LoadingSpinner />
      ) : (
        <>
          <DataTable
            rows={rows}
            rowKey={(r) => r.id}
            onRowClick={(r) => navigate(`/billings/${r.id}`)}
            columns={[
              { key: 'number', header: 'Billing #', render: (r) => r.billing_number },
              { key: 'tenant', header: 'Tenant', render: (r) => r.tenant_name ?? '—' },
              { key: 'subscription', header: 'Subscription', render: (r) => r.subscription_number ?? '—' },
              { key: 'status', header: 'Status', render: (r) => <StatusBadge status={r.status} /> },
              { key: 'total', header: 'Total', render: (r) => `${r.total} ${r.currency}` },
              { key: 'period', header: 'Period', render: (r) => `${r.period_start} → ${r.period_end}` },
            ]}
          />
          <Pagination meta={meta} onPageChange={setPage} />
        </>
      )}

      <Modal open={showRun} title="Run Billing" onClose={() => setShowRun(false)}>
        <RunBillingForm
          onRan={(results) => {
            setShowRun(false);
            setRunResults(results);
            reload();
          }}
        />
      </Modal>
    </div>
  );
}

function RunBillingForm({ onRan }: { onRan: (results: Array<Billing | BillingRunResultRow>) => void }) {
  const subscriptions = useApiResource(() => formOptionsApi.subscriptions(), []);
  const tenants = useApiResource(() => formOptionsApi.tenants(), []);
  const [form, setForm] = useState({ subscription_id: '', tenant_id: '', period_start: '', period_end: '' });
  const [error, setError] = useState<string | null>(null);
  const [submitting, setSubmitting] = useState(false);

  async function submit() {
    setSubmitting(true);
    setError(null);
    try {
      const res = await billingApi.run({
        subscription_id: form.subscription_id || undefined,
        tenant_id: form.tenant_id || undefined,
        period_start: form.period_start || undefined,
        period_end: form.period_end || undefined,
      });
      onRan(res.results);
    } catch (err) {
      setError(unwrapError(err).message);
    } finally {
      setSubmitting(false);
    }
  }

  return (
    <div className="form">
      <ErrorAlert message={error ?? subscriptions.error ?? tenants.error} />
      <p className="muted">Provide a subscription ID for a single run, or a tenant ID to bill every active subscription for that tenant.</p>
      <HelpLabel>Subscription ID<HelpSelect help="Select an active subscription. Its tenant is filled automatically." value={form.subscription_id} disabled={subscriptions.loading || !!subscriptions.error} onChange={(e) => { const subscription = subscriptions.data?.find((s) => s.id === e.target.value); setForm({ ...form, subscription_id: e.target.value, tenant_id: subscription?.tenant_id ?? form.tenant_id }); }}>
        <option value="">All active subscriptions for selected tenant</option>{(subscriptions.data ?? []).filter((s) => s.status === 'ACTIVE' && (!form.tenant_id || s.tenant_id === form.tenant_id)).map((s) => <option key={s.id} value={s.id}>{s.subscription_number}</option>)}
      </HelpSelect></HelpLabel>
      <HelpLabel>Tenant ID<HelpSelect help="Select an active tenant to filter subscriptions. Changing tenant clears the subscription." value={form.tenant_id} disabled={tenants.loading || !!tenants.error} onChange={(e) => setForm({ ...form, tenant_id: e.target.value, subscription_id: '' })}>
        <option value="">Select a tenant...</option>{(tenants.data ?? []).filter((t) => t.status === 'ACTIVE').map((t) => <option key={t.id} value={t.id}>{t.name}</option>)}
      </HelpSelect></HelpLabel>
      <HelpLabel>Period Start (optional)<HelpInput help="Optional billing period start date. Leave empty to use the subscription's current period." type="date" value={form.period_start} onChange={(e) => setForm({ ...form, period_start: e.target.value })} /></HelpLabel>
      <HelpLabel>Period End (optional)<HelpInput help="Optional billing period end date. It must follow the start date." type="date" value={form.period_end} onChange={(e) => setForm({ ...form, period_end: e.target.value })} /></HelpLabel>
      <div className="modal-actions">
        <button className="btn btn-primary" onClick={submit} disabled={submitting || subscriptions.loading || tenants.loading || !!subscriptions.error || !!tenants.error || (!form.subscription_id && !form.tenant_id)}>Run</button>
      </div>
    </div>
  );
}
