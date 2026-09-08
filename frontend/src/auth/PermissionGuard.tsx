import type { ReactNode } from 'react';
import { useAuth } from './useAuth';

/**
 * UX-only gate: hides actions the user's current context doesn't carry the
 * permission for. This is never the security boundary - every mutating
 * request is re-checked server-side by CheckPermission middleware /
 * AuthorizationService regardless of what this component renders.
 */
export function PermissionGuard({ permission, children, fallback = null }: { permission: string; children: ReactNode; fallback?: ReactNode }) {
  const { hasPermission } = useAuth();
  return hasPermission(permission) ? <>{children}</> : <>{fallback}</>;
}
