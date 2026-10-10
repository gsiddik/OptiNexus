import { useState } from 'react';
import { useParams } from 'react-router-dom';
import { applicationsApi, tenantsApi } from '../../api/endpoints';
import { useApiResource } from '../../api/useApi';
import { LoadingSpinner } from '../../components/LoadingSpinner';
import { ErrorAlert } from '../../components/ErrorAlert';
import { StatusBadge } from '../../components/StatusBadge';
import { LifecycleActions } from '../../components/LifecycleActions';
import { useAuth } from '../../auth/useAuth';
import { unwrapError } from '../../api/client';
import type { Application, Tenant, User } from '../../api/types';

export function TenantDetailPage() {
  const { id } = useParams<{ id: string }>();
  const { hasPermission } = useAuth();
  const { data: tenant, loading, error, reload } = useApiResource<Tenant>(() => tenantsApi.get(id!), [id]);
  const [tab, setTab] = useState<'details' | 'applications' | 'admins'>('details');

  if (loading) return <LoadingSpinner />;
  if (error) return <ErrorAlert message={error} />;
  if (!tenant) return null;

  return (
    <div>
      <div className="page-header">
        <h1>{tenant.name}</h1>
        <StatusBadge status={tenant.status} />
      </div>

      <LifecycleActions
        status={tenant.status}
        hasPermission={hasPermission}
        onDone={reload}
        actions={[
          { key: 'provision', label: 'Provision', allowedFrom: ['DRAFT'], permission: 'cgo.tenant.provision', action: () => tenantsApi.provision(tenant.id) },
          { key: 'activate', label: 'Activate', allowedFrom: ['PROVISIONING'], permission: 'cgo.tenant.activate', action: () => tenantsApi.activate(tenant.id) },
          { key: 'suspend', label: 'Suspend', allowedFrom: ['ACTIVE'], permission: 'cgo.tenant.suspend', action: () => tenantsApi.suspend(tenant.id) },
          { key: 'reactivate', label: 'Reactivate', allowedFrom: ['SUSPENDED'], permission: 'cgo.tenant.activate', action: () => tenantsApi.reactivate(tenant.id) },
          { key: 'terminate', label: 'Terminate', allowedFrom: ['ACTIVE', 'SUSPENDED'], permission: 'cgo.tenant.terminate', danger: true, action: () => tenantsApi.terminate(tenant.id) },
          { key: 'archive', label: 'Archive', allowedFrom: ['TERMINATED'], permission: 'cgo.tenant.archive', danger: true, action: () => tenantsApi.archive(tenant.id) },
        ]}
      />

      <div className="tabs">
        <button className={tab === 'details' ? 'tab active' : 'tab'} onClick={() => setTab('details')}>Details</button>
        <button className={tab === 'applications' ? 'tab active' : 'tab'} onClick={() => setTab('applications')}>Applications</button>
        <button className={tab === 'admins' ? 'tab active' : 'tab'} onClick={() => setTab('admins')}>Admins</button>
      </div>

      {tab === 'details' && (
        <div className="card">
          <dl className="definition-list">
            <dt>Tenant Code</dt><dd>{tenant.tenant_code}</dd>
            <dt>Region</dt><dd>{tenant.region ?? '—'}</dd>
            <dt>Timezone</dt><dd>{tenant.timezone ?? '—'}</dd>
            <dt>Currency</dt><dd>{tenant.currency ?? '—'}</dd>
            <dt>Language</dt><dd>{tenant.language ?? '—'}</dd>
          </dl>
        </div>
      )}
      {tab === 'applications' && <TenantApplications tenant={tenant} />}
      {tab === 'admins' && <TenantAdmins tenant={tenant} />}
    </div>
  );
}

function TenantApplications({ tenant }: { tenant: Tenant }) {
  const { data: apps, loading, error, reload } = useApiResource<Application[]>(() => tenantsApi.applications(tenant.id), [tenant.id]);
  const { data: allApps } = useApiResource(() => applicationsApi.list({ per_page: 100 }).then((e) => e.data), []);
  const [selected, setSelected] = useState('');
  const [actionError, setActionError] = useState<string | null>(null);

  async function attach() {
    if (!selected) return;
    setActionError(null);
    try {
      await tenantsApi.attachApplication(tenant.id, selected);
      setSelected('');
      reload();
    } catch (err) {
      setActionError(unwrapError(err).message);
    }
  }

  async function detach(applicationId: string) {
    try {
      await tenantsApi.detachApplication(tenant.id, applicationId);
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
        <select value={selected} onChange={(e) => setSelected(e.target.value)}>
          <option value="">Assign an application...</option>
          {(allApps as Application[] | null)?.map((a) => (
            <option key={a.id} value={a.id}>{a.name}</option>
          ))}
        </select>
        <button className="btn btn-secondary" onClick={attach} disabled={!selected}>Assign</button>
      </div>
      <ul className="chip-list">
        {apps?.map((a) => (
          <li key={a.id} className="chip removable">
            {a.name}
            <button onClick={() => detach(a.id)} aria-label={`Remove ${a.name}`}>×</button>
          </li>
        ))}
        {!apps?.length && <span className="muted">No applications assigned yet.</span>}
      </ul>
    </div>
  );
}

function TenantAdmins({ tenant }: { tenant: Tenant }) {
  const { data: admins, loading, error, reload } = useApiResource<User[]>(() => tenantsApi.admins(tenant.id), [tenant.id]);
  const [userId, setUserId] = useState('');
  const [actionError, setActionError] = useState<string | null>(null);

  async function add() {
    if (!userId) return;
    setActionError(null);
    try {
      await tenantsApi.addAdmin(tenant.id, userId);
      setUserId('');
      reload();
    } catch (err) {
      setActionError(unwrapError(err).message);
    }
  }

  async function remove(id: string) {
    try {
      await tenantsApi.removeAdmin(tenant.id, id);
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
        <input placeholder="User ID to grant tenant-admin" value={userId} onChange={(e) => setUserId(e.target.value)} />
        <button className="btn btn-secondary" onClick={add} disabled={!userId}>Add Admin</button>
      </div>
      <ul className="chip-list">
        {admins?.map((u) => (
          <li key={u.id} className="chip removable">
            {u.name} ({u.email})
            <button onClick={() => remove(u.id)} aria-label={`Remove ${u.name}`}>×</button>
          </li>
        ))}
        {!admins?.length && <span className="muted">No tenant admins yet.</span>}
      </ul>
    </div>
  );
}
