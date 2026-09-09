import { apiClient, type ApiEnvelope } from './client';
import type {
  AccessEvaluation,
  Addon,
  AddonLimit,
  ApprovalDefinition,
  ApprovalDelegation,
  ApprovalRequest,
  Application,
  AuditLog,
  AuthContext,
  Billing,
  Capability,
  Customer,
  EffectiveEntitlement,
  EffectivePermission,
  Entitlement,
  EventCatalogEntry,
  EventDelivery,
  FeatureFlag,
  FeatureFlagEvaluation,
  Integration,
  IntegrationCredential,
  IntegrationLog,
  Invoice,
  Notification,
  NotificationRule,
  NotificationTemplate,
  Permission,
  Plan,
  PlanLimit,
  Policy,
  PolicySimulationResult,
  Price,
  Product,
  Role,
  ServiceAccount,
  Subscription,
  Tenant,
  UsageEvent,
  User,
  Workflow,
  WorkflowInstance,
} from './types';

async function unwrap<T>(promise: Promise<{ data: ApiEnvelope<T> }>): Promise<T> {
  const res = await promise;
  return res.data.data;
}
async function unwrapFull<T>(promise: Promise<{ data: ApiEnvelope<T> }>): Promise<ApiEnvelope<T>> {
  const res = await promise;
  return res.data;
}

export interface ListParams {
  page?: number;
  per_page?: number;
  status?: string;
  search?: string;
  [key: string]: string | number | undefined;
}

// ---------------------------------------------------------------- Auth
export const authApi = {
  login: (email: string, password: string) =>
    unwrap<{ token: string; user: User }>(apiClient.post('/auth/login', { email, password })),
  logout: () => apiClient.post('/auth/logout'),
  context: (params?: { tenant_id?: string; application_id?: string }) =>
    unwrap<AuthContext>(apiClient.get('/auth/context', { params })),
};

// ------------------------------------------------------------ Customers
export const customersApi = {
  list: (params?: ListParams) => unwrapFull<Customer[]>(apiClient.get('/customers', { params })),
  get: (id: string) => unwrap<Customer>(apiClient.get(`/customers/${id}`)),
  create: (payload: Partial<Customer>) => unwrap<Customer>(apiClient.post('/customers', payload)),
  update: (id: string, payload: Partial<Customer>) => unwrap<Customer>(apiClient.put(`/customers/${id}`, payload)),
  activate: (id: string) => unwrap<Customer>(apiClient.post(`/customers/${id}/activate`)),
  suspend: (id: string) => unwrap<Customer>(apiClient.post(`/customers/${id}/suspend`)),
  terminate: (id: string) => unwrap<Customer>(apiClient.post(`/customers/${id}/terminate`)),
  archive: (id: string) => unwrap<Customer>(apiClient.post(`/customers/${id}/archive`)),
};

// -------------------------------------------------------------- Tenants
export const tenantsApi = {
  list: (params?: ListParams) => unwrapFull<Tenant[]>(apiClient.get('/tenants', { params })),
  get: (id: string) => unwrap<Tenant>(apiClient.get(`/tenants/${id}`)),
  create: (payload: Partial<Tenant>) => unwrap<Tenant>(apiClient.post('/tenants', payload)),
  update: (id: string, payload: Partial<Tenant>) => unwrap<Tenant>(apiClient.put(`/tenants/${id}`, payload)),
  provision: (id: string) => unwrap<Tenant>(apiClient.post(`/tenants/${id}/provision`)),
  activate: (id: string) => unwrap<Tenant>(apiClient.post(`/tenants/${id}/activate`)),
  suspend: (id: string) => unwrap<Tenant>(apiClient.post(`/tenants/${id}/suspend`)),
  reactivate: (id: string) => unwrap<Tenant>(apiClient.post(`/tenants/${id}/reactivate`)),
  terminate: (id: string) => unwrap<Tenant>(apiClient.post(`/tenants/${id}/terminate`)),
  archive: (id: string) => unwrap<Tenant>(apiClient.post(`/tenants/${id}/archive`)),
  applications: (id: string) => unwrap<Application[]>(apiClient.get(`/tenants/${id}/applications`)),
  attachApplication: (id: string, applicationId: string) =>
    apiClient.post(`/tenants/${id}/applications/${applicationId}`),
  detachApplication: (id: string, applicationId: string) =>
    apiClient.delete(`/tenants/${id}/applications/${applicationId}`),
  admins: (id: string) => unwrap<User[]>(apiClient.get(`/tenants/${id}/admins`)),
  addAdmin: (id: string, userId: string) => apiClient.post(`/tenants/${id}/admins/${userId}`),
  removeAdmin: (id: string, userId: string) => apiClient.delete(`/tenants/${id}/admins/${userId}`),
};

