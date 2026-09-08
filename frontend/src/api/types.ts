export interface ApiError {
  code: string;
  message: string;
  details?: unknown;
}

export interface Pagination {
  current_page: number;
  per_page: number;
  total: number;
  last_page: number;
}

export interface Customer {
  id: string;
  customer_code: string;
  legal_name: string;
  business_name: string | null;
  tax_id: string | null;
  industry: string | null;
  email: string | null;
  phone: string | null;
  billing_address: Record<string, unknown> | null;
  status: 'ACTIVE' | 'SUSPENDED' | 'TERMINATED' | 'ARCHIVED';
  metadata: Record<string, unknown> | null;
  created_at: string;
  updated_at: string;
}

export interface Tenant {
  id: string;
  tenant_code: string;
  customer_id: string;
  name: string;
  region: string | null;
  timezone: string | null;
  currency: string | null;
  language: string | null;
  status: 'DRAFT' | 'PROVISIONING' | 'ACTIVE' | 'SUSPENDED' | 'TERMINATED' | 'ARCHIVED';
  metadata: Record<string, unknown> | null;
  created_at: string;
  updated_at: string;
}

export interface Application {
  id: string;
  application_code: string;
  name: string;
  description: string | null;
  owner: string | null;
  version: string | null;
  status: 'DRAFT' | 'REVIEW' | 'APPROVED' | 'PUBLISHED' | 'DEPRECATED' | 'RETIRED';
  frontend_url: string | null;
  backend_url: string | null;
  metadata: Record<string, unknown> | null;
}

export type CapabilityType = 'MODULE' | 'MENU' | 'SUBMENU' | 'FEATURE' | 'FUNCTION' | 'ACTION';

export interface Capability {
  id: string;
  application_id: string;
  parent_id: string | null;
  type: CapabilityType;
  code: string;
  name: string;
  description: string | null;
  sort_order: number;
  status: 'ACTIVE' | 'DISABLED' | 'DEPRECATED';
  metadata: Record<string, unknown> | null;
  children: Capability[];
}

export interface Permission {
  id: string;
  application_id: string;
  capability_id: string | null;
  permission_key: string;
  name: string;
  description: string | null;
  status: 'ACTIVE' | 'DEPRECATED' | 'DISABLED';
}

export interface Role {
  id: string;
  tenant_id: string | null;
  application_id: string | null;
  name: string;
  code: string;
  description: string | null;
  role_type: 'SYSTEM' | 'APPLICATION' | 'TENANT';
  is_system: boolean;
  status: 'ACTIVE' | 'DISABLED';
  permissions_count?: number | null;
}

export interface User {
  id: string;
  name: string;
  email: string;
  status: 'INVITED' | 'ACTIVE' | 'SUSPENDED' | 'DISABLED';
  mfa_enabled: boolean;
  email_verified_at: string | null;
  last_login_at: string | null;
  metadata: Record<string, unknown> | null;
}

export interface EffectivePermission {
  permission_key: string;
  scope: 'GLOBAL' | 'CUSTOMER' | 'TENANT' | 'APPLICATION' | 'OWN';
}

export interface AuditLog {
  id: string;
  actor_user_id: string | null;
  actor_identity: string | null;
  tenant_id: string | null;
  customer_id: string | null;
  application_id: string | null;
  action: string;
  resource_type: string | null;
  resource_id: string | null;
  old_value: Record<string, unknown> | null;
  new_value: Record<string, unknown> | null;
  ip_address: string | null;
  request_id: string | null;
  correlation_id: string | null;
  source: string | null;
  metadata: Record<string, unknown> | null;
  created_at: string;
}

export interface ServiceAccount {
  id: string;
  name: string;
  application_id: string | null;
  tenant_id: string | null;
  client_id: string | null;
  status: 'ACTIVE' | 'REVOKED';
  last_used_at: string | null;
  created_at: string;
}

export interface AuthContext {
  user: User;
  active_tenant_id: string | null;
  available_tenants: Tenant[];
  roles: Role[];
  effective_permissions: EffectivePermission[];
}
