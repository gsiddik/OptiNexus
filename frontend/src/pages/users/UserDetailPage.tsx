import { useState } from 'react';
import { useParams, Link } from 'react-router-dom';
import { auditApi, rolesApi, usersApi } from '../../api/endpoints';
import { useApiResource } from '../../api/useApi';
import { LoadingSpinner } from '../../components/LoadingSpinner';
import { ErrorAlert } from '../../components/ErrorAlert';
import { StatusBadge } from '../../components/StatusBadge';
import { LifecycleActions } from '../../components/LifecycleActions';
import { useAuth } from '../../auth/useAuth';
import { unwrapError } from '../../api/client';
import type { AuditLog, EffectivePermission, Role, Tenant, User } from '../../api/types';

export function UserDetailPage() {
  const { id } = useParams<{ id: string }>();
  const { hasPermission } = useAuth();
  const { data: user, loading, error, reload } = useApiResource<User>(() => usersApi.get(id!), [id]);
  const [tab, setTab] = useState<'tenants' | 'roles' | 'permissions' | 'audit'>('tenants');

  if (loading) return <LoadingSpinner />;
  if (error) return <ErrorAlert message={error} />;
  if (!user) return null;

  return (
    <div>
      <Link to="/users" className="back-link">&larr; Back to Users</Link>
      <div className="page-header">
        <h1>{user.name}</h1>
        <StatusBadge status={user.status} />
      </div>
      <p className="muted">{user.email}</p>

      <LifecycleActions
        status={user.status}
        hasPermission={hasPermission}
        onDone={reload}
        actions={[
          { key: 'activate', label: 'Activate', allowedFrom: ['INVITED', 'SUSPENDED'], permission: 'cgo.user.activate', action: () => usersApi.activate(user.id) },
          { key: 'suspend', label: 'Suspend', allowedFrom: ['ACTIVE'], permission: 'cgo.user.suspend', action: () => usersApi.suspend(user.id) },
          { key: 'disable', label: 'Disable', allowedFrom: ['ACTIVE', 'SUSPENDED', 'INVITED'], permission: 'cgo.user.disable', danger: true, action: () => usersApi.disable(user.id) },
        ]}
      />

      <div className="tabs">
        <button className={tab === 'tenants' ? 'tab active' : 'tab'} onClick={() => setTab('tenants')}>Tenant Membership</button>
        <button className={tab === 'roles' ? 'tab active' : 'tab'} onClick={() => setTab('roles')}>Roles</button>
        <button className={tab === 'permissions' ? 'tab active' : 'tab'} onClick={() => setTab('permissions')}>Effective Permissions</button>
        <button className={tab === 'audit' ? 'tab active' : 'tab'} onClick={() => setTab('audit')}>Audit History</button>
      </div>

      {tab === 'tenants' && <TenantMembershipPanel user={user} />}
      {tab === 'roles' && <RolesPanel user={user} />}
      {tab === 'permissions' && <EffectivePermissionsPanel user={user} />}
      {tab === 'audit' && <AuditHistoryPanel user={user} />}
    </div>
  );
}

function TenantMembershipPanel({ user }: { user: User }) {
  const { data: tenants, loading, error, reload } = useApiResource<Tenant[]>(() => usersApi.tenants(user.id), [user.id]);
  const [tenantId, setTenantId] = useState('');
  const [actionError, setActionError] = useState<string | null>(null);

  async function attach() {
    if (!tenantId) return;
    setActionError(null);
    try {
      await usersApi.attachTenant(user.id, tenantId);
      setTenantId('');
      reload();
    } catch (err) {
      setActionError(unwrapError(err).message);
    }
  }

  async function detach(id: string) {
    setActionError(null);
    try {
      await usersApi.detachTenant(user.id, id);
      reload();
    } catch (err) {
      setActionError(unwrapError(err).message);
    }
  }

  if (loading) return <LoadingSpinner />;
  return (
    <div className="card">
      <ErrorAlert message={error ?? actionError} />
      <div className="toolbar">
        <input placeholder="Tenant ID" value={tenantId} onChange={(e) => setTenantId(e.target.value)} />
        <button className="btn btn-secondary" onClick={attach} disabled={!tenantId}>Add Membership</button>
      </div>
      <ul className="chip-list">
        {tenants?.map((t) => (
          <li key={t.id} className="chip removable">
            {t.name} ({t.tenant_code})
            <button onClick={() => detach(t.id)} aria-label={`Remove ${t.name}`}>×</button>
          </li>
        ))}
        {!tenants?.length && <span className="muted">Not a member of any tenant.</span>}
      </ul>
    </div>
  );
}