// ---------------------------------------------------------- Applications
export const applicationsApi = {
  list: (params?: ListParams) => unwrapFull<Application[]>(apiClient.get('/applications', { params })),
  get: (id: string) => unwrap<Application>(apiClient.get(`/applications/${id}`)),
  create: (payload: Partial<Application>) => unwrap<Application>(apiClient.post('/applications', payload)),
  update: (id: string, payload: Partial<Application>) => unwrap<Application>(apiClient.put(`/applications/${id}`, payload)),
  submit: (id: string) => unwrap<Application>(apiClient.post(`/applications/${id}/submit`)),
  approve: (id: string) => unwrap<Application>(apiClient.post(`/applications/${id}/approve`)),
  publish: (id: string) => unwrap<Application>(apiClient.post(`/applications/${id}/publish`)),
  deprecate: (id: string) => unwrap<Application>(apiClient.post(`/applications/${id}/deprecate`)),
  retire: (id: string) => unwrap<Application>(apiClient.post(`/applications/${id}/retire`)),
  permissions: (id: string) => unwrap<Permission[]>(apiClient.get(`/applications/${id}/permissions`)),
  capabilityTree: (id: string) => unwrap<Capability[]>(apiClient.get(`/applications/${id}/capabilities`, { params: { tree: 1 } })),
  createCapability: (id: string, payload: Partial<Capability>) =>
    unwrap<Capability>(apiClient.post(`/applications/${id}/capabilities`, payload)),
};

// ---------------------------------------------------------- Capabilities
export const capabilitiesApi = {
  get: (id: string) => unwrap<Capability>(apiClient.get(`/capabilities/${id}`)),
  update: (id: string, payload: Partial<Capability>) => unwrap<Capability>(apiClient.put(`/capabilities/${id}`, payload)),
  destroy: (id: string) => apiClient.delete(`/capabilities/${id}`),
  move: (id: string, parentId: string | null) => unwrap<Capability>(apiClient.post(`/capabilities/${id}/move`, { parent_id: parentId })),
  reorder: (id: string, sortOrder: number) => unwrap<Capability>(apiClient.post(`/capabilities/${id}/reorder`, { sort_order: sortOrder })),
  activate: (id: string) => unwrap<Capability>(apiClient.post(`/capabilities/${id}/activate`)),
  disable: (id: string) => unwrap<Capability>(apiClient.post(`/capabilities/${id}/disable`)),
  deprecate: (id: string) => unwrap<Capability>(apiClient.post(`/capabilities/${id}/deprecate`)),
  permissions: (id: string) => unwrap<Permission[]>(apiClient.get(`/capabilities/${id}/permissions`)),
  attachPermission: (id: string, permissionId: string) => apiClient.post(`/capabilities/${id}/permissions/${permissionId}`),
  detachPermission: (id: string, permissionId: string) => apiClient.delete(`/capabilities/${id}/permissions/${permissionId}`),
};

// ----------------------------------------------------------- Permissions
export const permissionsApi = {
  list: (params?: ListParams) => unwrapFull<Permission[]>(apiClient.get('/permissions', { params })),
  get: (id: string) => unwrap<Permission>(apiClient.get(`/permissions/${id}`)),
  create: (payload: Partial<Permission>) => unwrap<Permission>(apiClient.post('/permissions', payload)),
  update: (id: string, payload: Partial<Permission>) => unwrap<Permission>(apiClient.put(`/permissions/${id}`, payload)),
  deprecate: (id: string) => unwrap<Permission>(apiClient.post(`/permissions/${id}/deprecate`)),
};

// ----------------------------------------------------------------- Roles
export const rolesApi = {
  list: (params?: ListParams) => unwrapFull<Role[]>(apiClient.get('/roles', { params })),
  get: (id: string) => unwrap<Role>(apiClient.get(`/roles/${id}`)),
  create: (payload: Partial<Role>) => unwrap<Role>(apiClient.post('/roles', payload)),
  update: (id: string, payload: Partial<Role>) => unwrap<Role>(apiClient.put(`/roles/${id}`, payload)),
  clone: (id: string, name: string, code: string) => unwrap<Role>(apiClient.post(`/roles/${id}/clone`, { name, code })),
  disable: (id: string) => unwrap<Role>(apiClient.post(`/roles/${id}/disable`)),
  permissions: (id: string) => unwrap<Permission[]>(apiClient.get(`/roles/${id}/permissions`)),
  attachPermission: (id: string, permissionId: string) => apiClient.post(`/roles/${id}/permissions/${permissionId}`),
  detachPermission: (id: string, permissionId: string) => apiClient.delete(`/roles/${id}/permissions/${permissionId}`),
};

