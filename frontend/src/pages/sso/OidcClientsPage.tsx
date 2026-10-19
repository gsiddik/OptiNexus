import { HelpSelect, HelpInput } from '../../components/FieldControls';
import { useEffect, useMemo, useState } from 'react';
import { useSearchParams } from 'react-router-dom';
import { describeError } from '../../api/client';
import { applicationsApi, oidcClientsApi } from '../../api/endpoints';
import { useApiResource } from '../../api/useApi';
import type { Application, OidcClient } from '../../api/types';
import { useAuth } from '../../auth/useAuth';
import { ConfirmDialog } from '../../components/ConfirmDialog';
import { DataTable } from '../../components/DataTable';
import { ErrorAlert } from '../../components/ErrorAlert';
import { LoadingSpinner } from '../../components/LoadingSpinner';
import { Modal } from '../../components/Modal';
import { SecretDialog } from '../../components/SecretDialog';
import { EmptyState, ErrorState, ForbiddenState } from '../../components/StateViews';
import { Badge, StatusBadge } from '../../components/StatusBadge';
import { formatDateTime, useI18n } from '../../i18n';
import { OidcClientForm } from './OidcClientForm';
import { toCreatePayload, toUpdatePayload, type OidcFormState } from './oidcForm';

export const SSO_VIEW_PERMISSION = 'cgo.sso.client.view';
export const SSO_MANAGE_PERMISSION = 'cgo.sso.client.manage';

/** OIDC client registrations of the integrated applications. Gated by `cgo.sso.client.view`; changes need `cgo.sso.client.manage`. */
export function OidcClientsPage() {
  const { hasPermission } = useAuth();
  const { t } = useI18n();
  if (!hasPermission(SSO_VIEW_PERMISSION)) {
    return (
      <div>
        <div className="page-header"><h1>{t('oidc.title')}</h1></div>
        <ForbiddenState permission={SSO_VIEW_PERMISSION} />
      </div>
    );
  }
  return <OidcClientsView canManage={hasPermission(SSO_MANAGE_PERMISSION)} />;
}

type Editor = { mode: 'create'; applicationId?: string } | { mode: 'edit'; client: OidcClient };
type Confirm = { kind: 'rotate' | 'revoke'; client: OidcClient };
/** The one-time secret. It exists only while the dialog is open: closing sets this back to null. */
type Reveal = { title: string; clientId: string; secret: string };

