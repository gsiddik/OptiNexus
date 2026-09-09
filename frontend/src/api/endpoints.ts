import { apiClient, type ApiEnvelope } from './client';
import type {
  Addon,
  AddonLimit,
  Application,
  AuditLog,
  AuthContext,
  Billing,
  Capability,
  Customer,
  EffectiveEntitlement,
  EffectivePermission,
  Entitlement,
  Invoice,
  Permission,
  Plan,
  PlanLimit,
  Price,
  Product,
  Role,
  ServiceAccount,
  Subscription,
  Tenant,
  UsageEvent,
  User,
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
