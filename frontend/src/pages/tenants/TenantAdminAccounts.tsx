import { HelpLabel, HelpInput, HelpSelect, HelpTextarea } from '../../components/FieldControls';
import { useMemo, useState, type FormEvent } from 'react';
import { adminAccountsApi, rolesApi, tenantsApi } from '../../api/endpoints';
import { useApiResource } from '../../api/useApi';
import type { AdminAccount, Application, Role, Tenant, TemporaryCredential } from '../../api/types';
import { useAuth } from '../../auth/useAuth';
import { ConfirmDialog } from '../../components/ConfirmDialog';
import { ErrorAlert } from '../../components/ErrorAlert';
import { LoadingSpinner } from '../../components/LoadingSpinner';
import { Modal } from '../../components/Modal';
import { SecretDialog } from '../../components/SecretDialog';
import { Badge } from '../../components/StatusBadge';
import { formatDateTime, useI18n } from '../../i18n';
import { REASON_MAX, REASON_MIN, STAGE_TONE, adminErrorText, assignableRoles, emailsMatch, reasonProblem, stageOf } from './adminAccounts';

/** The credential an operator is looking at right now: it exists only in this component's state and is dropped when the dialog closes. */
interface ShownCredential {
  name: string;
  email: string;
  credential: TemporaryCredential;
}

/**
 * First Admin Tenant of a tenant, created without any e-mail (contract: GOVERNANCE_CONTRACTS.md 2c): the operator creates the
 * account, verifies the address outside OptiNexus and records that here, and hands the one-time temporary password over personally.
 */
