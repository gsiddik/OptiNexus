import type { ReactNode } from 'react';
import { NavLink } from 'react-router-dom';
import { useAuth } from '../auth/useAuth';

const NAV_ITEMS = [
  { to: '/', label: 'Dashboard', end: true },
  { to: '/customers', label: 'Customers' },
  { to: '/tenants', label: 'Tenants' },
  { to: '/applications', label: 'Applications' },
  { to: '/permissions', label: 'Permissions' },
  { to: '/roles', label: 'Roles' },
  { to: '/users', label: 'Users' },
  { to: '/audit-logs', label: 'Audit Logs' },
  { to: '/service-accounts', label: 'Service Accounts' },
  { to: '/products', label: 'Products' },
  { to: '/plans', label: 'Plans' },
  { to: '/addons', label: 'Add-ons' },
  { to: '/prices', label: 'Pricing' },
  { to: '/pricing/simulate', label: 'Simulate Price' },
  { to: '/subscriptions', label: 'Subscriptions' },
  { to: '/usage', label: 'Usage' },
  { to: '/billings', label: 'Billing' },
  { to: '/invoices', label: 'Invoices' },
];

export function Layout({ children }: { children: ReactNode }) {
  const { context, activeTenantId, setActiveTenantId, logout } = useAuth();

  return (
    <div className="app-shell">
      <aside className="sidebar">
        <div className="brand">CGO Governance</div>
        <nav>
          {NAV_ITEMS.map((item) => (
            <NavLink key={item.to} to={item.to} end={item.end} className={({ isActive }) => `nav-link${isActive ? ' active' : ''}`}>
              {item.label}
            </NavLink>
          ))}
        </nav>
      </aside>
      <div className="main-column">
        <header className="topbar">
          <div className="tenant-switcher">
            <label htmlFor="tenant-select">Tenant:</label>
            <select
              id="tenant-select"
              value={activeTenantId ?? ''}
              onChange={(e) => setActiveTenantId(e.target.value || null)}
            >
              <option value="">Global (platform scope)</option>
              {context?.available_tenants.map((t) => (
                <option key={t.id} value={t.id}>
                  {t.name} ({t.tenant_code})
                </option>
              ))}
            </select>
          </div>
          <div className="user-menu">
            <span>{context?.user.name}</span>
            <button className="btn btn-ghost" onClick={logout}>Log out</button>
          </div>
        </header>
        <main className="content">{children}</main>
      </div>
    </div>
  );
}
