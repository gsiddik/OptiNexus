import { useState } from 'react';
import { useParams } from 'react-router-dom';
import { applicationsApi, capabilitiesApi } from '../../api/endpoints';
import { useApiResource } from '../../api/useApi';
import { LoadingSpinner } from '../../components/LoadingSpinner';
import { ErrorAlert } from '../../components/ErrorAlert';
import { StatusBadge } from '../../components/StatusBadge';
import { LifecycleActions } from '../../components/LifecycleActions';
import { CapabilityTree } from '../../components/CapabilityTree';
import { useAuth } from '../../auth/useAuth';
import { unwrapError } from '../../api/client';
import type { Application, Capability, CapabilityType, Permission } from '../../api/types';

const CAPABILITY_TYPES: CapabilityType[] = ['MODULE', 'MENU', 'SUBMENU', 'FEATURE', 'FUNCTION', 'ACTION'];

export function ApplicationDetailPage() {
  const { id } = useParams<{ id: string }>();
  const { hasPermission } = useAuth();
  const { data: application, loading, error, reload } = useApiResource<Application>(() => applicationsApi.get(id!), [id]);
  const [tab, setTab] = useState<'details' | 'capabilities' | 'permissions'>('details');

  if (loading) return <LoadingSpinner />;
  if (error) return <ErrorAlert message={error} />;
  if (!application) return null;

  return (
    <div>
      <div className="page-header">
        <h1>{application.name}</h1>
        <StatusBadge status={application.status} />
      </div>

      <LifecycleActions
        status={application.status}
        hasPermission={hasPermission}
        onDone={reload}
        actions={[
          { key: 'submit', label: 'Submit for Review', allowedFrom: ['DRAFT'], permission: 'cgo.application.submit', action: () => applicationsApi.submit(application.id) },
          { key: 'approve', label: 'Approve', allowedFrom: ['REVIEW'], permission: 'cgo.application.approve', action: () => applicationsApi.approve(application.id) },
          { key: 'publish', label: 'Publish', allowedFrom: ['APPROVED'], permission: 'cgo.application.publish', action: () => applicationsApi.publish(application.id) },
          { key: 'deprecate', label: 'Deprecate', allowedFrom: ['PUBLISHED'], permission: 'cgo.application.deprecate', danger: true, action: () => applicationsApi.deprecate(application.id) },
          { key: 'retire', label: 'Retire', allowedFrom: ['DEPRECATED', 'PUBLISHED'], permission: 'cgo.application.retire', danger: true, action: () => applicationsApi.retire(application.id) },
        ]}
      />

      <div className="tabs">
        <button className={tab === 'details' ? 'tab active' : 'tab'} onClick={() => setTab('details')}>Details</button>
        <button className={tab === 'capabilities' ? 'tab active' : 'tab'} onClick={() => setTab('capabilities')}>Capabilities</button>
        <button className={tab === 'permissions' ? 'tab active' : 'tab'} onClick={() => setTab('permissions')}>Permissions</button>
      </div>

      {tab === 'details' && (
        <div className="card">
          <dl className="definition-list">
            <dt>Application Code</dt><dd>{application.application_code}</dd>
            <dt>Owner</dt><dd>{application.owner ?? '—'}</dd>
            <dt>Version</dt><dd>{application.version ?? '—'}</dd>
            <dt>Description</dt><dd>{application.description ?? '—'}</dd>
          </dl>
        </div>
      )}
      {tab === 'capabilities' && <CapabilitiesPanel application={application} />}
      {tab === 'permissions' && <PermissionsPanel application={application} />}
    </div>
  );
}

function CapabilitiesPanel({ application }: { application: Application }) {
  const { data: tree, loading, error, reload } = useApiResource<Capability[]>(() => applicationsApi.capabilityTree(application.id), [application.id]);
  const [selected, setSelected] = useState<Capability | null>(null);
  const [showCreate, setShowCreate] = useState(false);
  const { hasPermission } = useAuth();

  if (loading) return <LoadingSpinner />;

  return (
    <div className="split-panel">
      <div className="card tree-panel">
        <div className="toolbar">
          <h3>Hierarchy</h3>
          {hasPermission('cgo.capability.create') && (
            <button className="btn btn-secondary" onClick={() => setShowCreate((v) => !v)}>{showCreate ? 'Cancel' : '+ Add Capability'}</button>
          )}
        </div>
        <ErrorAlert message={error} />
        {showCreate && (
          <CreateCapabilityForm
            application={application}
            parentOptions={flatten(tree ?? [])}
            onCreated={() => { setShowCreate(false); reload(); }}
          />
        )}
        <CapabilityTree nodes={tree ?? []} onSelect={setSelected} selectedId={selected?.id} />
      </div>
      <div className="card detail-panel">
        {selected ? (
          <CapabilityDetail capability={selected} onChanged={() => { reload(); setSelected(null); }} />
        ) : (
          <p className="muted">Select a capability to view its details.</p>
        )}
      </div>
    </div>
  );
}