export function TenantAdminAccounts({ tenant, onChanged }: { tenant: Tenant; onChanged: () => void }) {
  const { t } = useI18n();
  const { hasPermission } = useAuth();
  const canCreate = hasPermission('cgo.tenant.admin.assign');
  const canVerify = hasPermission('cgo.user.email.verify');

  const accounts = useApiResource<AdminAccount[]>(() => adminAccountsApi.list(tenant.id), [tenant.id]);
  const apps = useApiResource<Application[]>(() => tenantsApi.applications(tenant.id), [tenant.id]);

  const [creating, setCreating] = useState(false);
  const [verifying, setVerifying] = useState<AdminAccount | null>(null);
  const [reissuing, setReissuing] = useState<AdminAccount | null>(null);
  const [shown, setShown] = useState<ShownCredential | null>(null);
  const [notice, setNotice] = useState<string | null>(null);
  const [actionError, setActionError] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);

  const subscribed = apps.data ?? [];

  function changed() {
    accounts.reload();
    onChanged();
  }

  async function reissue(account: AdminAccount) {
    setBusy(true);
    setActionError(null);
    try {
      const result = await adminAccountsApi.reissueCredential(tenant.id, account.user.id);
      setReissuing(null);
      if (result.temporary_credential) setShown({ name: account.user.name, email: account.user.email, credential: result.temporary_credential });
      changed();
    } catch (err) {
      setReissuing(null);
      setActionError(adminErrorText(err));
    } finally {
      setBusy(false);
    }
  }

  const list = accounts.data ?? [];

  return (
    <div className="card" data-testid="admin-accounts">
      <div className="page-header">
        <h3>{t('adminAcct.title')}</h3>
        {canCreate && (
          <button className="btn btn-primary btn-sm" onClick={() => { setNotice(null); setActionError(null); setCreating(true); }} disabled={!apps.loading && subscribed.length === 0}>
            {t('adminAcct.create')}
          </button>
        )}
      </div>
      <p className="muted">{t('adminAcct.lead')}</p>
      {canCreate && !apps.loading && subscribed.length === 0 && <p className="field-hint">{t('adminAcct.noApplications')}</p>}
      {notice && <div className="alert alert-warning" role="status">{notice}</div>}
      <ErrorAlert message={actionError} />
      {accounts.loading ? (
        <LoadingSpinner />
      ) : accounts.error ? (
        <ErrorAlert message={`${t('adminAcct.loadFailed')} ${accounts.error}`} />
      ) : list.length === 0 ? (
        <p className="muted">{t('adminAcct.empty')}</p>
      ) : (
        <div className="table-wrap">
          <table className="data-table">
            <thead>
              <tr>
                <th>{t('adminAcct.col.account')}</th>
                <th className="hide-narrow">{t('adminAcct.col.applications')}</th>
                <th>{t('adminAcct.col.state')}</th>
                <th>{t('adminAcct.col.email')}</th>
                <th className="hide-narrow">{t('adminAcct.col.password')}</th>
                <th className="sticky-end">{t('adminAcct.col.actions')}</th>
              </tr>
            </thead>
            <tbody>
              {list.map((account) => (
                <AccountRow
                  key={account.user.id}
                  account={account}
                  canVerify={canVerify}
                  canReissue={canCreate}
                  onVerify={() => { setNotice(null); setActionError(null); setVerifying(account); }}
                  onReissue={() => { setNotice(null); setActionError(null); setReissuing(account); }}
                />
              ))}
            </tbody>
          </table>
        </div>
      )}

      <Modal open={creating} title={t('adminAcct.form.title')} onClose={() => !busy && setCreating(false)} dismissible={false}>
        {creating && (
          <CreateForm
            tenant={tenant}
            applications={subscribed}
            onCancel={() => setCreating(false)}
            onCreated={(created) => {
              setCreating(false);
              if (created.temporary_credential) {
                setNotice(t('adminAcct.result.created', { name: created.user.name }));
                setShown({ name: created.user.name, email: created.user.email, credential: created.temporary_credential });
              } else {
                setNotice(t('adminAcct.result.linked', { name: created.user.name }));
              }
              changed();
            }}
          />
        )}
      </Modal>

      <Modal open={!!verifying} title={verifying ? t('adminAcct.verify.title', { name: verifying.user.name }) : ''} onClose={() => !busy && setVerifying(null)} dismissible={false}>
        {verifying && (
          <VerifyForm
            tenantId={tenant.id}
            account={verifying}
            onCancel={() => setVerifying(null)}
            onVerified={() => {
              setNotice(t('adminAcct.verify.done', { name: verifying.user.name }));
              setVerifying(null);
              changed();
            }}
          />
        )}
      </Modal>

      <ConfirmDialog
        open={!!reissuing}
        title={reissuing ? t('adminAcct.reissue.title', { name: reissuing.user.name }) : ''}
        message={t('adminAcct.reissue.message')}
        confirmLabel={t('adminAcct.reissue.confirm')}
        danger
        busy={busy}
        onConfirm={() => reissuing && reissue(reissuing)}
        onCancel={() => setReissuing(null)}
      />

      {shown && (
        <SecretDialog
          title={t('adminAcct.credential.title', { name: shown.name })}
          clientId={shown.email}
          secret={shown.credential.password}
          onClose={() => setShown(null)}
          labels={{
            identity: t('adminAcct.credential.identity'),
            secret: t('adminAcct.credential.secret'),
            once: t('adminAcct.credential.once'),
            copy: t('adminAcct.credential.copy'),
            copyFailed: t('adminAcct.credential.copyFailed'),
            notCopied: t('adminAcct.credential.notCopied'),
            close: t('adminAcct.credential.close'),
            closeAnyway: t('adminAcct.credential.closeAnyway'),
          }}
          note={shown.credential.expires_at ? <p className="muted">{t('adminAcct.credential.expires', { when: formatDateTime(shown.credential.expires_at) })}</p> : null}
        />
      )}
    </div>
  );
}

