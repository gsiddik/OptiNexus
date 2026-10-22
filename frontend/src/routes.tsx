import { isValidElement, type ReactNode } from 'react';
import { Route, Navigate, createRoutesFromChildren, matchRoutes } from 'react-router-dom';
import { ProtectedLayout } from './components/ProtectedLayout';
import { lazyPage, type LazyPage } from './routing/lazyPage';
// The pages needed when nothing else can be loaded stay in the first download: sign-in and the explanation for a refused account.
import { AccessDeniedPage } from './pages/AccessDeniedPage';
import { LoginPage } from './pages/LoginPage';

// Every other page is its own file, fetched when it is first opened.
const DashboardPage = lazyPage(() => import('./pages/DashboardPage'), 'DashboardPage');
const CustomersListPage = lazyPage(() => import('./pages/customers/CustomersListPage'), 'CustomersListPage');
const CustomerDetailPage = lazyPage(() => import('./pages/customers/CustomerDetailPage'), 'CustomerDetailPage');
const TenantsListPage = lazyPage(() => import('./pages/tenants/TenantsListPage'), 'TenantsListPage');
const TenantDetailPage = lazyPage(() => import('./pages/tenants/TenantDetailPage'), 'TenantDetailPage');
const TenantApplicationsPage = lazyPage(() => import('./pages/tenants/TenantDetailPage'), 'TenantApplicationsPage');
const TenantAdminsPage = lazyPage(() => import('./pages/tenants/TenantDetailPage'), 'TenantAdminsPage');
const OnboardingPage = lazyPage(() => import('./pages/onboarding/OnboardingPage'), 'OnboardingPage');
const OnboardingPickerPage = lazyPage(() => import('./pages/tenants/TenantPickerPage'), 'OnboardingPickerPage');
const ApplicationsListPage = lazyPage(() => import('./pages/applications/ApplicationsListPage'), 'ApplicationsListPage');
const ApplicationDetailPage = lazyPage(() => import('./pages/applications/ApplicationDetailPage'), 'ApplicationDetailPage');
const PermissionsListPage = lazyPage(() => import('./pages/permissions/PermissionsListPage'), 'PermissionsListPage');
const RolesListPage = lazyPage(() => import('./pages/roles/RolesListPage'), 'RolesListPage');
const RoleDetailPage = lazyPage(() => import('./pages/roles/RoleDetailPage'), 'RoleDetailPage');
const UsersListPage = lazyPage(() => import('./pages/users/UsersListPage'), 'UsersListPage');
const UserDetailPage = lazyPage(() => import('./pages/users/UserDetailPage'), 'UserDetailPage');
const AuditLogsPage = lazyPage(() => import('./pages/audit/AuditLogsPage'), 'AuditLogsPage');
const ServiceAccountsPage = lazyPage(() => import('./pages/service-accounts/ServiceAccountsPage'), 'ServiceAccountsPage');
const OidcClientsPage = lazyPage(() => import('./pages/sso/OidcClientsPage'), 'OidcClientsPage');
const GatewayPage = lazyPage(() => import('./pages/gateway/GatewayPage'), 'GatewayPage');
const GatewayPickerPage = lazyPage(() => import('./pages/tenants/TenantPickerPage'), 'GatewayPickerPage');
const ProductsListPage = lazyPage(() => import('./pages/commercial/products/ProductsListPage'), 'ProductsListPage');
const ProductDetailPage = lazyPage(() => import('./pages/commercial/products/ProductDetailPage'), 'ProductDetailPage');
const PlansListPage = lazyPage(() => import('./pages/commercial/plans/PlansListPage'), 'PlansListPage');
const PlanDetailPage = lazyPage(() => import('./pages/commercial/plans/PlanDetailPage'), 'PlanDetailPage');
const AddonsListPage = lazyPage(() => import('./pages/commercial/addons/AddonsListPage'), 'AddonsListPage');
const AddonDetailPage = lazyPage(() => import('./pages/commercial/addons/AddonDetailPage'), 'AddonDetailPage');
const PricesListPage = lazyPage(() => import('./pages/commercial/pricing/PricesListPage'), 'PricesListPage');
const PriceDetailPage = lazyPage(() => import('./pages/commercial/pricing/PriceDetailPage'), 'PriceDetailPage');
const PricingSimulatorPage = lazyPage(() => import('./pages/commercial/pricing/PricingSimulatorPage'), 'PricingSimulatorPage');
const SubscriptionsListPage = lazyPage(() => import('./pages/commercial/subscriptions/SubscriptionsListPage'), 'SubscriptionsListPage');
const SubscriptionDetailPage = lazyPage(() => import('./pages/commercial/subscriptions/SubscriptionDetailPage'), 'SubscriptionDetailPage');
const TenantEntitlementsPage = lazyPage(() => import('./pages/commercial/entitlements/TenantEntitlementsPage'), 'TenantEntitlementsPage');
const UsageEventsPage = lazyPage(() => import('./pages/commercial/usage/UsageEventsPage'), 'UsageEventsPage');
const BillingsListPage = lazyPage(() => import('./pages/commercial/billing/BillingsListPage'), 'BillingsListPage');
const BillingDetailPage = lazyPage(() => import('./pages/commercial/billing/BillingDetailPage'), 'BillingDetailPage');
const InvoicesListPage = lazyPage(() => import('./pages/commercial/invoices/InvoicesListPage'), 'InvoicesListPage');
const InvoicePaymentDetailPage = lazyPage(() => import('./pages/commercial/invoices/InvoicePaymentDetailPage'), 'InvoicePaymentDetailPage');
const InvoiceDetailPage = lazyPage(() => import('./pages/commercial/invoices/InvoiceDetailPage'), 'InvoiceDetailPage');
const PoliciesListPage = lazyPage(() => import('./pages/orchestration/policies/PoliciesListPage'), 'PoliciesListPage');
const PolicyDetailPage = lazyPage(() => import('./pages/orchestration/policies/PolicyDetailPage'), 'PolicyDetailPage');
const WorkflowsListPage = lazyPage(() => import('./pages/orchestration/workflows/WorkflowsListPage'), 'WorkflowsListPage');
const WorkflowDetailPage = lazyPage(() => import('./pages/orchestration/workflows/WorkflowDetailPage'), 'WorkflowDetailPage');
const WorkflowInstancesListPage = lazyPage(() => import('./pages/orchestration/workflow-instances/WorkflowInstancesListPage'), 'WorkflowInstancesListPage');
const WorkflowInstanceDetailPage = lazyPage(() => import('./pages/orchestration/workflow-instances/WorkflowInstanceDetailPage'), 'WorkflowInstanceDetailPage');
const ApprovalDefinitionsListPage = lazyPage(() => import('./pages/orchestration/approvals/ApprovalDefinitionsListPage'), 'ApprovalDefinitionsListPage');
const ApprovalDefinitionDetailPage = lazyPage(() => import('./pages/orchestration/approvals/ApprovalDefinitionDetailPage'), 'ApprovalDefinitionDetailPage');
const ApprovalRequestsListPage = lazyPage(() => import('./pages/orchestration/approvals/ApprovalRequestsListPage'), 'ApprovalRequestsListPage');
const ApprovalRequestDetailPage = lazyPage(() => import('./pages/orchestration/approvals/ApprovalRequestDetailPage'), 'ApprovalRequestDetailPage');
const IntegrationsListPage = lazyPage(() => import('./pages/orchestration/integrations/IntegrationsListPage'), 'IntegrationsListPage');
const IntegrationDetailPage = lazyPage(() => import('./pages/orchestration/integrations/IntegrationDetailPage'), 'IntegrationDetailPage');
const EventCatalogListPage = lazyPage(() => import('./pages/orchestration/events/EventCatalogListPage'), 'EventCatalogListPage');
const EventCatalogDetailPage = lazyPage(() => import('./pages/orchestration/events/EventCatalogDetailPage'), 'EventCatalogDetailPage');
const EventDeliveriesListPage = lazyPage(() => import('./pages/orchestration/events/EventDeliveriesListPage'), 'EventDeliveriesListPage');
const FeatureFlagsListPage = lazyPage(() => import('./pages/orchestration/feature-flags/FeatureFlagsListPage'), 'FeatureFlagsListPage');
const FeatureFlagDetailPage = lazyPage(() => import('./pages/orchestration/feature-flags/FeatureFlagDetailPage'), 'FeatureFlagDetailPage');
const NotificationTemplatesListPage = lazyPage(() => import('./pages/orchestration/notifications/NotificationTemplatesListPage'), 'NotificationTemplatesListPage');
const NotificationTemplateDetailPage = lazyPage(() => import('./pages/orchestration/notifications/NotificationTemplateDetailPage'), 'NotificationTemplateDetailPage');
const NotificationRulesListPage = lazyPage(() => import('./pages/orchestration/notifications/NotificationRulesListPage'), 'NotificationRulesListPage');
const NotificationRuleDetailPage = lazyPage(() => import('./pages/orchestration/notifications/NotificationRuleDetailPage'), 'NotificationRuleDetailPage');
const NotificationsListPage = lazyPage(() => import('./pages/orchestration/notifications/NotificationsListPage'), 'NotificationsListPage');
const NotificationDetailPage = lazyPage(() => import('./pages/orchestration/notifications/NotificationDetailPage'), 'NotificationDetailPage');

