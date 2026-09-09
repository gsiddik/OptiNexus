import { useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { billingApi, type BillingRunResultRow } from '../../../api/endpoints';
import { useApiList } from '../../../api/useApi';
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
              { key: 'tenant', header: 'Tenant', render: (r) => r.tenant_id },
              { key: 'subscription', header: 'Subscription', render: (r) => r.subscription_id },
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
      <ErrorAlert message={error} />
      <p className="muted">Provide a subscription ID for a single run, or a tenant ID to bill every active subscription for that tenant.</p>
      <label>Subscription ID<input value={form.subscription_id} onChange={(e) => setForm({ ...form, subscription_id: e.target.value })} /></label>
      <label>Tenant ID<input value={form.tenant_id} onChange={(e) => setForm({ ...form, tenant_id: e.target.value })} /></label>
      <label>Period Start (optional)<input type="date" value={form.period_start} onChange={(e) => setForm({ ...form, period_start: e.target.value })} /></label>
      <label>Period End (optional)<input type="date" value={form.period_end} onChange={(e) => setForm({ ...form, period_end: e.target.value })} /></label>
      <div className="modal-actions">
        <button className="btn btn-primary" onClick={submit} disabled={submitting || (!form.subscription_id && !form.tenant_id)}>Run</button>
      </div>
    </div>
  );
}
