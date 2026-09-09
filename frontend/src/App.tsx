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
import { ProductsListPage } from './pages/commercial/products/ProductsListPage';
import { ProductDetailPage } from './pages/commercial/products/ProductDetailPage';
import { PlansListPage } from './pages/commercial/plans/PlansListPage';
import { PlanDetailPage } from './pages/commercial/plans/PlanDetailPage';
import { AddonsListPage } from './pages/commercial/addons/AddonsListPage';
import { AddonDetailPage } from './pages/commercial/addons/AddonDetailPage';
import { PricesListPage } from './pages/commercial/pricing/PricesListPage';
import { PriceDetailPage } from './pages/commercial/pricing/PriceDetailPage';
import { PricingSimulatorPage } from './pages/commercial/pricing/PricingSimulatorPage';
import { SubscriptionsListPage } from './pages/commercial/subscriptions/SubscriptionsListPage';
import { SubscriptionDetailPage } from './pages/commercial/subscriptions/SubscriptionDetailPage';
import { TenantEntitlementsPage } from './pages/commercial/entitlements/TenantEntitlementsPage';
import { UsageEventsPage } from './pages/commercial/usage/UsageEventsPage';
import { BillingsListPage } from './pages/commercial/billing/BillingsListPage';
import { BillingDetailPage } from './pages/commercial/billing/BillingDetailPage';
import { InvoicesListPage } from './pages/commercial/invoices/InvoicesListPage';
import { InvoiceDetailPage } from './pages/commercial/invoices/InvoiceDetailPage';
import { PoliciesListPage } from './pages/orchestration/policies/PoliciesListPage';
import { PolicyDetailPage } from './pages/orchestration/policies/PolicyDetailPage';
import { WorkflowsListPage } from './pages/orchestration/workflows/WorkflowsListPage';
import { WorkflowDetailPage } from './pages/orchestration/workflows/WorkflowDetailPage';
import { WorkflowInstancesListPage } from './pages/orchestration/workflow-instances/WorkflowInstancesListPage';
import { WorkflowInstanceDetailPage } from './pages/orchestration/workflow-instances/WorkflowInstanceDetailPage';
import { ApprovalDefinitionsListPage } from './pages/orchestration/approvals/ApprovalDefinitionsListPage';
import { ApprovalDefinitionDetailPage } from './pages/orchestration/approvals/ApprovalDefinitionDetailPage';
import { ApprovalRequestsListPage } from './pages/orchestration/approvals/ApprovalRequestsListPage';
import { ApprovalRequestDetailPage } from './pages/orchestration/approvals/ApprovalRequestDetailPage';
import { IntegrationsListPage } from './pages/orchestration/integrations/IntegrationsListPage';
import { IntegrationDetailPage } from './pages/orchestration/integrations/IntegrationDetailPage';
import { EventCatalogListPage } from './pages/orchestration/events/EventCatalogListPage';
import { EventCatalogDetailPage } from './pages/orchestration/events/EventCatalogDetailPage';
import { EventDeliveriesListPage } from './pages/orchestration/events/EventDeliveriesListPage';
import { FeatureFlagsListPage } from './pages/orchestration/feature-flags/FeatureFlagsListPage';
import { FeatureFlagDetailPage } from './pages/orchestration/feature-flags/FeatureFlagDetailPage';
import { NotificationTemplatesListPage } from './pages/orchestration/notifications/NotificationTemplatesListPage';
import { NotificationTemplateDetailPage } from './pages/orchestration/notifications/NotificationTemplateDetailPage';
import { NotificationRulesListPage } from './pages/orchestration/notifications/NotificationRulesListPage';
import { NotificationRuleDetailPage } from './pages/orchestration/notifications/NotificationRuleDetailPage';
import { NotificationsListPage } from './pages/orchestration/notifications/NotificationsListPage';
import { NotificationDetailPage } from './pages/orchestration/notifications/NotificationDetailPage';

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
          <Route path="/products" element={<Protected><ProductsListPage /></Protected>} />
          <Route path="/products/:id" element={<Protected><ProductDetailPage /></Protected>} />
          <Route path="/plans" element={<Protected><PlansListPage /></Protected>} />
          <Route path="/plans/:id" element={<Protected><PlanDetailPage /></Protected>} />
          <Route path="/addons" element={<Protected><AddonsListPage /></Protected>} />
          <Route path="/addons/:id" element={<Protected><AddonDetailPage /></Protected>} />
          <Route path="/prices" element={<Protected><PricesListPage /></Protected>} />
          <Route path="/prices/:id" element={<Protected><PriceDetailPage /></Protected>} />
          <Route path="/pricing/simulate" element={<Protected><PricingSimulatorPage /></Protected>} />
          <Route path="/subscriptions" element={<Protected><SubscriptionsListPage /></Protected>} />
          <Route path="/subscriptions/:id" element={<Protected><SubscriptionDetailPage /></Protected>} />
          <Route path="/tenants/:tenantId/entitlements" element={<Protected><TenantEntitlementsPage /></Protected>} />
          <Route path="/usage" element={<Protected><UsageEventsPage /></Protected>} />
          <Route path="/billings" element={<Protected><BillingsListPage /></Protected>} />
          <Route path="/billings/:id" element={<Protected><BillingDetailPage /></Protected>} />
          <Route path="/invoices" element={<Protected><InvoicesListPage /></Protected>} />
          <Route path="/invoices/:id" element={<Protected><InvoiceDetailPage /></Protected>} />
          <Route path="/policies" element={<Protected><PoliciesListPage /></Protected>} />
          <Route path="/policies/:id" element={<Protected><PolicyDetailPage /></Protected>} />
          <Route path="/workflows" element={<Protected><WorkflowsListPage /></Protected>} />
          <Route path="/workflows/:id" element={<Protected><WorkflowDetailPage /></Protected>} />
          <Route path="/workflow-instances" element={<Protected><WorkflowInstancesListPage /></Protected>} />
          <Route path="/workflow-instances/:id" element={<Protected><WorkflowInstanceDetailPage /></Protected>} />
          <Route path="/approval-definitions" element={<Protected><ApprovalDefinitionsListPage /></Protected>} />
          <Route path="/approval-definitions/:id" element={<Protected><ApprovalDefinitionDetailPage /></Protected>} />
          <Route path="/approval-requests" element={<Protected><ApprovalRequestsListPage /></Protected>} />
          <Route path="/approval-requests/:id" element={<Protected><ApprovalRequestDetailPage /></Protected>} />
          <Route path="/integrations" element={<Protected><IntegrationsListPage /></Protected>} />
          <Route path="/integrations/:id" element={<Protected><IntegrationDetailPage /></Protected>} />
          <Route path="/event-catalog" element={<Protected><EventCatalogListPage /></Protected>} />
          <Route path="/event-catalog/:id" element={<Protected><EventCatalogDetailPage /></Protected>} />
          <Route path="/event-deliveries" element={<Protected><EventDeliveriesListPage /></Protected>} />
          <Route path="/feature-flags" element={<Protected><FeatureFlagsListPage /></Protected>} />
          <Route path="/feature-flags/:id" element={<Protected><FeatureFlagDetailPage /></Protected>} />
          <Route path="/notification-templates" element={<Protected><NotificationTemplatesListPage /></Protected>} />
          <Route path="/notification-templates/:id" element={<Protected><NotificationTemplateDetailPage /></Protected>} />
          <Route path="/notification-rules" element={<Protected><NotificationRulesListPage /></Protected>} />
          <Route path="/notification-rules/:id" element={<Protected><NotificationRuleDetailPage /></Protected>} />
          <Route path="/notifications" element={<Protected><NotificationsListPage /></Protected>} />
          <Route path="/notifications/:id" element={<Protected><NotificationDetailPage /></Protected>} />
          <Route path="*" element={<Navigate to="/" replace />} />
        </Routes>
      </AuthProvider>
    </BrowserRouter>
  );
}