// ----------------------------------------------------------------- Users
export const usersApi = {
  list: (params?: ListParams) => unwrapFull<User[]>(apiClient.get('/users', { params })),
  get: (id: string) => unwrap<User>(apiClient.get(`/users/${id}`)),
  create: (payload: Partial<User>) => unwrap<User>(apiClient.post('/users', payload)),
  update: (id: string, payload: Partial<User>) => unwrap<User>(apiClient.put(`/users/${id}`, payload)),
  activate: (id: string) => unwrap<User>(apiClient.post(`/users/${id}/activate`)),
  suspend: (id: string) => unwrap<User>(apiClient.post(`/users/${id}/suspend`)),
  disable: (id: string) => unwrap<User>(apiClient.post(`/users/${id}/disable`)),
  tenants: (id: string) => unwrap<Tenant[]>(apiClient.get(`/users/${id}/tenants`)),
  attachTenant: (id: string, tenantId: string) => apiClient.post(`/users/${id}/tenants/${tenantId}`),
  detachTenant: (id: string, tenantId: string) => apiClient.delete(`/users/${id}/tenants/${tenantId}`),
  applications: (id: string) => unwrap<Application[]>(apiClient.get(`/users/${id}/applications`)),
  attachApplication: (id: string, applicationId: string, tenantId: string) =>
    apiClient.post(`/users/${id}/applications/${applicationId}`, { tenant_id: tenantId }),
  detachApplication: (id: string, applicationId: string, tenantId?: string) =>
    apiClient.delete(`/users/${id}/applications/${applicationId}`, { params: tenantId ? { tenant_id: tenantId } : undefined }),
  roles: (id: string, tenantId?: string) =>
    unwrap<Role[]>(apiClient.get(`/users/${id}/roles`, { params: tenantId ? { tenant_id: tenantId } : undefined })),
  attachRole: (id: string, roleId: string, tenantId?: string | null) =>
    apiClient.post(`/users/${id}/roles/${roleId}`, { tenant_id: tenantId ?? null }),
  detachRole: (id: string, roleId: string, tenantId?: string) =>
    apiClient.delete(`/users/${id}/roles/${roleId}`, { params: tenantId ? { tenant_id: tenantId } : undefined }),
  effectivePermissions: (id: string, tenantId?: string, applicationId?: string) =>
    unwrap<{ permissions: EffectivePermission[] }>(
      apiClient.get(`/users/${id}/effective-permissions`, { params: { tenant_id: tenantId, application_id: applicationId } }),
    ),
};

// ------------------------------------------------------------------ Audit
export const auditApi = {
  list: (params?: ListParams) => unwrapFull<AuditLog[]>(apiClient.get('/audit-logs', { params })),
  get: (id: string) => unwrap<AuditLog>(apiClient.get(`/audit-logs/${id}`)),
};

// --------------------------------------------------------- Service Accounts
export const serviceAccountsApi = {
  list: (params?: ListParams) => unwrapFull<ServiceAccount[]>(apiClient.get('/service-accounts', { params })),
  create: (payload: { name: string; application_id?: string; tenant_id?: string }) =>
    unwrap<{ service_account: ServiceAccount; client_id: string; client_secret: string }>(
      apiClient.post('/service-accounts', payload),
    ),
  rotateSecret: (id: string) => unwrap<{ client_id: string; client_secret: string }>(apiClient.post(`/service-accounts/${id}/rotate-secret`)),
  revoke: (id: string) => unwrap<ServiceAccount>(apiClient.post(`/service-accounts/${id}/revoke`)),
};

// ------------------------------------------------------- Phase 2: Commercial

