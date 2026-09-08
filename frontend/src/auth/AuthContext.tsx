import { createContext, useCallback, useEffect, useMemo, useState, type ReactNode } from 'react';
import { authApi } from '../api/endpoints';
import { onUnauthorized, TOKEN_STORAGE_KEY } from '../api/client';
import type { AuthContext as GovernanceContext } from '../api/types';

interface AuthState {
  token: string | null;
  context: GovernanceContext | null;
  loading: boolean;
  activeTenantId: string | null;
}

interface AuthContextValue extends AuthState {
  login: (email: string, password: string) => Promise<void>;
  logout: () => void;
  refreshContext: (tenantId?: string | null) => Promise<void>;
  setActiveTenantId: (tenantId: string | null) => void;
  hasPermission: (key: string) => boolean;
}

// eslint-disable-next-line react-refresh/only-export-components
export const AuthCtx = createContext<AuthContextValue | undefined>(undefined);

export function AuthProvider({ children }: { children: ReactNode }) {
  const [token, setToken] = useState<string | null>(() => localStorage.getItem(TOKEN_STORAGE_KEY));
  const [context, setContext] = useState<GovernanceContext | null>(null);
  const [loading, setLoading] = useState(true);
  const [activeTenantId, setActiveTenantIdState] = useState<string | null>(() => localStorage.getItem('cgo_active_tenant'));

  const logout = useCallback(() => {
    localStorage.removeItem(TOKEN_STORAGE_KEY);
    localStorage.removeItem('cgo_active_tenant');
    setToken(null);
    setContext(null);
  }, []);

  const refreshContext = useCallback(async (tenantId?: string | null) => {
    try {
      const effectiveTenant = tenantId !== undefined ? tenantId : activeTenantId;
      const ctx = await authApi.context(effectiveTenant ? { tenant_id: effectiveTenant } : undefined);
      setContext(ctx);
    } catch {
      logout();
    }
  }, [activeTenantId, logout]);

  useEffect(() => {
    onUnauthorized(() => logout());
  }, [logout]);

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
    localStorage.setItem(TOKEN_STORAGE_KEY, result.token);
    setToken(result.token);
  }, []);

  const setActiveTenantId = useCallback((tenantId: string | null) => {
    if (tenantId) {
      localStorage.setItem('cgo_active_tenant', tenantId);
    } else {
      localStorage.removeItem('cgo_active_tenant');
    }
    setActiveTenantIdState(tenantId);
    void refreshContext(tenantId);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  const hasPermission = useCallback(
    (key: string) => context?.effective_permissions.some((p) => p.permission_key === key) ?? false,
    [context],
  );

  const value = useMemo<AuthContextValue>(
    () => ({ token, context, loading, activeTenantId, login, logout, refreshContext, setActiveTenantId, hasPermission }),
    [token, context, loading, activeTenantId, login, logout, refreshContext, setActiveTenantId, hasPermission],
  );

  return <AuthCtx.Provider value={value}>{children}</AuthCtx.Provider>;
}