function AccountRow({ account, canVerify, canReissue, onVerify, onReissue }: {
  account: AdminAccount;
  canVerify: boolean;
  canReissue: boolean;
  onVerify: () => void;
  onReissue: () => void;
}) {
  const { t } = useI18n();
  const stage = stageOf(account);
  const { activation } = account;
  const when = (value: string | null) => formatDateTime(value);

  let email: string;
  if (!activation.email.verified) {
    email = t('adminAcct.email.notVerified');
  } else if (activation.email.method === 'OPERATOR_MANUAL') {
    email = activation.email.verified_by
      ? t('adminAcct.email.verifiedManual', { name: activation.email.verified_by.name, when: when(activation.email.verified_at) })
      : t('adminAcct.email.verifiedManualAnon', { when: when(activation.email.verified_at) });
  } else if (activation.email.method === 'INVITATION') {
    email = t('adminAcct.email.verifiedInvitation', { when: when(activation.email.verified_at) });
  } else {
    email = t('adminAcct.email.verifiedOther', { when: when(activation.email.verified_at) });
  }

  let password = t('adminAcct.password.none');
  if (activation.managed) {
    if (activation.password.locked_seconds > 0) password = t('adminAcct.password.locked', { seconds: activation.password.locked_seconds });
    else if (activation.password.change_required && activation.password.expired) password = t('adminAcct.password.expired', { when: when(activation.password.expires_at) });
    else if (activation.password.change_required) password = t('adminAcct.password.valid', { when: when(activation.password.expires_at) });
    else if (activation.password.changed_at) password = t('adminAcct.password.changed', { when: when(activation.password.changed_at) });
  }

  const next = activation.next_steps.length ? activation.next_steps.map((step) => t(`adminAcct.next.${step}` as const)).join(' ') : activation.managed ? t('adminAcct.next.done') : '';
  const needsEmail = activation.managed && !activation.email.verified;
  const canIssue = account.user.status === 'ACTIVE' || (account.user.status === 'INVITED' && activation.password.change_required);

  return (
    <tr>
      <td>
        <strong>{account.user.name}</strong>
        <div className="muted break-all">{account.user.email}</div>
      </td>
      <td className="hide-narrow">{account.applications.map((a) => a.name).join(', ') || '—'}</td>
      <td>
        <Badge tone={STAGE_TONE[stage]}>{t(`adminAcct.state.${stage}` as const)}</Badge>
        {next && <div className="field-hint">{next}</div>}
      </td>
      <td>{email}</td>
      <td className="hide-narrow">{password}</td>
      <td className="sticky-end">
        <div className="row-actions">
          {canVerify && needsEmail && (
            <button className="btn btn-secondary btn-sm" onClick={onVerify} aria-label={`${t('adminAcct.action.verify')}: ${account.user.name}`}>{t('adminAcct.action.verify')}</button>
          )}
          {canReissue && canIssue && (
            <button className="btn btn-secondary btn-sm" onClick={onReissue} aria-label={`${t('adminAcct.action.reissue')}: ${account.user.name}`}>{t('adminAcct.action.reissue')}</button>
          )}
        </div>
      </td>
    </tr>
  );
}

