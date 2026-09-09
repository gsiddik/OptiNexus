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

// ------------------------------------------------------- Phase 2: Commercial

export interface Product {
  id: string; product_code: string; name: string; description: string | null;
  status: 'DRAFT' | 'ACTIVE' | 'INACTIVE' | 'RETIRED';
  currency: string | null; metadata: Record<string, unknown> | null;
  created_at: string; updated_at: string;
}

export interface PlanLimit {
  id: string; plan_id: string; limit_key: string; limit_value: string | null;
  is_unlimited: boolean; unit: string | null;
}

export interface Plan {
  id: string; product_id: string; plan_code: string; name: string; description: string | null;
  billing_interval: 'MONTHLY' | 'QUARTERLY' | 'SEMI_ANNUAL' | 'ANNUAL' | 'CUSTOM';
  status: 'DRAFT' | 'ACTIVE' | 'INACTIVE' | 'RETIRED';
  trial_days: number | null; currency: string; metadata: Record<string, unknown> | null;
  capabilities_count?: number | null;
  created_at: string; updated_at: string;
}

export interface AddonLimit {
  id: string; addon_id: string; limit_key: string; limit_delta: string; unit: string | null;
}

export interface Addon {
  id: string; product_id: string | null; addon_code: string; name: string; description: string | null;
  status: 'DRAFT' | 'ACTIVE' | 'INACTIVE' | 'RETIRED';
  metadata: Record<string, unknown> | null; created_at: string; updated_at: string;
}

export interface TaxCode {
  id: string; tax_code: string; label: string; rate: string;
  status: 'ACTIVE' | 'INACTIVE'; effective_from: string; effective_until: string | null;
}

export type PriceType = 'FLAT' | 'PER_USER' | 'PER_DEVICE' | 'PER_VEHICLE' | 'PER_TRANSACTION' | 'PER_API_CALL' | 'USAGE' | 'TIERED';

export interface PricingTier {
  id: string; price_id: string; tier_order: number; from_quantity: string; to_quantity: string | null;
  unit_amount: string; flat_amount: string | null;
}

export interface Price {
  id: string; plan_id: string | null; addon_id: string | null; tenant_id: string | null;
  price_type: PriceType; currency: string; unit_amount: string | null; billing_interval: string;
  unit_name: string | null; meter_key: string | null; minimum_quantity: string | null; included_quantity: string | null;
  tax_code_id: string | null; status: 'ACTIVE' | 'RETIRED';
  approval_status: 'DRAFT' | 'PENDING_APPROVAL' | 'APPROVED' | 'REJECTED';
  effective_from: string; effective_until: string | null; created_by: string | null; approved_by: string | null;
  metadata: Record<string, unknown> | null; tiers?: PricingTier[];
}

export interface SubscriptionItem {
  id: string; subscription_id: string; item_type: 'PLAN' | 'ADDON'; plan_id: string | null; addon_id: string | null;
  price_id: string | null; quantity: string; unit_price: string | null; currency: string;
  start_at: string; end_at: string | null;
}

export interface Subscription {
  id: string; subscription_number: string; customer_id: string; tenant_id: string; product_id: string; plan_id: string;
  status: 'DRAFT' | 'PENDING' | 'TRIAL' | 'ACTIVE' | 'PAST_DUE' | 'GRACE_PERIOD' | 'SUSPENDED' | 'EXPIRED' | 'CANCELLED' | 'TERMINATED';
  start_at: string | null; current_period_start: string | null; current_period_end: string | null;
  trial_end_at: string | null; grace_end_at: string | null; cancel_at: string | null; cancelled_at: string | null;
  currency: string; billing_interval: string; auto_renew: boolean; metadata: Record<string, unknown> | null;
  items: SubscriptionItem[]; created_at: string; updated_at: string;
}

export interface Entitlement {
  id: string; tenant_id: string; subscription_id: string | null; application_id: string | null; capability_id: string | null;
  entitlement_type: 'APPLICATION' | 'CAPABILITY' | 'LIMIT'; entitlement_key: string; value: unknown;
  status: 'ACTIVE' | 'SUSPENDED' | 'EXPIRED' | 'REVOKED';
  source_type: 'PLAN' | 'ADDON' | 'MANUAL_OVERRIDE' | 'SYSTEM'; source_reference_id: string | null;
  effective_from: string; effective_until: string | null; reason: string | null; created_by: string | null; created_at: string;
}

export interface EffectiveEntitlement {
  entitlement_type: string; entitlement_key: string; value: unknown; source: string;
}

export interface UsageEvent {
  id: string; tenant_id: string; application_id: string; subscription_id: string | null; meter_key: string; quantity: string;
  usage_timestamp: string; period_start: string; period_end: string; source: string | null;
  external_reference: string | null; idempotency_key: string | null; metadata: Record<string, unknown> | null; created_at: string;
}

export interface BillingItem {
  id: string; billing_id: string; subscription_item_id: string | null; charge_type: string; description: string;
  quantity: string; unit_amount: string; subtotal: string; discount_amount: string; tax_amount: string; total: string;
  pricing_snapshot: Record<string, unknown> | null;
}

export interface BillingAdjustment {
  id: string; billing_id: string; adjustment_type: 'DISCOUNT' | 'CREDIT' | 'DEBIT' | 'CORRECTION' | 'ROUNDING';
  reason: string; amount: string; status: string; created_by: string | null;
}

export interface Billing {
  id: string; billing_number: string; customer_id: string; tenant_id: string; subscription_id: string;
  period_start: string; period_end: string; currency: string;
  subtotal: string; discount_total: string; tax_total: string; adjustment_total: string; total: string;
  status: 'DRAFT' | 'CALCULATED' | 'REVIEWED' | 'FINALIZED' | 'CANCELLED';
  calculated_at: string | null; finalized_at: string | null; metadata: Record<string, unknown> | null;
  items: BillingItem[]; adjustments: BillingAdjustment[]; created_at: string;
}

export interface InvoiceItem {
  id: string; invoice_id: string; description: string; quantity: string; unit_amount: string;
  subtotal: string; discount_amount: string; tax_amount: string; total: string; source_billing_item_id: string | null;
}

export interface InvoicePayment {
  id: string; invoice_id: string; amount: string; paid_at: string; method: string | null; reference: string | null; recorded_by: string | null;
}

export interface Invoice {
  id: string; invoice_number: string; billing_id: string; customer_id: string; tenant_id: string; subscription_id: string;
  issue_date: string | null; due_date: string; currency: string;
  subtotal: string; discount_total: string; tax_total: string; adjustment_total: string; total: string; balance_due: string;
  status: 'DRAFT' | 'ISSUED' | 'PARTIALLY_PAID' | 'PAID' | 'OVERDUE' | 'VOID' | 'CANCELLED';
  issued_at: string | null; paid_at: string | null; cancelled_at: string | null; metadata: Record<string, unknown> | null;
  items: InvoiceItem[]; payments: InvoicePayment[]; created_at: string;
}
