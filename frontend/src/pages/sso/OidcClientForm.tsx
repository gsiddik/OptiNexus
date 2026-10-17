import { useMemo, useState, type FormEvent } from 'react';
import { describeError, unwrapError } from '../../api/client';
import type { Application, OidcClient } from '../../api/types';
import { ErrorAlert } from '../../components/ErrorAlert';
import { useI18n } from '../../i18n';
import { UrlListEditor } from './UrlListEditor';
import { emptyForm, formFromClient, MAX_URLS, pkceNeedsAck, serverFieldErrors, validateForm, type OidcFormState } from './oidcForm';

interface Props {
  mode: 'create' | 'edit';
  /** The client being edited. */
  client?: OidcClient;
  applications: Application[];
  applicationsFailed: boolean;
  /** Pre-selected application when the form is opened from the onboarding report. */
  presetApplicationId?: string;
  busy: boolean;
  onSubmit: (form: OidcFormState) => Promise<void>;
  onCancel: () => void;
}

/** Create / edit form of an SSO client. PKCE starts on and stays on unless the operator explicitly accepts the risk. */
export function OidcClientForm({ mode, client, applications, applicationsFailed, presetApplicationId, busy, onSubmit, onCancel }: Props) {
  const { t } = useI18n();
  const [form, setForm] = useState<OidcFormState>(() => (client ? formFromClient(client) : emptyForm(presetApplicationId)));
  const [submitted, setSubmitted] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [serverFields, setServerFields] = useState<ReturnType<typeof serverFieldErrors>>({ fields: {}, rows: {} });

  const { problems, valid } = useMemo(() => validateForm(form, mode, client), [form, mode, client]);
  const show = submitted; // problems appear once the operator tried to save, not while typing the first character
  const set = <K extends keyof OidcFormState>(key: K, value: OidcFormState[K]) => setForm((current) => ({ ...current, [key]: value }));

  const publicClient = !form.confidential;
  const ackNeeded = pkceNeedsAck(form, client);
  const pkceAlreadyOff = !!client && !client.require_pkce;

  async function submit(e: FormEvent) {
    e.preventDefault();
    setSubmitted(true);
    setError(null);
    if (!valid) return;
    try {
      await onSubmit(form);
    } catch (err) {
      const failure = unwrapError(err);
      if (failure.kind === 'validation') {
        setServerFields(serverFieldErrors(failure.details));
        setError(t('oidc.form.fixErrors'));
      } else {
        setError(describeError(failure));
      }
    }
  }

  return (
    <form className="form" onSubmit={submit} noValidate>
      <ErrorAlert message={error} />
      {applicationsFailed && mode === 'create' && <div className="alert alert-error" role="alert">{t('oidc.form.applicationsFailed')}</div>}

      <label>
        {t('oidc.form.application')}
        {mode === 'create' ? (
          <select value={form.application_id} onChange={(e) => set('application_id', e.target.value)} aria-invalid={show && problems.application ? true : undefined}>
            <option value="">{t('oidc.form.applicationPick')}</option>
            {applications.map((a) => <option key={a.id} value={a.id}>{a.name} ({a.application_code})</option>)}
          </select>
        ) : (
          <input value={applications.find((a) => a.id === form.application_id)?.name ?? form.application_id} readOnly disabled />
        )}
        {show && problems.application && <span className="field-error">{t(problems.application)}</span>}
        {serverFields.fields.application_id && <span className="field-error">{serverFields.fields.application_id.join(' ')}</span>}
      </label>

      <label>
        {t('oidc.form.name')}
        <input value={form.name} maxLength={255} onChange={(e) => set('name', e.target.value)} aria-invalid={show && problems.name ? true : undefined} className={show && problems.name ? 'invalid' : undefined} />
        {show && problems.name && <span className="field-error">{t(problems.name)}</span>}
        {serverFields.fields.name && <span className="field-error">{serverFields.fields.name.join(' ')}</span>}
      </label>

      <fieldset className="url-fieldset">
        <legend>{t('oidc.form.type')}</legend>
        {mode === 'create' ? (
          <>
            <label className="radio-row">
              <input type="radio" name="client-type" checked={form.confidential} onChange={() => set('confidential', true)} />
              <span>{t('oidc.form.typeConfidential')}</span>
            </label>
            <label className="radio-row">
              <input type="radio" name="client-type" checked={!form.confidential} onChange={() => setForm((c) => ({ ...c, confidential: false, require_pkce: true, pkce_ack: false }))} />
              <span>{t('oidc.form.typePublic')}</span>
            </label>
          </>
        ) : (
          <>
            <strong>{form.confidential ? t('oidc.type.confidential') : t('oidc.type.public')}</strong>
            <span className="field-hint">{t('oidc.form.typeFixed')}</span>
          </>
        )}
      </fieldset>

      <div className={`pkce-box${form.require_pkce ? '' : ' off'}`} data-testid="pkce-box">
        <label>
          <input
            type="checkbox"
            checked={form.require_pkce}
            disabled={publicClient}
            onChange={(e) => setForm((c) => ({ ...c, require_pkce: e.target.checked, pkce_ack: false }))}
          />
          <strong>{t('oidc.pkce.title')}</strong>
        </label>
        <p>{t('oidc.pkce.help')}</p>
        {publicClient && <p>{t('oidc.pkce.publicLocked')}</p>}
        {!form.require_pkce && (
          <div className="pkce-warning" role="alert" data-testid="pkce-warning">
            <p><strong>{t('oidc.pkce.warnTitle')}</strong></p>
            <p>{t('oidc.pkce.warnBody')}</p>
            {pkceAlreadyOff && !ackNeeded && <p>{t('oidc.pkce.offExisting')}</p>}
            {ackNeeded && (
              <label>
                <input type="checkbox" checked={form.pkce_ack} onChange={(e) => set('pkce_ack', e.target.checked)} />
                <span>{t('oidc.pkce.ack')}</span>
              </label>
            )}
            {show && problems.pkceAck && <span className="field-error">{t(problems.pkceAck)}</span>}
          </div>
        )}
      </div>

      <UrlListEditor
        label={t('oidc.form.redirects')}
        hint={t('oidc.form.redirectsHint')}
        values={form.redirect_uris}
        onChange={(v) => set('redirect_uris', v)}
        problems={show ? problems.redirects : []}
        serverRows={serverFields.rows.redirect_uris}
        listProblem={show ? problems.redirectsList : undefined}
        min={1}
        max={MAX_URLS}
        testId="redirect-uris"
      />
      <UrlListEditor
        label={t('oidc.form.postLogout')}
        hint={t('oidc.form.postLogoutHint')}
        values={form.post_logout_redirect_uris}
        onChange={(v) => set('post_logout_redirect_uris', v)}
        problems={show ? problems.postLogout : []}
        serverRows={serverFields.rows.post_logout_redirect_uris}
        listProblem={show ? problems.postLogoutList : undefined}
        min={0}
        max={MAX_URLS}
        testId="post-logout-uris"
      />

      <label>
        {t('oidc.form.backchannel')}
        <span className="field-hint">{t('oidc.form.backchannelHint')}</span>
        <input type="url" inputMode="url" autoComplete="off" value={form.backchannel_logout_uri} onChange={(e) => set('backchannel_logout_uri', e.target.value)} className={show && problems.backchannel ? 'invalid' : undefined} placeholder="https://app.example.com/oidc/backchannel-logout" />
        {show && problems.backchannel && <span className="field-error">{t(problems.backchannel)}</span>}
        {serverFields.fields.backchannel_logout_uri && <span className="field-error">{serverFields.fields.backchannel_logout_uri.join(' ')}</span>}
      </label>

      <label>
        {t('oidc.form.launch')}
        <span className="field-hint">{t('oidc.form.launchHint')}</span>
        <input type="url" inputMode="url" autoComplete="off" value={form.launch_url} onChange={(e) => set('launch_url', e.target.value)} className={show && problems.launch ? 'invalid' : undefined} placeholder="https://app.example.com/" />
        {show && problems.launch && <span className="field-error">{t(problems.launch)}</span>}
        {serverFields.fields.launch_url && <span className="field-error">{serverFields.fields.launch_url.join(' ')}</span>}
      </label>

      <div className="modal-actions">
        <button type="button" className="btn btn-ghost" onClick={onCancel} disabled={busy}>{t('common.cancel')}</button>
        <button type="submit" className="btn btn-primary" disabled={busy || (mode === 'create' && applicationsFailed)}>
          {mode === 'create' ? t('oidc.form.saveCreate') : t('oidc.form.saveEdit')}
        </button>
      </div>
    </form>
  );
}
