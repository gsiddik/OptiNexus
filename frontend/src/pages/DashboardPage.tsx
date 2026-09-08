import { useAuth } from '../auth/useAuth';

export function DashboardPage() {
  const { context } = useAuth();

  return (
    <div>
      <h1>Welcome, {context?.user.name}</h1>
      <p className="muted">
        {context?.active_tenant_id
          ? `Viewing governance data scoped to tenant ${context.active_tenant_id}.`
          : 'Viewing global/platform-scoped governance data. Select a tenant above to scope your view.'}
      </p>

      <div className="card-grid">
        <div className="card">
          <h3>Your Roles</h3>
          <ul className="chip-list">
            {context?.roles.map((r) => (
              <li key={r.id} className="chip">{r.name}</li>
            ))}
            {!context?.roles.length && <span className="muted">No roles in this scope.</span>}
          </ul>
        </div>
        <div className="card">
          <h3>Effective Permissions</h3>
          <p className="muted">{context?.effective_permissions.length ?? 0} permissions resolved in the current scope.</p>
        </div>
        <div className="card">
          <h3>Available Tenants</h3>
          <p className="muted">{context?.available_tenants.length ?? 0} tenant memberships.</p>
        </div>
      </div>
    </div>
  );
}