// ---------------------------------------------------------------- Products
export const productsApi = {
  list: (params?: ListParams) => unwrapFull<Product[]>(apiClient.get('/products', { params })),
  get: (id: string) => unwrap<Product>(apiClient.get(`/products/${id}`)),
  create: (payload: Partial<Product>) => unwrap<Product>(apiClient.post('/products', payload)),
  update: (id: string, payload: Partial<Product>) => unwrap<Product>(apiClient.put(`/products/${id}`, payload)),
  activate: (id: string) => unwrap<Product>(apiClient.post(`/products/${id}/activate`)),
  deactivate: (id: string) => unwrap<Product>(apiClient.post(`/products/${id}/deactivate`)),
  retire: (id: string) => unwrap<Product>(apiClient.post(`/products/${id}/retire`)),
  applications: (id: string) => unwrap<Application[]>(apiClient.get(`/products/${id}/applications`)),
  attachApplication: (id: string, applicationId: string) => apiClient.post(`/products/${id}/applications/${applicationId}`),
  detachApplication: (id: string, applicationId: string) => apiClient.delete(`/products/${id}/applications/${applicationId}`),
  capabilities: (id: string) => unwrap<Capability[]>(apiClient.get(`/products/${id}/capabilities`)),
  attachCapability: (id: string, capabilityId: string) => apiClient.post(`/products/${id}/capabilities/${capabilityId}`),
  detachCapability: (id: string, capabilityId: string) => apiClient.delete(`/products/${id}/capabilities/${capabilityId}`),
};

// ------------------------------------------------------------------- Plans
export const plansApi = {
  list: (params?: ListParams) => unwrapFull<Plan[]>(apiClient.get('/plans', { params })),
  get: (id: string) => unwrap<Plan>(apiClient.get(`/plans/${id}`)),
  create: (payload: Partial<Plan>) => unwrap<Plan>(apiClient.post('/plans', payload)),
  update: (id: string, payload: Partial<Plan>) => unwrap<Plan>(apiClient.put(`/plans/${id}`, payload)),
  clone: (id: string, name: string, planCode: string) => unwrap<Plan>(apiClient.post(`/plans/${id}/clone`, { name, plan_code: planCode })),
  activate: (id: string) => unwrap<Plan>(apiClient.post(`/plans/${id}/activate`)),
  deactivate: (id: string) => unwrap<Plan>(apiClient.post(`/plans/${id}/deactivate`)),
  retire: (id: string) => unwrap<Plan>(apiClient.post(`/plans/${id}/retire`)),
  capabilities: (id: string) => unwrap<Capability[]>(apiClient.get(`/plans/${id}/capabilities`)),
  attachCapability: (id: string, capabilityId: string) => apiClient.post(`/plans/${id}/capabilities/${capabilityId}`),
  detachCapability: (id: string, capabilityId: string) => apiClient.delete(`/plans/${id}/capabilities/${capabilityId}`),
  limits: (id: string) => unwrap<PlanLimit[]>(apiClient.get(`/plans/${id}/limits`)),
  addLimit: (id: string, payload: { limit_key: string; limit_value?: string | null; is_unlimited?: boolean; unit?: string | null }) =>
    unwrap<PlanLimit>(apiClient.post(`/plans/${id}/limits`, payload)),
  removeLimit: (id: string, limitId: string) => apiClient.delete(`/plans/${id}/limits/${limitId}`),
};

// ------------------------------------------------------------------ Addons
export const addonsApi = {
  list: (params?: ListParams) => unwrapFull<Addon[]>(apiClient.get('/addons', { params })),
  get: (id: string) => unwrap<Addon>(apiClient.get(`/addons/${id}`)),
  create: (payload: Partial<Addon>) => unwrap<Addon>(apiClient.post('/addons', payload)),
  update: (id: string, payload: Partial<Addon>) => unwrap<Addon>(apiClient.put(`/addons/${id}`, payload)),
  activate: (id: string) => unwrap<Addon>(apiClient.post(`/addons/${id}/activate`)),
  deactivate: (id: string) => unwrap<Addon>(apiClient.post(`/addons/${id}/deactivate`)),
  retire: (id: string) => unwrap<Addon>(apiClient.post(`/addons/${id}/retire`)),
  capabilities: (id: string) => unwrap<Capability[]>(apiClient.get(`/addons/${id}/capabilities`)),
  attachCapability: (id: string, capabilityId: string) => apiClient.post(`/addons/${id}/capabilities/${capabilityId}`),
  detachCapability: (id: string, capabilityId: string) => apiClient.delete(`/addons/${id}/capabilities/${capabilityId}`),
  limits: (id: string) => unwrap<AddonLimit[]>(apiClient.get(`/addons/${id}/limits`)),
  addLimit: (id: string, payload: { limit_key: string; limit_delta: string; unit?: string | null }) =>
    unwrap<AddonLimit>(apiClient.post(`/addons/${id}/limits`, payload)),
};