function RolesPanel({ user }: { user: User }) {
  const { data: roles, loading, error, reload } = useApiResource<Role[]>(() => usersApi.roles(user.id), [user.id]);
  const { data: allRoles } = useApiResource(() => rolesApi.list({ per_page: 100 }).then((e) => e.data), []);
  const [roleId, setRoleId] = useState('');
  const [tenantId, setTenantId] = useState('');
  const [actionError, setActionError] = useState<string | null>(null);

  async function attach() {
    if (!roleId) return;
    setActionError(null);
    try {
      await usersApi.attachRole(user.id, roleId, tenantId || null);
      setRoleId('');
      setTenantId('');
      reload();
    } catch (err) {
      setActionError(unwrapError(err).message);
    }
  }

  async function detach(id: string) {
    setActionError(null);
    try {
      await usersApi.detachRole(user.id, id);
      reload();
    } catch (err) {
      setActionError(unwrapError(err).message);
    }
  }

  if (loading) return <LoadingSpinner />;
  return (
    <div className="card">
      <ErrorAlert message={error ?? actionError} />
      <div className="toolbar">
        <select value={roleId} onChange={(e) => setRoleId(e.target.value)}>
          <option value="">Select a role...</option>
          {(allRoles as Role[] | null)?.map((r) => <option key={r.id} value={r.id}>{r.name} ({r.role_type})</option>)}
        </select>
        <input placeholder="Tenant ID (if TENANT/APPLICATION role)" value={tenantId} onChange={(e) => setTenantId(e.target.value)} />
        <button className="btn btn-secondary" onClick={attach} disabled={!roleId}>Assign Role</button>
      </div>
      <ul className="chip-list">
        {roles?.map((r) => (
          <li key={r.id} className="chip removable">
            {r.name}
            <button onClick={() => detach(r.id)} aria-label={`Remove ${r.name}`}>×</button>
          </li>
        ))}
        {!roles?.length && <span className="muted">No roles assigned in this scope.</span>}
      </ul>
    </div>
  );
}

function EffectivePermissionsPanel({ user }: { user: User }) {
  const [tenantId, setTenantId] = useState('');
  const { data, loading, error } = useApiResource(
    () => usersApi.effectivePermissions(user.id, tenantId || undefined),
    [user.id, tenantId],
  );
  const permissions = (data as { permissions: EffectivePermission[] } | null)?.permissions ?? [];

  return (
    <div className="card">
      <div className="toolbar">
        <input placeholder="Filter by tenant ID (blank = global)" value={tenantId} onChange={(e) => setTenantId(e.target.value)} />
      </div>
      <ErrorAlert message={error} />
      {loading ? (
        <LoadingSpinner />
      ) : (
        <table className="data-table">
          <thead><tr><th>Permission</th><th>Scope</th></tr></thead>
          <tbody>
            {permissions.map((p) => (
              <tr key={p.permission_key}><td><code>{p.permission_key}</code></td><td><span className="badge badge-info">{p.scope}</span></td></tr>
            ))}
            {!permissions.length && <tr><td colSpan={2} className="empty-cell">No effective permissions in this scope.</td></tr>}
          </tbody>
        </table>
      )}
    </div>
  );
}

function AuditHistoryPanel({ user }: { user: User }) {
  const { data, loading, error } = useApiResource(() => auditApi.list({ actor_user_id: user.id, per_page: 25 }).then((e) => e.data), [user.id]);
  const logs = (data as AuditLog[] | null) ?? [];

  if (loading) return <LoadingSpinner />;
  return (
    <div className="card">
      <ErrorAlert message={error} />
      <table className="data-table">
        <thead><tr><th>Action</th><th>Resource</th><th>When</th></tr></thead>
        <tbody>
          {logs.map((log) => (
            <tr key={log.id}>
              <td>{log.action}</td>
              <td>{log.resource_type ?? '—'} {log.resource_id ?? ''}</td>
              <td>{new Date(log.created_at).toLocaleString()}</td>
            </tr>
          ))}
          {!logs.length && <tr><td colSpan={3} className="empty-cell">No audit history for this user yet.</td></tr>}
        </tbody>
      </table>
    </div>
  );
}
