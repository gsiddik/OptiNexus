import type { ReactNode } from 'react';
import { Navigate, useLocation } from 'react-router-dom';
import { useAuth } from './useAuth';
import { useI18n } from '../i18n';
import { LoadingSpinner } from '../components/LoadingSpinner';
import { SessionProblem } from '../components/SessionProblem';

/**
 * Every portal page sits behind this gate:
 *  - no session: the sign-in page (or, right after the API refused the account for the portal, the explanation page, never a loop);
 *  - session being checked: a spinner; the check failing for a network/server reason: a retry page that keeps the sign-in;
 *  - otherwise the page.
 */
export function RequireAuth({ children }: { children: ReactNode }) {
  const { token, loading, contextError, accessDenied, context } = useAuth();
  const { t } = useI18n();
  const location = useLocation();

  if (!token) {
    if (accessDenied) return <Navigate to="/access-denied" replace />;
    return <Navigate to="/login" replace state={{ from: location }} />;
  }
  if (loading) {
    return <LoadingSpinner label={t('shell.sessionLoading')} />;
  }
  if (contextError || !context) {
    return <SessionProblem />;
  }
  return <>{children}</>;
}
