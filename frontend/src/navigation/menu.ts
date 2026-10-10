/** The sidebar menu: items grouped into sections that expand and collapse. `label: null` is a section without a header. */
export type NavItem = { to: string; label: string; end?: boolean };
export type NavGroup = { label: string | null; items: NavItem[] };

export const NAV_GROUPS: NavGroup[] = [
  { label: null, items: [{ to: '/', label: 'Dashboard', end: true }] },
  {
    label: 'Organisation',
    items: [
      { to: '/customers', label: 'Customers' },
      { to: '/tenants', label: 'Tenants' },
      { to: '/users', label: 'Users' },
    ],
  },
  {
    label: 'Access Control',
    items: [
      { to: '/applications', label: 'Applications' },
      { to: '/permissions', label: 'Permissions' },
      { to: '/roles', label: 'Roles' },
      { to: '/service-accounts', label: 'Service Accounts' },
      { to: '/audit-logs', label: 'Audit Logs' },
    ],
  },
  {
    label: 'Catalog & Pricing',
    items: [
      { to: '/products', label: 'Products' },
      { to: '/plans', label: 'Plans' },
      { to: '/addons', label: 'Add-ons' },
      { to: '/prices', label: 'Pricing' },
      { to: '/pricing/simulate', label: 'Simulate Price' },
    ],
  },
  {
    label: 'Billing',
    items: [
      { to: '/subscriptions', label: 'Subscriptions' },
      { to: '/usage', label: 'Usage' },
      { to: '/billings', label: 'Billing' },
      { to: '/invoices', label: 'Invoices' },
    ],
  },
  {
    label: 'Governance',
    items: [
      { to: '/policies', label: 'Policies' },
      { to: '/workflows', label: 'Workflows' },
      { to: '/workflow-instances', label: 'Workflow Instances' },
      { to: '/approval-definitions', label: 'Approval Definitions' },
      { to: '/approval-requests', label: 'Approval Requests' },
      { to: '/feature-flags', label: 'Feature Flags' },
    ],
  },
  {
    label: 'Integration',
    items: [
      { to: '/integrations', label: 'Integrations' },
      { to: '/event-catalog', label: 'Event Catalog' },
      { to: '/event-deliveries', label: 'Event Deliveries' },
    ],
  },
  {
    label: 'Notifications',
    items: [
      { to: '/notification-templates', label: 'Notification Templates' },
      { to: '/notification-rules', label: 'Notification Rules' },
      { to: '/notifications', label: 'Notifications' },
    ],
  },
];
