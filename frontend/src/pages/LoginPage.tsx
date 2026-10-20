import { HelpLabel, HelpInput } from '../components/FieldControls';
import { useState, type FormEvent } from 'react';
import { useNavigate, useLocation } from 'react-router-dom';
import { useAuth } from '../auth/useAuth';
import { describeError, unwrapError } from '../api/client';
import { useI18n } from '../i18n';
import { BrandLogo } from '../components/BrandLogo';
import { ErrorAlert } from '../components/ErrorAlert';
import { LanguageSwitcher } from '../components/LanguageSwitcher';

export function LoginPage() {
  const { login, sessionNotice } = useAuth();
  const { t } = useI18n();
  const navigate = useNavigate();
  const location = useLocation();
  const [email, setEmail] = useState('');
  const [password, setPassword] = useState('');
  const [error, setError] = useState<string | null>(null);
  const [submitting, setSubmitting] = useState(false);

  async function handleSubmit(e: FormEvent) {
    e.preventDefault();
    setSubmitting(true);
    setError(null);
    try {
      await login(email, password);
      const from = (location.state as { from?: Location })?.from;
      navigate(from ? `${from.pathname}${from.search}` : '/', { replace: true });
    } catch (err) {
      // 403 ECOSYSTEM_ACCESS_REQUIRED: right credentials, account not allowed in the portal. The API response handler has already
      // sent the user to the explanation page and no token was issued, so there is nothing to show here.
      if (unwrapError(err).kind === 'ecosystem') {
        navigate('/access-denied', { replace: true });
        return;
      }
      setError(describeError(err));
      setSubmitting(false);
      return;
    }
    setSubmitting(false);
  }

  return (
    <div className="login-page">
      <form className="login-card" onSubmit={handleSubmit}>
        <BrandLogo variant="login" />
        <h1>{t('login.title')}</h1>
        <p className="muted">{t('login.subtitle')}</p>
        {sessionNotice === 'expired' && <div className="alert alert-warning" role="status">{t('login.sessionExpired')}</div>}
        <ErrorAlert message={error} />
        <HelpLabel>
          {t('login.email')}
          <HelpInput help="Enter a valid email address for this account or customer." type="email" value={email} onChange={(e) => setEmail(e.target.value)} required autoFocus autoComplete="username" />
        </HelpLabel>
        <HelpLabel>
          {t('login.password')}
          <HelpInput help="Enter the password for your OptiNexus account." type="password" value={password} onChange={(e) => setPassword(e.target.value)} required autoComplete="current-password" />
        </HelpLabel>
        <button className="btn btn-primary" type="submit" disabled={submitting}>
          {submitting ? t('login.submitting') : t('login.submit')}
        </button>
        <p className="muted login-note">{t('login.operatorsOnly')}</p>
        <LanguageSwitcher />
      </form>
    </div>
  );
}
