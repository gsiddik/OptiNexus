import { useState } from 'react';
import { Link } from 'react-router-dom';
import { tenantsApi } from '../../api/endpoints';
import { useApiList } from '../../api/useApi';
import type { Tenant } from '../../api/types';
import { DataTable } from '../../components/DataTable';
import { LoadingSpinner } from '../../components/LoadingSpinner';
import { Pagination } from '../../components/Pagination';
import { EmptyState, ErrorState } from '../../components/StateViews';
import { StatusBadge } from '../../components/StatusBadge';
import { useI18n, type MessageKey } from '../../i18n';

const TEXT: Record<'onboarding' | 'gateway', { title: MessageKey; intro: MessageKey }> = {
  onboarding: { title: 'nav.onboarding', intro: 'gw.pickerOnboardingIntro' },
  gateway: { title: 'nav.gateway', intro: 'gw.pickerIntro' },
};

/** Entry of the per-tenant screens from the menu: pick the tenant, then open its onboarding report or vehicle mapping. */
function TenantPicker({ target }: { target: 'onboarding' | 'gateway' }) {
  const { t } = useI18n();
  const [search, setSearch] = useState('');
  const { rows, meta, setPage, loading, errorInfo, reload } = useApiList<Tenant>((page) => tenantsApi.list({ page, per_page: 25, search: search || undefined }), [search]);

  return (
    <div>
      <div className="page-header"><h1>{t(TEXT[target].title)}</h1></div>
      <p className="muted">{t(TEXT[target].intro)}</p>
      <div className="toolbar">
        <input type="search" aria-label={t('gw.pickerSearch')} placeholder={t('gw.pickerSearch')} value={search} onChange={(e) => setSearch(e.target.value)} />
      </div>
      {loading ? (
        <LoadingSpinner />
      ) : errorInfo ? (
        <ErrorState error={errorInfo} onRetry={reload} permission="cgo.tenant.view" />
      ) : rows.length === 0 && !search ? (
        <EmptyState title={t('common.noRecords')} />
      ) : (
        <>
          <DataTable
            rows={rows}
            rowKey={(r) => r.id}
            columns={[
              { key: 'name', header: t('common.name'), render: (r) => <strong>{r.name}</strong> },
              { key: 'code', header: 'Code', render: (r) => <code>{r.tenant_code}</code> },
              { key: 'status', header: t('common.status'), render: (r) => <StatusBadge status={r.status} /> },
              { key: 'open', header: '', render: (r) => <Link className="btn btn-secondary btn-sm tab-link" to={`/tenants/${r.id}/${target}`}>{t('gw.pickerOpen')}</Link> },
            ]}
          />
          <Pagination meta={meta} onPageChange={setPage} />
        </>
      )}
    </div>
  );
}

export const OnboardingPickerPage = () => <TenantPicker target="onboarding" />;
export const GatewayPickerPage = () => <TenantPicker target="gateway" />;