function CreateForm({ tenant, applications, onCancel, onCreated }: {
  tenant: Tenant;
  applications: Application[];
  onCancel: () => void;
  onCreated: (created: { user: { name: string; email: string }; temporary_credential: TemporaryCredential | null }) => void;
}) {
  const { t } = useI18n();
  const accounts = useApiResource(() => adminAccountsApi.accountOptions(tenant.id), [tenant.id]);
  const [mode, setMode] = useState<'new' | 'existing'>('new');
  const [name, setName] = useState('');
  const [email, setEmail] = useState('');
  const [userId, setUserId] = useState('');
  const [confirmEmail, setConfirmEmail] = useState('');
  const [selectedApps, setSelectedApps] = useState<string[]>(() => (applications.length === 1 ? [applications[0].id] : []));
  const [selectedRoles, setSelectedRoles] = useState<string[]>([]);
  const [submitted, setSubmitted] = useState(false);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);

  // Roles are optional and listing them needs its own permission: without it the section simply offers none.
  const roles = useApiResource<Role[]>(() => rolesApi.list({ per_page: 100, status: 'ACTIVE' }).then((e) => e.data).catch(() => []), []);
  const roleChoices = useMemo(() => assignableRoles(roles.data ?? [], tenant.id, selectedApps), [roles.data, tenant.id, selectedApps]);

  const toggle = (list: string[], id: string) => (list.includes(id) ? list.filter((x) => x !== id) : [...list, id]);
  const appsMissing = selectedApps.length === 0;

  async function submit(e: FormEvent) {
    e.preventDefault();
    setSubmitted(true);
    setError(null);
    if (appsMissing) return;
    setBusy(true);
    try {
      const roleIds = selectedRoles.filter((id) => roleChoices.some((r) => r.id === id));
      const payload = mode === 'new'
        ? { name: name.trim(), email: email.trim(), application_ids: selectedApps, role_ids: roleIds }
        : { user_id: userId.trim(), confirm_email: confirmEmail.trim(), application_ids: selectedApps, role_ids: roleIds };
      const created = await adminAccountsApi.create(tenant.id, payload);
      onCreated(created);
    } catch (err) {
      setError(adminErrorText(err));
    } finally {
      setBusy(false);
    }
  }

  return (
    <form className="form" onSubmit={submit} noValidate>
      <ErrorAlert message={error} />
      <div role="radiogroup">
        <HelpLabel className="radio-row">
          <HelpInput help="Create a new user account as this tenant's administrator." type="radio" name="mode" checked={mode === 'new'} onChange={() => setMode('new')} />
          {t('adminAcct.form.modeNew')}
        </HelpLabel>
        <HelpLabel className="radio-row">
          <HelpInput help="Assign an existing registered user as this tenant's administrator." type="radio" name="mode" checked={mode === 'existing'} onChange={() => setMode('existing')} />
          {t('adminAcct.form.modeExisting')}
        </HelpLabel>
      </div>

      {mode === 'new' ? (
        <>
          <HelpLabel>
            {t('adminAcct.form.name')}
            <HelpInput help="Enter a readable name used to identify this record in lists and selections." value={name} onChange={(e) => setName(e.target.value)} maxLength={255} required autoComplete="off" />
          </HelpLabel>
          <HelpLabel>
            {t('adminAcct.form.email')}
            <HelpInput help="Enter a valid email address for this account or customer." type="email" value={email} onChange={(e) => setEmail(e.target.value)} maxLength={255} required autoComplete="off" />
            <span className="field-hint">{t('adminAcct.form.emailHint')}</span>
          </HelpLabel>
        </>
      ) : (
        <>
          <HelpLabel>
            {t('adminAcct.form.userId')}
            <HelpSelect help="Select an OptiNexus account belonging to this tenant's customer. Email is filled automatically and can be changed with user update permission." value={userId} disabled={accounts.loading || !!accounts.error} onChange={(e) => { setUserId(e.target.value); setConfirmEmail(accounts.data?.find((account) => account.id === e.target.value)?.email ?? ''); }} required>
              <option value="">Select an account...</option>{(accounts.data ?? []).map((account) => <option key={account.id} value={account.id}>{account.name} — {account.id}</option>)}
            </HelpSelect><ErrorAlert message={accounts.error} />
            <span className="field-hint">{t('adminAcct.form.userIdHint')}</span>
          </HelpLabel>
          <HelpLabel>
            {t('adminAcct.form.confirmEmail')}
            <HelpInput help="Filled from the selected account. You may change it with user update permission; a changed address must be verified again." type="email" value={confirmEmail} onChange={(e) => setConfirmEmail(e.target.value)} maxLength={255} required autoComplete="off" />
            <span className="field-hint">{t('adminAcct.form.confirmEmailHint')}</span>
          </HelpLabel>
        </>
      )}

      <fieldset className="url-fieldset">
        <legend>{t('adminAcct.form.applications')}</legend>
        {applications.map((app) => (
          <HelpLabel key={app.id} className="check-inline">
            <HelpInput help="Select the applications this tenant administrator may access." type="checkbox" checked={selectedApps.includes(app.id)} onChange={() => setSelectedApps((list) => toggle(list, app.id))} />
            {app.name}
          </HelpLabel>
        ))}
        <span className="field-hint">{t('adminAcct.form.applicationsHint')}</span>
        {submitted && appsMissing && <span className="field-error" role="alert">{t('adminAcct.form.needApplication')}</span>}
      </fieldset>

      <fieldset className="url-fieldset">
        <legend>{t('adminAcct.form.roles')}</legend>
        {roleChoices.length === 0 && <span className="field-hint">{t('adminAcct.form.rolesNone')}</span>}
        {roleChoices.map((role) => (
          <HelpLabel key={role.id} className="check-inline">
            <HelpInput help="Select the roles assigned to this tenant administrator." type="checkbox" checked={selectedRoles.includes(role.id)} onChange={() => setSelectedRoles((list) => toggle(list, role.id))} />
            {role.name}
          </HelpLabel>
        ))}
      </fieldset>

      <div className="modal-actions">
        <button type="button" className="btn btn-ghost" onClick={onCancel} disabled={busy}>{t('common.cancel')}</button>
        <button type="submit" className="btn btn-primary" disabled={busy}>{t('adminAcct.form.submit')}</button>
      </div>
    </form>
  );
}

