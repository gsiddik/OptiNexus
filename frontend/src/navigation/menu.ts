import type { MessageKey } from '../i18n';

/**
 * The sidebar menu: items grouped into sections that expand and collapse. `label: null` is a section without a header.
 * `label` stays the English name (it is also the stable id a group is remembered by); `labelKey` is what is shown.
 */
export type NavItem = { to: string; label: string; labelKey: MessageKey; end?: boolean };
export type NavGroup = { label: string | null; labelKey?: MessageKey; items: NavItem[] };

export const NAV_GROUPS: NavGroup[] = [
  { label: null, items: [{ to: '/', label: 'Dashboard', labelKey: 'nav.dashboard', end: true }] },
  {
    label: 'Organisation',
    labelKey: 'nav.group.organisation',
    items: [
      { to: '/customers', label: 'Customers', labelKey: 'nav.customers' },
      { to: '/tenants', label: 'Tenants', labelKey: 'nav.tenants' },
      { to: '/onboarding', label: 'Onboarding', labelKey: 'nav.onboarding' },
      { to: '/users', label: 'Users', labelKey: 'nav.users' },
    ],
  },
  {
    label: 'Access Control',
    labelKey: 'nav.group.access',
    items: [
      { to: '/applications', label: 'Applications', labelKey: 'nav.applications' },
      { to: '/permissions', label: 'Permissions', labelKey: 'nav.permissions' },
      { to: '/roles', label: 'Roles', labelKey: 'nav.roles' },
      { to: '/service-accounts', label: 'Service Accounts', labelKey: 'nav.serviceAccounts' },
      { to: '/sso-clients', label: 'SSO Clients', labelKey: 'nav.ssoClients' },
      { to: '/audit-logs', label: 'Audit Logs', labelKey: 'nav.auditLogs' },
    ],
  },
  {
    label: 'Catalog & Pricing',
    labelKey: 'nav.group.catalog',
    items: [
      { to: '/products', label: 'Products', labelKey: 'nav.products' },
      { to: '/plans', label: 'Plans', labelKey: 'nav.plans' },
      { to: '/addons', label: 'Add-ons', labelKey: 'nav.addons' },
      { to: '/prices', label: 'Pricing', labelKey: 'nav.prices' },
      { to: '/pricing/simulate', label: 'Simulate Price', labelKey: 'nav.simulatePrice' },
    ],
  },
  {
    label: 'Billing',
    labelKey: 'nav.group.billing',
    items: [
      { to: '/subscriptions', label: 'Subscriptions', labelKey: 'nav.subscriptions' },
      { to: '/usage', label: 'Usage', labelKey: 'nav.usage' },
      { to: '/billings', label: 'Billing', labelKey: 'nav.billings' },
      { to: '/invoices', label: 'Invoices', labelKey: 'nav.invoices' },
    ],
  },
  {
    label: 'Governance',
    labelKey: 'nav.group.governance',
    items: [
      { to: '/policies', label: 'Policies', labelKey: 'nav.policies' },
      { to: '/workflows', label: 'Workflows', labelKey: 'nav.workflows' },
      { to: '/workflow-instances', label: 'Workflow Instances', labelKey: 'nav.workflowInstances' },
      { to: '/approval-definitions', label: 'Approval Definitions', labelKey: 'nav.approvalDefinitions' },
      { to: '/approval-requests', label: 'Approval Requests', labelKey: 'nav.approvalRequests' },
      { to: '/feature-flags', label: 'Feature Flags', labelKey: 'nav.featureFlags' },
    ],
  },
  {
    label: 'Integration',
    labelKey: 'nav.group.integration',
    items: [
      { to: '/integrations', label: 'Integrations', labelKey: 'nav.integrations' },
      { to: '/gateway', label: 'Vehicle Mapping', labelKey: 'nav.gateway' },
      { to: '/event-catalog', label: 'Event Catalog', labelKey: 'nav.eventCatalog' },
      { to: '/event-deliveries', label: 'Event Deliveries', labelKey: 'nav.eventDeliveries' },
    ],
  },
  {
    label: 'Notifications',
    labelKey: 'nav.group.notifications',
    items: [
      { to: '/notification-templates', label: 'Notification Templates', labelKey: 'nav.notificationTemplates' },
      { to: '/notification-rules', label: 'Notification Rules', labelKey: 'nav.notificationRules' },
      { to: '/notifications', label: 'Notifications', labelKey: 'nav.notifications' },
    ],
  },
];
