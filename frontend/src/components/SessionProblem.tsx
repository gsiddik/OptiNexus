import { describeError } from '../api/client';
import { useAuth } from '../auth/useAuth';
import { useI18n } from '../i18n';
import { BrandLogo } from './BrandLogo';
import { LanguageSwitcher } from './LanguageSwitcher';

/** The session could not be checked (server or network trouble). Nothing was signed out; the user can retry or sign out. */
export function SessionProblem() {
  const { contextError, retryContext, logout } = useAuth();
  const { t } = useI18n();
  return (
    <div className="login-page">
      <main className="login-card state-card" role="alert">
        <BrandLogo variant="login" />
        <h1>{t('session.problemTitle')}</h1>
        <p className="muted">{t('session.problemBody')}</p>
        {contextError && <div className="alert alert-error">{describeError(contextError)}</div>}
        <div className="modal-actions state-actions">
          <button type="button" className="btn btn-ghost" onClick={logout}>{t('shell.logout')}</button>
          <button type="button" className="btn btn-primary" onClick={retryContext}>{t('common.retry')}</button>
        </div>
        <LanguageSwitcher />
      </main>
    </div>
  );
}