// ------------------------------------------------------------------ Pricing
export const pricesApi = {
  list: (params?: ListParams) => unwrapFull<Price[]>(apiClient.get('/prices', { params })),
  get: (id: string) => unwrap<Price>(apiClient.get(`/prices/${id}`)),
  create: (payload: Partial<Price>) => unwrap<Price>(apiClient.post('/prices', payload)),
  update: (id: string, payload: { effective_until?: string | null; metadata?: Record<string, unknown> | null }) =>
    unwrap<Price>(apiClient.put(`/prices/${id}`, payload)),
  activate: (id: string) => unwrap<Price>(apiClient.post(`/prices/${id}/activate`)),
  retire: (id: string) => unwrap<Price>(apiClient.post(`/prices/${id}/retire`)),
};

export interface PricingSimulationResult {
  currency: string;
  subtotal: string;
  components: Array<{
    code?: string;
    amount: string;
    quantity?: string;
    included_quantity?: string;
    billable_quantity?: string;
    unit_amount?: string;
    tax?: string;
    [key: string]: unknown;
  }>;
  tax: string;
  total: string;
}

export const pricingApi = {
  simulate: (payload: { plan_id: string; tenant_id?: string; addon_ids?: string[]; quantities?: Record<string, string | number> }) =>
    unwrap<PricingSimulationResult>(apiClient.post('/pricing/simulate', payload)),
  createOverride: (payload: Partial<Price> & { tenant_id: string }) => unwrap<Price>(apiClient.post('/pricing/overrides', payload)),
};

// ------------------------------------------------------------ Subscriptions
export const subscriptionsApi = {
  list: (params?: ListParams) => unwrapFull<Subscription[]>(apiClient.get('/subscriptions', { params })),
  get: (id: string) => unwrap<Subscription>(apiClient.get(`/subscriptions/${id}`)),
  create: (payload: Partial<Subscription> & { customer_id: string; tenant_id: string; plan_id: string; idempotency_key?: string }) =>
    unwrap<Subscription>(apiClient.post('/subscriptions', payload)),
  update: (id: string, payload: { auto_renew?: boolean; metadata?: Record<string, unknown> | null }) =>
    unwrap<Subscription>(apiClient.put(`/subscriptions/${id}`, payload)),
  startTrial: (id: string) => unwrap<Subscription>(apiClient.post(`/subscriptions/${id}/start-trial`)),
  activate: (id: string) => unwrap<Subscription>(apiClient.post(`/subscriptions/${id}/activate`)),
  renew: (id: string) => unwrap<Subscription>(apiClient.post(`/subscriptions/${id}/renew`)),
  cancel: (id: string) => unwrap<Subscription>(apiClient.post(`/subscriptions/${id}/cancel`)),
  suspend: (id: string) => unwrap<Subscription>(apiClient.post(`/subscriptions/${id}/suspend`)),
  reactivate: (id: string) => unwrap<Subscription>(apiClient.post(`/subscriptions/${id}/reactivate`)),
  expire: (id: string) => unwrap<Subscription>(apiClient.post(`/subscriptions/${id}/expire`)),
  terminate: (id: string) => unwrap<Subscription>(apiClient.post(`/subscriptions/${id}/terminate`)),
  upgrade: (id: string, planId: string) => unwrap<Subscription>(apiClient.post(`/subscriptions/${id}/upgrade`, { plan_id: planId })),
  downgrade: (id: string, planId: string) => unwrap<Subscription>(apiClient.post(`/subscriptions/${id}/downgrade`, { plan_id: planId })),
};

// ------------------------------------------------------------ Entitlements
export const entitlementsApi = {
  list: (params?: ListParams) => unwrapFull<Entitlement[]>(apiClient.get('/entitlements', { params })),
  suspend: (id: string) => unwrap<Entitlement>(apiClient.post(`/entitlements/${id}/suspend`)),
  restore: (id: string) => unwrap<Entitlement>(apiClient.post(`/entitlements/${id}/restore`)),
  forTenant: (tenantId: string, params?: ListParams) => unwrapFull<Entitlement[]>(apiClient.get(`/tenants/${tenantId}/entitlements`, { params })),
  effectiveForTenant: (tenantId: string) =>
    unwrap<{ tenant_id: string; entitlements: EffectiveEntitlement[] }>(apiClient.get(`/tenants/${tenantId}/effective-entitlements`)),
  createOverride: (
    tenantId: string,
    payload: {
      entitlement_type: string;
      entitlement_key: string;
      value: unknown;
      application_id?: string;
      capability_id?: string;
      effective_from?: string;
      effective_until?: string;
      reason: string;
    },
  ) => unwrap<Entitlement>(apiClient.post(`/tenants/${tenantId}/entitlement-overrides`, payload)),
};