function flatten(nodes: Capability[]): Capability[] {
  return nodes.flatMap((n) => [n, ...flatten(n.children ?? [])]);
}

function CreateCapabilityForm({ application, parentOptions, onCreated }: { application: Application; parentOptions: Capability[]; onCreated: () => void }) {
  const [form, setForm] = useState({ type: 'MODULE' as CapabilityType, code: '', name: '', parent_id: '' });
  const [error, setError] = useState<string | null>(null);

  async function submit() {
    setError(null);
    try {
      await applicationsApi.createCapability(application.id, { ...form, parent_id: form.parent_id || null });
      onCreated();
    } catch (err) {
      setError(unwrapError(err).message);
    }
  }

  return (
    <div className="form form-inline">
      <ErrorAlert message={error} />
      <select value={form.type} onChange={(e) => setForm({ ...form, type: e.target.value as CapabilityType })}>
        {CAPABILITY_TYPES.map((t) => <option key={t} value={t}>{t}</option>)}
      </select>
      <input placeholder="code" value={form.code} onChange={(e) => setForm({ ...form, code: e.target.value })} />
      <input placeholder="name" value={form.name} onChange={(e) => setForm({ ...form, name: e.target.value })} />
      <select value={form.parent_id} onChange={(e) => setForm({ ...form, parent_id: e.target.value })}>
        <option value="">No parent (top-level)</option>
        {parentOptions.map((p) => <option key={p.id} value={p.id}>{p.name} ({p.type})</option>)}
      </select>
      <button className="btn btn-primary" onClick={submit}>Add</button>
    </div>
  );
}

function CapabilityDetail({ capability, onChanged }: { capability: Capability; onChanged: () => void }) {
  const { hasPermission } = useAuth();
  const { data: permissions } = useApiResource<Permission[]>(() => capabilitiesApi.permissions(capability.id), [capability.id]);
  const [error, setError] = useState<string | null>(null);

  async function transition(fn: () => Promise<unknown>) {
    setError(null);
    try {
      await fn();
      onChanged();
    } catch (err) {
      setError(unwrapError(err).message);
    }
  }

  return (
    <div>
      <h3>{capability.name}</h3>
      <p className="muted">{capability.type} · {capability.code}</p>
      <StatusBadge status={capability.status} />
      <ErrorAlert message={error} />
      <div className="lifecycle-actions">
        {capability.status !== 'ACTIVE' && hasPermission('cgo.capability.activate') && (
          <button className="btn btn-secondary" onClick={() => transition(() => capabilitiesApi.activate(capability.id))}>Activate</button>
        )}
        {capability.status !== 'DISABLED' && hasPermission('cgo.capability.disable') && (
          <button className="btn btn-secondary" onClick={() => transition(() => capabilitiesApi.disable(capability.id))}>Disable</button>
        )}
        {capability.status !== 'DEPRECATED' && hasPermission('cgo.capability.deprecate') && (
          <button className="btn btn-danger" onClick={() => transition(() => capabilitiesApi.deprecate(capability.id))}>Deprecate</button>
        )}
      </div>
      <h4>Mapped Permissions</h4>
      <ul className="chip-list">
        {permissions?.map((p) => <li key={p.id} className="chip">{p.permission_key}</li>)}
        {!permissions?.length && <span className="muted">No permissions mapped to this capability.</span>}
      </ul>
    </div>
  );
}

function PermissionsPanel({ application }: { application: Application }) {
  const { data: permissions, loading, error } = useApiResource<Permission[]>(() => applicationsApi.permissions(application.id), [application.id]);
  if (loading) return <LoadingSpinner />;
  return (
    <div className="card">
      <ErrorAlert message={error} />
      <table className="data-table">
        <thead><tr><th>Key</th><th>Name</th><th>Status</th></tr></thead>
        <tbody>
          {permissions?.map((p) => (
            <tr key={p.id}><td>{p.permission_key}</td><td>{p.name}</td><td><StatusBadge status={p.status} /></td></tr>
          ))}
          {!permissions?.length && <tr><td colSpan={3} className="empty-cell">No permissions registered yet.</td></tr>}
        </tbody>
      </table>
    </div>
  );
}
