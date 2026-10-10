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

/** Every page except sign-in: needs a session and shares one Layout, which stays mounted while you move between pages. */
function ProtectedLayout() {
  return (
    <RequireAuth>
      <Layout />
    </RequireAuth>
  );
}

export default function App() {
  return (
    <BrowserRouter>
      <AuthProvider>
        <Routes>
          <Route path="/login" element={<LoginPage />} />
          <Route element={<ProtectedLayout />}>
            <Route path="/" element={<DashboardPage />} />
            <Route path="/customers" element={<CustomersListPage />} />
            <Route path="/customers/:id" element={<CustomerDetailPage />} />
            <Route path="/tenants" element={<TenantsListPage />} />
            <Route path="/tenants/:id" element={<TenantDetailPage />} />
            <Route path="/applications" element={<ApplicationsListPage />} />
            <Route path="/applications/:id" element={<ApplicationDetailPage />} />
            <Route path="/permissions" element={<PermissionsListPage />} />
            <Route path="/roles" element={<RolesListPage />} />
            <Route path="/roles/:id" element={<RoleDetailPage />} />
            <Route path="/users" element={<UsersListPage />} />
            <Route path="/users/:id" element={<UserDetailPage />} />
            <Route path="/audit-logs" element={<AuditLogsPage />} />
            <Route path="/service-accounts" element={<ServiceAccountsPage />} />
            <Route path="/products" element={<ProductsListPage />} />
            <Route path="/products/:id" element={<ProductDetailPage />} />
            <Route path="/plans" element={<PlansListPage />} />
            <Route path="/plans/:id" element={<PlanDetailPage />} />
            <Route path="/addons" element={<AddonsListPage />} />
            <Route path="/addons/:id" element={<AddonDetailPage />} />
            <Route path="/prices" element={<PricesListPage />} />
            <Route path="/prices/:id" element={<PriceDetailPage />} />
            <Route path="/pricing/simulate" element={<PricingSimulatorPage />} />
            <Route path="/subscriptions" element={<SubscriptionsListPage />} />
            <Route path="/subscriptions/:id" element={<SubscriptionDetailPage />} />
            <Route path="/tenants/:tenantId/entitlements" element={<TenantEntitlementsPage />} />
            <Route path="/usage" element={<UsageEventsPage />} />
            <Route path="/billings" element={<BillingsListPage />} />
            <Route path="/billings/:id" element={<BillingDetailPage />} />
            <Route path="/invoices" element={<InvoicesListPage />} />
            <Route path="/invoices/:id" element={<InvoiceDetailPage />} />
            <Route path="/policies" element={<PoliciesListPage />} />
            <Route path="/policies/:id" element={<PolicyDetailPage />} />
            <Route path="/workflows" element={<WorkflowsListPage />} />
            <Route path="/workflows/:id" element={<WorkflowDetailPage />} />
            <Route path="/workflow-instances" element={<WorkflowInstancesListPage />} />
            <Route path="/workflow-instances/:id" element={<WorkflowInstanceDetailPage />} />
            <Route path="/approval-definitions" element={<ApprovalDefinitionsListPage />} />
            <Route path="/approval-definitions/:id" element={<ApprovalDefinitionDetailPage />} />
            <Route path="/approval-requests" element={<ApprovalRequestsListPage />} />
            <Route path="/approval-requests/:id" element={<ApprovalRequestDetailPage />} />
            <Route path="/integrations" element={<IntegrationsListPage />} />
            <Route path="/integrations/:id" element={<IntegrationDetailPage />} />
            <Route path="/event-catalog" element={<EventCatalogListPage />} />
            <Route path="/event-catalog/:id" element={<EventCatalogDetailPage />} />
            <Route path="/event-deliveries" element={<EventDeliveriesListPage />} />
            <Route path="/feature-flags" element={<FeatureFlagsListPage />} />
            <Route path="/feature-flags/:id" element={<FeatureFlagDetailPage />} />
            <Route path="/notification-templates" element={<NotificationTemplatesListPage />} />
            <Route path="/notification-templates/:id" element={<NotificationTemplateDetailPage />} />
            <Route path="/notification-rules" element={<NotificationRulesListPage />} />
            <Route path="/notification-rules/:id" element={<NotificationRuleDetailPage />} />
            <Route path="/notifications" element={<NotificationsListPage />} />
            <Route path="/notifications/:id" element={<NotificationDetailPage />} />
          </Route>
          <Route path="*" element={<Navigate to="/" replace />} />
        </Routes>
      </AuthProvider>
    </BrowserRouter>
  );
}
