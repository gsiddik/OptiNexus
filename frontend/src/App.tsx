import type { ReactNode } from 'react';
import { BrowserRouter, Routes, Route, Navigate } from 'react-router-dom';
import { AuthProvider } from './auth/AuthContext';
import { RequireAuth } from './auth/RequireAuth';
import { Layout } from './components/Layout';
import { LoginPage } from './pages/LoginPage';
import { DashboardPage } from './pages/DashboardPage';
import { CustomersListPage } from './pages/customers/CustomersListPage';
import { CustomerDetailPage } from './pages/customers/CustomerDetailPage';
import { TenantsListPage } from './pages/tenants/TenantsListPage';
import { TenantDetailPage } from './pages/tenants/TenantDetailPage';
import { ApplicationsListPage } from './pages/applications/ApplicationsListPage';
import { ApplicationDetailPage } from './pages/applications/ApplicationDetailPage';
import { PermissionsListPage } from './pages/permissions/PermissionsListPage';
import { RolesListPage } from './pages/roles/RolesListPage';
import { RoleDetailPage } from './pages/roles/RoleDetailPage';
import { UsersListPage } from './pages/users/UsersListPage';
import { UserDetailPage } from './pages/users/UserDetailPage';
import { AuditLogsPage } from './pages/audit/AuditLogsPage';
import { ServiceAccountsPage } from './pages/service-accounts/ServiceAccountsPage';

function Protected({ children }: { children: ReactNode }) {
  return (
    <RequireAuth>
      <Layout>{children}</Layout>
    </RequireAuth>
  );
}

export default function App() {
  return (
    <BrowserRouter>
      <AuthProvider>
        <Routes>
          <Route path="/login" element={<LoginPage />} />
          <Route path="/" element={<Protected><DashboardPage /></Protected>} />
          <Route path="/customers" element={<Protected><CustomersListPage /></Protected>} />
          <Route path="/customers/:id" element={<Protected><CustomerDetailPage /></Protected>} />
          <Route path="/tenants" element={<Protected><TenantsListPage /></Protected>} />
          <Route path="/tenants/:id" element={<Protected><TenantDetailPage /></Protected>} />
          <Route path="/applications" element={<Protected><ApplicationsListPage /></Protected>} />
          <Route path="/applications/:id" element={<Protected><ApplicationDetailPage /></Protected>} />
          <Route path="/permissions" element={<Protected><PermissionsListPage /></Protected>} />
          <Route path="/roles" element={<Protected><RolesListPage /></Protected>} />
          <Route path="/roles/:id" element={<Protected><RoleDetailPage /></Protected>} />
          <Route path="/users" element={<Protected><UsersListPage /></Protected>} />
          <Route path="/users/:id" element={<Protected><UserDetailPage /></Protected>} />
          <Route path="/audit-logs" element={<Protected><AuditLogsPage /></Protected>} />
          <Route path="/service-accounts" element={<Protected><ServiceAccountsPage /></Protected>} />
          <Route path="*" element={<Navigate to="/" replace />} />
        </Routes>
      </AuthProvider>
    </BrowserRouter>
  );
}