// ------------------------------------------------------------------- Usage
export const usageApi = {
  list: (params?: ListParams) => unwrapFull<UsageEvent[]>(apiClient.get('/usage', { params })),
  forTenant: (tenantId: string, params?: ListParams) => unwrapFull<UsageEvent[]>(apiClient.get(`/tenants/${tenantId}/usage`, { params })),
};

// ------------------------------------------------------------------ Billing
export interface BillingRunResultRow {
  subscription_id?: string;
  error?: string;
  [key: string]: unknown;
}

export const billingApi = {
  list: (params?: ListParams) => unwrapFull<Billing[]>(apiClient.get('/billings', { params })),
  get: (id: string) => unwrap<Billing>(apiClient.get(`/billings/${id}`)),
  run: (payload: { subscription_id?: string; tenant_id?: string; period_start?: string; period_end?: string }) =>
    unwrap<{ results: Array<Billing | BillingRunResultRow> }>(apiClient.post('/billing-runs', payload)),
  recalculate: (id: string) => unwrap<Billing>(apiClient.post(`/billings/${id}/recalculate`)),
  review: (id: string) => unwrap<Billing>(apiClient.post(`/billings/${id}/review`)),
  finalize: (id: string) => unwrap<Billing>(apiClient.post(`/billings/${id}/finalize`)),
  cancel: (id: string) => unwrap<Billing>(apiClient.post(`/billings/${id}/cancel`)),
  addAdjustment: (id: string, payload: { adjustment_type: string; reason: string; amount: string }) =>
    unwrap<Billing>(apiClient.post(`/billings/${id}/adjustments`, payload)),
};

// ----------------------------------------------------------------- Invoices
export const invoicesApi = {
  list: (params?: ListParams) => unwrapFull<Invoice[]>(apiClient.get('/invoices', { params })),
  get: (id: string) => unwrap<Invoice>(apiClient.get(`/invoices/${id}`)),
  generateFromBilling: (billingId: string, netDays?: number) =>
    unwrap<Invoice>(apiClient.post(`/invoices/generate-from-billing/${billingId}`, netDays ? { net_days: netDays } : undefined)),
  issue: (id: string) => unwrap<Invoice>(apiClient.post(`/invoices/${id}/issue`)),
  void: (id: string) => unwrap<Invoice>(apiClient.post(`/invoices/${id}/void`)),
  cancel: (id: string) => unwrap<Invoice>(apiClient.post(`/invoices/${id}/cancel`)),
  markPaid: (id: string, payload?: { method?: string; reference?: string }) =>
    unwrap<Invoice>(apiClient.post(`/invoices/${id}/mark-paid`, payload)),
  markPartiallyPaid: (id: string, payload: { amount: string; method?: string; reference?: string }) =>
    unwrap<Invoice>(apiClient.post(`/invoices/${id}/mark-partially-paid`, payload)),
};

// ------------------------------------------------------------------ Policies
export const policiesApi = {
  list: (params?: ListParams) => unwrapFull<Policy[]>(apiClient.get('/policies', { params })),
  get: (id: string) => unwrap<Policy>(apiClient.get(`/policies/${id}`)),
  create: (payload: Partial<Policy>) => unwrap<Policy>(apiClient.post('/policies', payload)),
  update: (id: string, payload: Partial<Policy>) => unwrap<Policy>(apiClient.put(`/policies/${id}`, payload)),
  activate: (id: string) => unwrap<Policy>(apiClient.post(`/policies/${id}/activate`)),
  deactivate: (id: string) => unwrap<Policy>(apiClient.post(`/policies/${id}/deactivate`)),
  deprecate: (id: string) => unwrap<Policy>(apiClient.post(`/policies/${id}/deprecate`)),
  clone: (id: string, payload: { policy_code: string; name?: string }) => unwrap<Policy>(apiClient.post(`/policies/${id}/clone`, payload)),
  simulate: (payload: Record<string, unknown>) => unwrap<PolicySimulationResult>(apiClient.post('/policies/simulate', payload)),
};