/** Every route of the portal. Kept apart from the router components so tests and the preload can read it. */
export const routeTree = (
  <>
    <Route path="/login" element={<LoginPage />} />
    <Route path="/access-denied" element={<AccessDeniedPage />} />
    <Route element={<ProtectedLayout />}>
      <Route path="/" element={<DashboardPage />} />
      <Route path="/customers" element={<CustomersListPage />} />
      <Route path="/customers/:id" element={<CustomerDetailPage />} />
      <Route path="/tenants" element={<TenantsListPage />} />
      <Route path="/tenants/:id" element={<TenantDetailPage />} />
      <Route path="/tenants/:tenantId/applications" element={<TenantApplicationsPage />} />
      <Route path="/tenants/:tenantId/admins" element={<TenantAdminsPage />} />
      <Route path="/onboarding" element={<OnboardingPickerPage />} />
      <Route path="/tenants/:tenantId/onboarding" element={<OnboardingPage />} />
      <Route path="/gateway" element={<GatewayPickerPage />} />
      <Route path="/tenants/:tenantId/gateway" element={<GatewayPage />} />
      <Route path="/applications" element={<ApplicationsListPage />} />
      <Route path="/applications/:id" element={<ApplicationDetailPage />} />
      <Route path="/permissions" element={<PermissionsListPage />} />
      <Route path="/roles" element={<RolesListPage />} />
      <Route path="/roles/:id" element={<RoleDetailPage />} />
      <Route path="/users" element={<UsersListPage />} />
      <Route path="/users/:id" element={<UserDetailPage />} />
      <Route path="/audit-logs" element={<AuditLogsPage />} />
      <Route path="/service-accounts" element={<ServiceAccountsPage />} />
      <Route path="/sso-clients" element={<OidcClientsPage />} />
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
      <Route path="/invoice-payments/:id" element={<InvoicePaymentDetailPage />} />
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
  </>
);

/**
 * Starts fetching the page file of a URL. Called with the address the portal was opened on, so the file arrives while the session
 * is being checked instead of after it, and the page can be shown the moment the session is known.
 */
export function preloadPageFor(pathname: string) {
  const matches = matchRoutes(createRoutesFromChildren(routeTree), pathname) ?? [];
  for (const { route } of matches) {
    let node: ReactNode = route.element;
    while (isValidElement<{ children?: ReactNode }>(node)) {
      (node.type as Partial<LazyPage>).preload?.();
      node = node.props.children;
    }
  }
}