function VerifyForm({ tenantId, account, onCancel, onVerified }: {
  tenantId: string;
  account: AdminAccount;
  onCancel: () => void;
  onVerified: () => void;
}) {
  const { t } = useI18n();
  const [email, setEmail] = useState('');
  const [reason, setReason] = useState('');
  const [submitted, setSubmitted] = useState(false);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const mismatch = !emailsMatch(email, account.user.email);
  const short = reasonProblem(reason) === 'short';

  async function submit(e: FormEvent) {
    e.preventDefault();
    setSubmitted(true);
    setError(null);
    if (mismatch || short) return;
    setBusy(true);
    try {
      await adminAccountsApi.verifyEmail(tenantId, account.user.id, { email: email.trim(), reason: reason.trim() });
      onVerified();
    } catch (err) {
      setError(adminErrorText(err));
    } finally {
      setBusy(false);
    }
  }

  return (
    <form className="form" onSubmit={submit} noValidate>
      <p className="muted">{t('adminAcct.verify.intro')}</p>
      <ErrorAlert message={error} />
      <HelpLabel>
        {t('adminAcct.verify.email')}
        <HelpInput help="Enter a valid email address for this account or customer." type="email" value={email} onChange={(e) => setEmail(e.target.value)} maxLength={255} required autoComplete="off" className={submitted && mismatch ? 'invalid' : undefined} />
        <span className="field-hint">{t('adminAcct.verify.emailHint')}</span>
        {submitted && mismatch && <span className="field-error" role="alert">{t('adminAcct.verify.mismatch')}</span>}
      </HelpLabel>
      <HelpLabel>
        {t('adminAcct.verify.reason')}
        <HelpTextarea help="Explain why this change is needed. The reason is retained for review and audit." value={reason} onChange={(e) => setReason(e.target.value)} maxLength={REASON_MAX} required className={submitted && short ? 'invalid' : undefined} />
        <span className="field-hint">{t('adminAcct.verify.reasonHint', { min: REASON_MIN })}</span>
        {submitted && short && <span className="field-error" role="alert">{t('adminAcct.verify.reasonShort', { min: REASON_MIN })}</span>}
      </HelpLabel>
      <div className="modal-actions">
        <button type="button" className="btn btn-ghost" onClick={onCancel} disabled={busy}>{t('common.cancel')}</button>
        <button type="submit" className="btn btn-primary" disabled={busy}>{t('adminAcct.verify.submit')}</button>
      </div>
    </form>
  );
}
