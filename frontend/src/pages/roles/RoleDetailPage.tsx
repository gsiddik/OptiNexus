import { HelpLabel, HelpInput } from '../../components/FieldControls';
import { useMemo, useState } from 'react';
import { useParams } from 'react-router-dom';
import { permissionsApi, rolesApi, listAll } from '../../api/endpoints';
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
  const { data: allPermissions } = useApiResource(() => listAll((params) => permissionsApi.list({ ...params, status: 'ACTIVE' })), []);
  const [busy, setBusy] = useState(false);
  const [actionError, setActionError] = useState<string | null>(null);

  const grantedIds = useMemo(() => new Set((grantedPermissions ?? []).map((p) => p.id)), [grantedPermissions]);
  const grouped = useMemo(() => {
    const groups = new Map<string, Permission[]>();
    for (const p of (allPermissions as Permission[]) ?? []) {
      const key = p.application_name ?? p.permission_key.split('.')[0];
      if (!groups.has(key)) groups.set(key, []);
      groups.get(key)!.push(p);
    }
    return groups;
  }, [allPermissions]);

  async function changePermissions(permissions: Permission[], grant: boolean) {
    if (!role || busy) return;
    setActionError(null); setBusy(true);
    try {
      for (const permission of permissions) {
        if (grant && !grantedIds.has(permission.id)) await rolesApi.attachPermission(role.id, permission.id);
        if (!grant && grantedIds.has(permission.id)) await rolesApi.detachPermission(role.id, permission.id);
      }
    } catch (err) { setActionError(unwrapError(err).message); }
    finally { setBusy(false); reloadGranted(); }
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
      <p className="muted">Grouped by application and module. Tenant and application roles use subscribed capability permissions until customized. Assign the role to tenant members to see its subscribed capabilities. Toggling requires cgo.role.permission.grant, and you cannot grant a permission you do not hold yourself.</p>
      {[...grouped.entries()].map(([group, perms]) => (
        <div key={group} className="card permission-group">
          <h4>{group} <HelpLabel className="check-inline"><HelpInput type="checkbox" help="Select or clear all permissions in this application. System-managed roles are read-only."
            checked={perms.length > 0 && perms.every((p) => grantedIds.has(p.id))} disabled={busy || role.is_system || !hasPermission('cgo.role.permission.grant')}
            onChange={(event) => changePermissions(perms, event.target.checked)} />Select All</HelpLabel></h4>
          {[...perms.reduce((groups, p) => { const module = p.module_name ?? p.permission_key.split('.')[1] ?? 'General'; groups.set(module, [...(groups.get(module) ?? []), p]); return groups; }, new Map<string, Permission[]>())].map(([module, items]) => <fieldset key={module}><legend>{module}</legend><div className="permission-grid">
            {items.map((p) => (
              <HelpLabel key={p.id} className="permission-toggle">
                <HelpInput help="Check to grant this permission to the role; uncheck to revoke it."
                  type="checkbox"
                  checked={grantedIds.has(p.id)}
                  disabled={busy || role.is_system || !hasPermission('cgo.role.permission.grant')}
                  onChange={() => changePermissions([p], !grantedIds.has(p.id))}
                />
                <span>{p.permission_key}</span>
              </HelpLabel>
            ))}
          </div></fieldset>)}
        </div>
      ))}
    </div>
  );
}
