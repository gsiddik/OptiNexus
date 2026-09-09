import { useState } from 'react';
import { usageApi } from '../../../api/endpoints';
import { useApiList } from '../../../api/useApi';
import { DataTable } from '../../../components/DataTable';
import { Pagination } from '../../../components/Pagination';
import { LoadingSpinner } from '../../../components/LoadingSpinner';
import { ErrorAlert } from '../../../components/ErrorAlert';
import type { UsageEvent } from '../../../api/types';

export function UsageEventsPage() {
  const [tenantId, setTenantId] = useState('');
  const [meterKey, setMeterKey] = useState('');
  const { rows, meta, setPage, loading, error } = useApiList<UsageEvent>(
    (p) => usageApi.list({ page: p, tenant_id: tenantId || undefined, meter_key: meterKey || undefined }),
    [tenantId, meterKey],
  );

  return (
    <div>
      <div className="page-header">
        <h1>Usage Events</h1>
      </div>
      <p className="muted">
        Usage events are ingested machine-to-machine by service accounts (metering pipelines) — there is no
        manual create form here. This view is read-only.
      </p>

      <div className="toolbar">
        <input placeholder="Filter by tenant ID..." value={tenantId} onChange={(e) => setTenantId(e.target.value)} />
        <input placeholder="Filter by meter key..." value={meterKey} onChange={(e) => setMeterKey(e.target.value)} />
      </div>

      <ErrorAlert message={error} />
      {loading ? (
        <LoadingSpinner />
      ) : (
        <>
          <DataTable
            rows={rows}
            rowKey={(r) => r.id}
            columns={[
              { key: 'tenant', header: 'Tenant', render: (r) => r.tenant_id },
              { key: 'meter', header: 'Meter Key', render: (r) => r.meter_key },
              { key: 'quantity', header: 'Quantity', render: (r) => r.quantity },
              { key: 'timestamp', header: 'Usage Timestamp', render: (r) => r.usage_timestamp },
              { key: 'period_start', header: 'Period Start', render: (r) => r.period_start },
              { key: 'period_end', header: 'Period End', render: (r) => r.period_end },
              { key: 'source', header: 'Source', render: (r) => r.source ?? '—' },
            ]}
          />
          <Pagination meta={meta} onPageChange={setPage} />
        </>
      )}
    </div>
  );
}
