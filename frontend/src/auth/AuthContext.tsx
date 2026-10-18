import { createContext, useCallback, useEffect, useMemo, useState, type ReactNode } from 'react';
import { useNavigate } from 'react-router-dom';
import { authApi } from '../api/endpoints';
import { ApiRequestError, revokeToken, setAuthHandlers, TOKEN_STORAGE_KEY, unwrapError } from '../api/client';
import type { AuthContext as GovernanceContext } from '../api/types';
import { storage } from './storage';

export const ACTIVE_TENANT_KEY = 'cgo_active_tenant';

interface AuthState {
  token: string | null;
  context: GovernanceContext | null;
  loading: boolean;
  activeTenantId: string | null;
  /** The session could not be checked (server unreachable, 5xx). The token is kept; the user can retry or sign out. */
  contextError: ApiRequestError | null;
  /** The API refused this account for the portal (403 ECOSYSTEM_ACCESS_REQUIRED) during this page load. */
  accessDenied: boolean;
  /** Why the user was sent to the sign-in page without asking for it. */
  sessionNotice: 'expired' | null;
}

interface AuthContextValue extends AuthState {
  login: (email: string, password: string) => Promise<void>;
  logout: () => void;
  refreshContext: (tenantId?: string | null) => Promise<void>;
  retryContext: () => void;
  clearAccessDenied: () => void;
  setActiveTenantId: (tenantId: string | null) => void;
  hasPermission: (key: string) => boolean;
}

// eslint-disable-next-line react-refresh/only-export-components
export const AuthCtx = createContext<AuthContextValue | undefined>(undefined);

export function AuthProvider({ children }: { children: ReactNode }) {
  const navigate = useNavigate();
  const [token, setToken] = useState<string | null>(() => storage.get(TOKEN_STORAGE_KEY));
  const [context, setContext] = useState<GovernanceContext | null>(null);
  const [loading, setLoading] = useState(true);
  const [activeTenantId, setActiveTenantIdState] = useState<string | null>(() => storage.get(ACTIVE_TENANT_KEY));
  const [contextError, setContextError] = useState<ApiRequestError | null>(null);
  const [accessDenied, setAccessDenied] = useState(false);
  const [sessionNotice, setSessionNotice] = useState<'expired' | null>(null);

  /** Forgets the session on this device only (no request). */
  const clearLocal = useCallback(() => {
    storage.remove(TOKEN_STORAGE_KEY);
    storage.remove(ACTIVE_TENANT_KEY);
    setToken(null);
    setContext(null);
    setContextError(null);
  }, []);

  /** User-initiated sign out: revokes the token on the server (best effort) and always clears the device, whatever the server answers. */
  const logout = useCallback(() => {
    const existing = storage.get(TOKEN_STORAGE_KEY);
    clearLocal();
    setAccessDenied(false);
    setSessionNotice(null);
    if (existing) void revokeToken(existing);
  }, [clearLocal]);

  const refreshContext = useCallback(
    async (tenantId?: string | null) => {
      const requested = tenantId !== undefined ? tenantId : activeTenantId;
      try {
        let ctx: GovernanceContext;
        try {
          ctx = await authApi.context(requested ? { tenant_id: requested } : undefined);
        } catch (err) {
          // A remembered tenant the account no longer belongs to must not lock the operator out: fall back to the global scope.
          if (requested && unwrapError(err).code === 'TENANT_ACCESS_DENIED') {
            storage.remove(ACTIVE_TENANT_KEY);
            setActiveTenantIdState(null);
            ctx = await authApi.context();
          } else {
            throw err;
          }
        }
        setContext(ctx);
        setContextError(null);
      } catch (err) {
        const failure = unwrapError(err);
        // Session ended / not allowed for the portal: the response handlers below already cleared the session and chose the page.
        if (failure.kind === 'session' || failure.kind === 'ecosystem') return;
        setContextError(failure);
      }
    },
    [activeTenantId],
  );

  useEffect(() => {
    setAuthHandlers({
      onUnauthorized: () => {
        // A 401 without a stored token is just a failed sign-in; only an ended session needs the notice.
        if (!storage.get(TOKEN_STORAGE_KEY)) return;
        clearLocal();
        setSessionNotice('expired');
      },
      onEcosystemDenied: () => {
        const existing = storage.get(TOKEN_STORAGE_KEY);
        clearLocal();
        setSessionNotice(null);
        setAccessDenied(true);
        navigate('/access-denied', { replace: true });
        if (existing) void revokeToken(existing);
      },
    });
    return () => setAuthHandlers({});
  }, [clearLocal, navigate]);

  // Signing out in another tab signs out here too.
  useEffect(() => {
    const onStorage = (event: StorageEvent) => {
      if (event.key === TOKEN_STORAGE_KEY && !event.newValue) clearLocal();
    };
    window.addEventListener('storage', onStorage);
    return () => window.removeEventListener('storage', onStorage);
  }, [clearLocal]);

  useEffect(() => {
    if (!token) {
      setLoading(false);
      return;
    }
    setLoading(true);
    refreshContext(activeTenantId).finally(() => setLoading(false));
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [token]);

  const login = useCallback(async (email: string, password: string) => {
    const result = await authApi.login(email, password);
    storage.set(TOKEN_STORAGE_KEY, result.token);
    setAccessDenied(false);
    setSessionNotice(null);
    setToken(result.token);
  }, []);

  const retryContext = useCallback(() => {
    setLoading(true);
    setContextError(null);
    refreshContext().finally(() => setLoading(false));
  }, [refreshContext]);

  const setActiveTenantId = useCallback((tenantId: string | null) => {
    if (tenantId) {
      storage.set(ACTIVE_TENANT_KEY, tenantId);
    } else {
      storage.remove(ACTIVE_TENANT_KEY);
    }
    setActiveTenantIdState(tenantId);
    void refreshContext(tenantId);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  const hasPermission = useCallback(
    (key: string) => context?.effective_permissions.some((p) => p.permission_key === key) ?? false,
    [context],
  );

  const clearAccessDenied = useCallback(() => setAccessDenied(false), []);

  const value = useMemo<AuthContextValue>(
    () => ({
      token, context, loading, activeTenantId, contextError, accessDenied, sessionNotice,
      login, logout, refreshContext, retryContext, clearAccessDenied, setActiveTenantId, hasPermission,
    }),
    [token, context, loading, activeTenantId, contextError, accessDenied, sessionNotice, login, logout, refreshContext, retryContext, clearAccessDenied, setActiveTenantId, hasPermission],
  );

  return <AuthCtx.Provider value={value}>{children}</AuthCtx.Provider>;
}
