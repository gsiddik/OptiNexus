import { useMemo, useState } from 'react';
import { useParams } from 'react-router-dom';
import { permissionsApi, rolesApi } from '../../api/endpoints';
import { useApiResource } from '../../api/useApi';
import { LoadingSpinner } from '../../components/LoadingSpinner';
import { ErrorAlert } from '../../components/ErrorAlert';
import { StatusBadge } from '../../components/StatusBadge';
import { useAuth } from '../../auth/useAuth';
import { unwrapError } from '../../api/client';
import type { Permission, Role } from '../../api/types';

export function RoleDetailPage() {
  const { id } = useParams<{ id: string }>();
  const { hasPermission } = useAuth();
  const { data: role, loading, error, reload } = useApiResource<Role>(() => rolesApi.get(id!), [id]);
  const { data: grantedPermissions, reload: reloadGranted } = useApiResource<Permission[]>(() => rolesApi.permissions(id!), [id]);
  const { data: allPermissions } = useApiResource(() => permissionsApi.list({ per_page: 200, status: 'ACTIVE' }).then((e) => e.data), []);
  const [actionError, setActionError] = useState<string | null>(null);

  const grantedIds = useMemo(() => new Set((grantedPermissions ?? []).map((p) => p.id)), [grantedPermissions]);
  const grouped = useMemo(() => {
    const groups = new Map<string, Permission[]>();
    for (const p of (allPermissions as Permission[]) ?? []) {
      const key = p.permission_key.split('.')[0];
      if (!groups.has(key)) groups.set(key, []);
      groups.get(key)!.push(p);
    }
    return groups;
  }, [allPermissions]);

  async function toggle(permission: Permission, granted: boolean) {
    if (!role) return;
    setActionError(null);
    try {
      if (granted) {
        await rolesApi.detachPermission(role.id, permission.id);
      } else {
        await rolesApi.attachPermission(role.id, permission.id);
      }
      reloadGranted();
    } catch (err) {
      setActionError(unwrapError(err).message);
    }
  }

  async function disable() {
    if (!role) return;
    setActionError(null);
    try {
      await rolesApi.disable(role.id);
      reload();
    } catch (err) {
      setActionError(unwrapError(err).message);
    }
  }

  if (loading) return <LoadingSpinner />;
  if (error) return <ErrorAlert message={error} />;
  if (!role) return null;

  return (
    <div>
      <div className="page-header">
        <h1>{role.name}</h1>
        <StatusBadge status={role.status} />
      </div>
      <p className="muted">{role.role_type} role · {role.is_system ? 'System-managed' : 'Custom'}</p>

      <ErrorAlert message={actionError} />
      {!role.is_system && role.status === 'ACTIVE' && hasPermission('cgo.role.update') && (
        <button className="btn btn-danger" onClick={disable}>Disable Role</button>
      )}

      <h3 style={{ marginTop: '1.5rem' }}>Permission Matrix</h3>
      <p className="muted">Grouped by application/permission prefix. Toggling requires cgo.role.permission.grant, and you cannot grant a permission you do not hold yourself.</p>
      {[...grouped.entries()].map(([group, perms]) => (
        <div key={group} className="card permission-group">
          <h4>{group}</h4>
          <div className="permission-grid">
            {perms.map((p) => (
              <label key={p.id} className="permission-toggle">
                <input
                  type="checkbox"
                  checked={grantedIds.has(p.id)}
                  disabled={!hasPermission('cgo.role.permission.grant')}
                  onChange={() => toggle(p, grantedIds.has(p.id))}
                />
                <span>{p.permission_key}</span>
              </label>
            ))}
          </div>
        </div>
      ))}
    </div>
  );
}