// ----------------------------------------------------------------- Workflows
export const workflowsApi = {
  list: (params?: ListParams) => unwrapFull<Workflow[]>(apiClient.get('/workflows', { params })),
  get: (id: string) => unwrap<Workflow>(apiClient.get(`/workflows/${id}`)),
  create: (payload: Record<string, unknown>) => unwrap<Workflow>(apiClient.post('/workflows', payload)),
  update: (id: string, payload: Record<string, unknown>) => unwrap<Workflow>(apiClient.put(`/workflows/${id}`, payload)),
  clone: (id: string, payload: { workflow_code: string; name?: string }) => unwrap<Workflow>(apiClient.post(`/workflows/${id}/clone`, payload)),
  validate: (id: string) => unwrap<{ valid: boolean; errors: string[] }>(apiClient.post(`/workflows/${id}/validate`)),
  activate: (id: string) => unwrap<Workflow>(apiClient.post(`/workflows/${id}/activate`)),
  deactivate: (id: string) => unwrap<Workflow>(apiClient.post(`/workflows/${id}/deactivate`)),
  execute: (id: string, payload?: { payload?: Record<string, unknown>; idempotency_key?: string }) =>
    unwrap<WorkflowInstance>(apiClient.post(`/workflows/${id}/execute`, payload)),
};

export const workflowInstancesApi = {
  list: (params?: ListParams) => unwrapFull<WorkflowInstance[]>(apiClient.get('/workflow-instances', { params })),
  get: (id: string) => unwrap<WorkflowInstance>(apiClient.get(`/workflow-instances/${id}`)),
  retry: (id: string) => unwrap<WorkflowInstance>(apiClient.post(`/workflow-instances/${id}/retry`)),
  cancel: (id: string) => unwrap<WorkflowInstance>(apiClient.post(`/workflow-instances/${id}/cancel`)),
};

// ---------------------------------------------------------------- Approvals
export const approvalDefinitionsApi = {
  list: (params?: ListParams) => unwrapFull<ApprovalDefinition[]>(apiClient.get('/approval-definitions', { params })),
  get: (id: string) => unwrap<ApprovalDefinition>(apiClient.get(`/approval-definitions/${id}`)),
  create: (payload: Record<string, unknown>) => unwrap<ApprovalDefinition>(apiClient.post('/approval-definitions', payload)),
  update: (id: string, payload: Record<string, unknown>) => unwrap<ApprovalDefinition>(apiClient.put(`/approval-definitions/${id}`, payload)),
};

export const approvalRequestsApi = {
  list: (params?: ListParams) => unwrapFull<ApprovalRequest[]>(apiClient.get('/approval-requests', { params })),
  get: (id: string) => unwrap<ApprovalRequest>(apiClient.get(`/approval-requests/${id}`)),
  approve: (id: string, comment?: string) => unwrap<ApprovalRequest>(apiClient.post(`/approval-requests/${id}/approve`, { comment })),
  reject: (id: string, comment?: string) => unwrap<ApprovalRequest>(apiClient.post(`/approval-requests/${id}/reject`, { comment })),
  return: (id: string, comment?: string) => unwrap<ApprovalRequest>(apiClient.post(`/approval-requests/${id}/return`, { comment })),
};

export const approvalDelegationsApi = {
  create: (payload: { delegate_user_id: string; approval_definition_id?: string; starts_at?: string; ends_at?: string }) =>
    unwrap<ApprovalDelegation>(apiClient.post('/approval-delegations', payload)),
  revoke: (id: string) => apiClient.delete(`/approval-delegations/${id}`),
};

// -------------------------------------------------------------- Integrations
export const integrationsApi = {
  list: (params?: ListParams) => unwrapFull<Integration[]>(apiClient.get('/integrations', { params })),
  get: (id: string) => unwrap<Integration>(apiClient.get(`/integrations/${id}`)),
  create: (payload: Record<string, unknown>) => unwrap<Integration>(apiClient.post('/integrations', payload)),
  update: (id: string, payload: Record<string, unknown>) => unwrap<Integration>(apiClient.put(`/integrations/${id}`, payload)),
  activate: (id: string) => unwrap<Integration>(apiClient.post(`/integrations/${id}/activate`)),
  deactivate: (id: string) => unwrap<Integration>(apiClient.post(`/integrations/${id}/deactivate`)),
  test: (id: string) => unwrap<{ ok: boolean; status?: number; duration_ms?: number; error?: string }>(apiClient.post(`/integrations/${id}/test`)),
  logs: (id: string, params?: ListParams) => unwrapFull<IntegrationLog[]>(apiClient.get(`/integrations/${id}/logs`, { params })),
  storeCredential: (id: string, payload: { credential_type: string; secret: string; reference_label: string }) =>
    unwrap<IntegrationCredential>(apiClient.post(`/integrations/${id}/credentials`, payload)),
  rotateCredential: (id: string, payload: { credential_type: string; secret: string; reference_label: string }) =>
    unwrap<IntegrationCredential>(apiClient.post(`/integrations/${id}/credentials/rotate`, payload)),
  revokeCredential: (id: string) => unwrap<IntegrationCredential>(apiClient.post(`/integrations/${id}/credentials/revoke`)),
};

