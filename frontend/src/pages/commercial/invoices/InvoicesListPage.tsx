import { useNavigate } from 'react-router-dom';
import { invoicesApi } from '../../../api/endpoints';
import { useApiList } from '../../../api/useApi';
import { DataTable } from '../../../components/DataTable';
import { Pagination } from '../../../components/Pagination';
import { StatusBadge } from '../../../components/StatusBadge';
import { LoadingSpinner } from '../../../components/LoadingSpinner';
import { ErrorAlert } from '../../../components/ErrorAlert';
import type { Invoice } from '../../../api/types';

export function InvoicesListPage() {
  const navigate = useNavigate();
  const { rows, meta, setPage, loading, error } = useApiList<Invoice>((p) => invoicesApi.list({ page: p }));

  return (
    <div>
      <div className="page-header">
        <h1>Invoices</h1>
      </div>
      <p className="muted">Invoices are generated from finalized billings — see a billing's detail page to generate one.</p>

      <ErrorAlert message={error} />
      {loading ? (
        <LoadingSpinner />
      ) : (
        <>
          <DataTable
            rows={rows}
            rowKey={(r) => r.id}
            onRowClick={(r) => navigate(`/invoices/${r.id}`)}
            columns={[
              { key: 'number', header: 'Invoice #', render: (r) => r.invoice_number },
              { key: 'tenant', header: 'Tenant', render: (r) => r.tenant_id },
              { key: 'status', header: 'Status', render: (r) => <StatusBadge status={r.status} /> },
              { key: 'total', header: 'Total', render: (r) => `${r.total} ${r.currency}` },
              { key: 'balance', header: 'Balance Due', render: (r) => `${r.balance_due} ${r.currency}` },
              { key: 'due', header: 'Due Date', render: (r) => r.due_date },
            ]}
          />
          <Pagination meta={meta} onPageChange={setPage} />
        </>
      )}
    </div>
  );
}
