import { useNavigate } from 'react-router-dom';
import { useAuth } from '../auth/useAuth';
import { useI18n } from '../i18n';
import { BrandLogo } from '../components/BrandLogo';
import { LanguageSwitcher } from '../components/LanguageSwitcher';

/**
 * Where an account lands when the API answers 403 ECOSYSTEM_ACCESS_REQUIRED: right after sign-in, or on any portal page opened
 * by direct URL. It is a public page (no session needed, nothing to redirect back from), keeps no token, names no product and
 * links to none: tenant users are sent to their own application's sign-in, never offered a way across from here.
 */
export function AccessDeniedPage() {
  const { clearAccessDenied, logout } = useAuth();
  const { t } = useI18n();
  const navigate = useNavigate();

  function signInAgain() {
    logout(); // also forgets any leftover token; the explanation is no longer needed
    clearAccessDenied();
    navigate('/login', { replace: true });
  }

  return (
    <div className="login-page">
      <main className="login-card state-card" aria-labelledby="denied-title" data-testid="access-denied">
        <BrandLogo variant="login" />
        <h1 id="denied-title">{t('denied.title')}</h1>
        <p>{t('denied.body')}</p>
        <h2 className="state-subtitle">{t('denied.what')}</h2>
        <ul className="state-list">
          <li>{t('denied.step1')}</li>
          <li>{t('denied.step2')}</li>
        </ul>
        <p className="muted">{t('denied.cleared')}</p>
        <button type="button" className="btn btn-primary" onClick={signInAgain}>{t('denied.signInAgain')}</button>
        <LanguageSwitcher />
      </main>
    </div>
  );
}