// -------------------------------------------------------------------- Events
export const eventCatalogApi = {
  list: (params?: ListParams) => unwrapFull<EventCatalogEntry[]>(apiClient.get('/event-catalog', { params })),
  get: (id: string) => unwrap<EventCatalogEntry>(apiClient.get(`/event-catalog/${id}`)),
  create: (payload: Record<string, unknown>) => unwrap<EventCatalogEntry>(apiClient.post('/event-catalog', payload)),
  update: (id: string, payload: Record<string, unknown>) => unwrap<EventCatalogEntry>(apiClient.put(`/event-catalog/${id}`, payload)),
};

export const eventDeliveriesApi = {
  list: (params?: ListParams) => unwrapFull<EventDelivery[]>(apiClient.get('/event-deliveries', { params })),
  get: (id: string) => unwrap<EventDelivery>(apiClient.get(`/event-deliveries/${id}`)),
  retry: (id: string) => unwrap<EventDelivery>(apiClient.post(`/event-deliveries/${id}/retry`)),
  discard: (id: string) => unwrap<EventDelivery>(apiClient.post(`/event-deliveries/${id}/discard`)),
};

// ------------------------------------------------------------- Feature Flags
export const featureFlagsApi = {
  list: (params?: ListParams) => unwrapFull<FeatureFlag[]>(apiClient.get('/feature-flags', { params })),
  get: (id: string) => unwrap<FeatureFlag>(apiClient.get(`/feature-flags/${id}`)),
  create: (payload: Record<string, unknown>) => unwrap<FeatureFlag>(apiClient.post('/feature-flags', payload)),
  update: (id: string, payload: Record<string, unknown>) => unwrap<FeatureFlag>(apiClient.put(`/feature-flags/${id}`, payload)),
  activate: (id: string) => unwrap<FeatureFlag>(apiClient.post(`/feature-flags/${id}/activate`)),
  deactivate: (id: string) => unwrap<FeatureFlag>(apiClient.post(`/feature-flags/${id}/deactivate`)),
  addOverride: (id: string, payload: Record<string, unknown>) => apiClient.post(`/feature-flags/${id}/overrides`, payload),
  removeOverride: (id: string, overrideId: string) => apiClient.delete(`/feature-flags/${id}/overrides/${overrideId}`),
  evaluate: (payload: { flag_key: string; tenant_id?: string; user_id?: string }) =>
    unwrap<FeatureFlagEvaluation>(apiClient.post('/feature-flags/evaluate', payload)),
};

// ------------------------------------------------------------- Notifications
export const notificationTemplatesApi = {
  list: (params?: ListParams) => unwrapFull<NotificationTemplate[]>(apiClient.get('/notification-templates', { params })),
  get: (id: string) => unwrap<NotificationTemplate>(apiClient.get(`/notification-templates/${id}`)),
  create: (payload: Record<string, unknown>) => unwrap<NotificationTemplate>(apiClient.post('/notification-templates', payload)),
  update: (id: string, payload: Record<string, unknown>) => unwrap<NotificationTemplate>(apiClient.put(`/notification-templates/${id}`, payload)),
};

export const notificationRulesApi = {
  list: (params?: ListParams) => unwrapFull<NotificationRule[]>(apiClient.get('/notification-rules', { params })),
  get: (id: string) => unwrap<NotificationRule>(apiClient.get(`/notification-rules/${id}`)),
  create: (payload: Record<string, unknown>) => unwrap<NotificationRule>(apiClient.post('/notification-rules', payload)),
  update: (id: string, payload: Record<string, unknown>) => unwrap<NotificationRule>(apiClient.put(`/notification-rules/${id}`, payload)),
};

export const notificationsApi = {
  list: (params?: ListParams) => unwrapFull<Notification[]>(apiClient.get('/notifications', { params })),
  get: (id: string) => unwrap<Notification>(apiClient.get(`/notifications/${id}`)),
  send: (payload: Record<string, unknown>) => unwrap<Notification[]>(apiClient.post('/notifications/send', payload)),
  retry: (id: string) => unwrap<Notification>(apiClient.post(`/notifications/${id}/retry`)),
  cancel: (id: string) => unwrap<Notification>(apiClient.post(`/notifications/${id}/cancel`)),
};

// ----------------------------------------------------------------- Access
export const accessApi = {
  evaluate: (payload: Record<string, unknown>) => unwrap<AccessEvaluation>(apiClient.post('/access/evaluate', payload)),
};
