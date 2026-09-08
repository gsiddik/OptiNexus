import { apiClient, type ApiEnvelope } from './client';
import type {
  Application,
  AuditLog,
  AuthContext,
  Capability,
  Customer,
  EffectivePermission,
  Permission,
  Role,
  ServiceAccount,
  Tenant,
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