function OidcClientsView({ canManage }: { canManage: boolean }) {
  const { t } = useI18n();
  const clients = useApiResource<OidcClient[]>(() => oidcClientsApi.list(), []);
  const apps = useApiResource<Application[]>(() => applicationsApi.list({ per_page: 100 }).then((e) => e.data), []);
  const [filters, setFilters] = useState({ application: '', status: '', search: '' });
  const [editor, setEditor] = useState<Editor | null>(null);
  const [confirm, setConfirm] = useState<Confirm | null>(null);
  const [reveal, setReveal] = useState<Reveal | null>(null);
  const [busy, setBusy] = useState(false);
  const [actionError, setActionError] = useState<string | null>(null);
  const [searchParams, setSearchParams] = useSearchParams();

  const applications = useMemo(() => apps.data ?? [], [apps.data]);
  const appName = (id: string) => applications.find((a) => a.id === id)?.name ?? id.slice(0, 8);

  // The onboarding report links here with ?new=1&application=<code> to start a client for the application that lacks one.
  const wantsNew = searchParams.get('new') === '1';
  const presetCode = searchParams.get('application');
  const [openedFromLink, setOpenedFromLink] = useState(false);
  if (wantsNew && !openedFromLink && canManage && !apps.loading) {
    // Adjusted while rendering (not in an effect), so the form is there on the first frame the applications are known.
    setOpenedFromLink(true);
    setEditor({ mode: 'create', applicationId: applications.find((a) => a.application_code === presetCode)?.id });
  }
  useEffect(() => {
    if (openedFromLink && wantsNew) setSearchParams({}, { replace: true }); // keep the address clean so a reload does not reopen the form
  }, [openedFromLink, wantsNew, setSearchParams]);

  const rows = useMemo(() => {
    const needle = filters.search.trim().toLowerCase();
    return (clients.data ?? []).filter(
      (c) =>
        (!filters.application || c.application_id === filters.application) &&
        (!filters.status || c.status === filters.status) &&
        (!needle || c.name.toLowerCase().includes(needle) || c.client_id.toLowerCase().includes(needle)),
    );
  }, [clients.data, filters]);

  async function save(form: OidcFormState) {
    setBusy(true);
    try {
      if (editor?.mode === 'edit') {
        await oidcClientsApi.update(editor.client.id, toUpdatePayload(form));
      } else {
        const result = await oidcClientsApi.create(toCreatePayload(form));
        if (result.client_secret) {
          setReveal({ title: t('oidc.secret.createdTitle', { name: result.client.name }), clientId: result.client.client_id, secret: result.client_secret });
        }
      }
      setEditor(null);
      clients.reload();
    } finally {
      setBusy(false);
    }
  }

  async function runConfirmed() {
    if (!confirm) return;
    setBusy(true);
    setActionError(null);
    try {
      if (confirm.kind === 'rotate') {
        const result = await oidcClientsApi.rotateSecret(confirm.client.id);
        setReveal({ title: t('oidc.secret.rotatedTitle', { name: confirm.client.name }), clientId: result.client.client_id, secret: result.client_secret });
      } else {
        await oidcClientsApi.revoke(confirm.client.id);
      }
      clients.reload();
    } catch (err) {
      setActionError(describeError(err));
    } finally {
      setConfirm(null);
      setBusy(false);
    }
  }

  const newButton = canManage && (
    <button type="button" className="btn btn-primary" onClick={() => setEditor({ mode: 'create' })}>{t('oidc.new')}</button>
  );

  let body;
  if (clients.loading) {
    body = <LoadingSpinner />;
  } else if (clients.errorInfo) {
    body = <ErrorState error={clients.errorInfo} onRetry={clients.reload} permission={SSO_VIEW_PERMISSION} />;
  } else if ((clients.data ?? []).length === 0) {
    body = <EmptyState title={t('oidc.empty.title')} hint={t('oidc.empty.hint')} action={newButton || undefined} />;
  } else {
    body = (
      <>
        <div className="toolbar">
          <HelpSelect help="Select the registered application to which this configuration applies." aria-label={t('oidc.filter.application')} value={filters.application} onChange={(e) => setFilters({ ...filters, application: e.target.value })}>
            <option value="">{t('oidc.filter.allApplications')}</option>
            {applications.map((a) => <option key={a.id} value={a.id}>{a.name}</option>)}
          </HelpSelect>
          <HelpSelect help="Filter by lifecycle status. Leave empty to show all statuses." aria-label={t('oidc.filter.status')} value={filters.status} onChange={(e) => setFilters({ ...filters, status: e.target.value })}>
            <option value="">{t('common.all')}</option>
            <option value="ACTIVE">{t('status.ACTIVE')}</option>
            <option value="REVOKED">{t('status.REVOKED')}</option>
          </HelpSelect>
          <HelpInput help="Type a name, code, or identifying text to narrow the list. Clear to show all records." type="search" aria-label={t('oidc.filter.search')} placeholder={t('oidc.filter.search')} value={filters.search} onChange={(e) => setFilters({ ...filters, search: e.target.value })} />
        </div>
        <DataTable
          rows={rows}
          rowKey={(r) => r.id}
          emptyMessage={t('oidc.empty.filtered')}
          columns={[
            { key: 'client', header: t('oidc.col.client'), render: (r) => (<><strong>{r.name}</strong><br /><code className="break-all">{r.client_id}</code></>) },
            { key: 'app', header: t('oidc.col.application'), render: (r) => appName(r.application_id) },
            { key: 'type', header: t('oidc.col.type'), render: (r) => <Badge tone="neutral">{r.confidential ? t('oidc.type.confidential') : t('oidc.type.public')}</Badge> },
            {
              key: 'pkce',
              header: t('oidc.col.pkce'),
              render: (r) => <Badge tone={r.require_pkce ? 'success' : 'danger'}>{r.require_pkce ? t('oidc.pkce.required') : t('oidc.pkce.off')}</Badge>,
            },
            {
              key: 'redirects',
              header: t('oidc.col.redirects'),
              render: (r) => (
                <span title={r.redirect_uris.join('\n')}>
                  <span className="break-all">{r.redirect_uris[0]}</span>
                  {r.redirect_uris.length > 1 && <span className="muted"> +{r.redirect_uris.length - 1}</span>}
                </span>
              ),
            },
            { key: 'status', header: t('common.status'), render: (r) => <StatusBadge status={r.status} /> },
            { key: 'created', className: 'hide-narrow', header: t('common.created'), render: (r) => <span className="nowrap">{formatDateTime(r.created_at)}</span> },
            {
              key: 'actions',
              className: 'sticky-end',
              header: '',
              render: (r) =>
                canManage && r.status === 'ACTIVE' ? (
                  <div className="row-actions">
                    <button type="button" className="btn btn-ghost" onClick={() => setEditor({ mode: 'edit', client: r })}>{t('oidc.action.edit')}</button>
                    {r.confidential && <button type="button" className="btn btn-ghost" onClick={() => setConfirm({ kind: 'rotate', client: r })}>{t('oidc.action.rotate')}</button>}
                    <button type="button" className="btn btn-ghost" onClick={() => setConfirm({ kind: 'revoke', client: r })}>{t('oidc.action.revoke')}</button>
                  </div>
                ) : r.status === 'REVOKED' ? <span className="muted">{t('oidc.revoked.note')}</span> : null,
            },
          ]}
        />
      </>
    );
  }

  return (
    <div>
      <div className="page-header">
        <h1>{t('oidc.title')}</h1>
        {newButton}
      </div>
      <p className="muted">{t('oidc.intro')}</p>
      <ErrorAlert message={actionError} />
      {body}

      <Modal
        open={!!editor}
        title={editor?.mode === 'edit' ? t('oidc.form.editTitle', { name: editor.client.name }) : t('oidc.form.createTitle')}
        onClose={() => !busy && setEditor(null)}
        dismissible={false}
      >
        {editor && (
          <OidcClientForm
            key={editor.mode === 'edit' ? editor.client.id : 'new'}
            mode={editor.mode}
            client={editor.mode === 'edit' ? editor.client : undefined}
            applications={applications}
            applicationsFailed={!!apps.errorInfo}
            presetApplicationId={editor.mode === 'create' ? editor.applicationId : undefined}
            busy={busy}
            onSubmit={save}
            onCancel={() => setEditor(null)}
          />
        )}
      </Modal>

      <ConfirmDialog
        open={!!confirm}
        danger
        busy={busy}
        title={confirm ? t(confirm.kind === 'rotate' ? 'oidc.rotate.title' : 'oidc.revoke.title', { name: confirm.client.name }) : ''}
        message={confirm ? <p>{t(confirm.kind === 'rotate' ? 'oidc.rotate.body' : 'oidc.revoke.body')}</p> : null}
        confirmLabel={confirm ? t(confirm.kind === 'rotate' ? 'oidc.rotate.confirm' : 'oidc.revoke.confirm') : undefined}
        onConfirm={runConfirmed}
        onCancel={() => setConfirm(null)}
      />

      {reveal && <SecretDialog title={reveal.title} clientId={reveal.clientId} secret={reveal.secret} onClose={() => setReveal(null)} />}
    </div>
  );
}
