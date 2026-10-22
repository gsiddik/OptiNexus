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
  provisioning_blocker?: string | null;
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
  application_name?: string;
  module_name?: string;
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
  auto_capability_permissions?: boolean;
  status: 'ACTIVE' | 'DISABLED';
  permissions_count?: number | null;
}

export interface User {
  id: string;
  name: string;
  email: string;
  status: 'INVITED' | 'ACTIVE' | 'SUSPENDED' | 'DISABLED';
  mfa_enabled: boolean;
  generated_password?: string;
  email_verified_at: string | null;
  last_login_at: string | null;
  metadata: Record<string, unknown> | null;
}

/** Where an account created by an operator (first Admin Tenant) stands. `null` for accounts that never went through that flow. */
export type ActivationState = 'PASSWORD_CHANGE_REQUIRED' | 'CREDENTIAL_EXPIRED' | 'EMAIL_VERIFICATION_PENDING' | 'ACTIVATED';

export interface AdminActivation {
  managed: boolean;
  state: ActivationState | null;
  created_at: string | null;
  email: {
    verified: boolean;
    verified_at: string | null;
    method: 'INVITATION' | 'OPERATOR_MANUAL' | null;
    verified_by: { id: string; name: string } | null;
  };
  password: {
    change_required: boolean;
    issued_at: string | null;
    expires_at: string | null;
    expired: boolean;
    locked_seconds: number;
    changed_at: string | null;
  };
  activated_at: string | null;
  next_steps: Array<'verify_email' | 'reissue_credential' | 'hand_over_credential'>;
}

export interface AdminAccount {
  user: { id: string; name: string; email: string; status: User['status']; created_at: string | null };
  membership: { status: string | null; is_tenant_admin: boolean };
  applications: Array<{ id: string; code: string; name: string }>;
  roles: Array<{ id: string; code: string; name: string }>;
  activation: AdminActivation;
}

/** The temporary password as the API returns it exactly once (on create and on reissue); never part of a list or a later read. */
export interface TemporaryCredential {
  password: string;
  expires_at: string | null;
  shown_once: boolean;
}

export type AdminAccountCreated = AdminAccount & {
  /** `created` for a new identity, `linked` for an existing one (which never gets a credential). */
  outcome: 'created' | 'linked';
  temporary_credential: TemporaryCredential | null;
};

export type AdminAccountReissued = AdminAccount & { temporary_credential: TemporaryCredential | null };

export interface AdminAccountCreate {
  user_id?: string;
  confirm_email?: string;
  name?: string;
  email?: string;
  application_ids: string[];
  role_ids?: string[];
}

export interface EffectivePermission {
  application_name?: string;
  module_name?: string;
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
  product_name?: string | null;

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
  product_name?: string | null;

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
  applications?: { id: string; name: string }[];
  plan_name?: string | null;
  addon_name?: string | null;

  id: string; plan_id: string | null; addon_id: string | null; tenant_id: string | null;
  price_type: PriceType; currency: string; unit_amount: string | null; billing_interval: string;
  unit_name: string | null; meter_key: string | null; minimum_quantity: string | null; included_quantity: string | null;
  tax_code_id: string | null; status: 'ACTIVE' | 'RETIRED';
  approval_status: 'DRAFT' | 'PENDING_APPROVAL' | 'APPROVED' | 'REJECTED';
  effective_from: string; effective_until: string | null; created_by: string | null; approved_by: string | null;
  metadata: Record<string, unknown> | null; tiers?: PricingTier[];
}

export interface SubscriptionItem {
  price_type?: PriceType | null;
  total_price?: string | null;
  plan_name?: string | null;
  addon_name?: string | null;

  id: string; subscription_id: string; item_type: 'PLAN' | 'ADDON'; plan_id: string | null; addon_id: string | null;
  price_id: string | null; quantity: string; unit_price: string | null; currency: string;
  start_at: string; end_at: string | null;
}

export interface Subscription {
  customer_name?: string | null;
  tenant_name?: string | null;
  product_name?: string | null;
  plan_name?: string | null;

  id: string; subscription_number: string; customer_id: string; tenant_id: string; product_id: string; plan_id: string;
  status: 'DRAFT' | 'PENDING' | 'TRIAL' | 'ACTIVE' | 'PAST_DUE' | 'GRACE_PERIOD' | 'SUSPENDED' | 'EXPIRED' | 'CANCELLED' | 'TERMINATED';
  start_at: string | null; current_period_start: string | null; current_period_end: string | null;
  grace_period_days?: number; trial_end_at: string | null; grace_end_at: string | null; cancel_at: string | null; cancelled_at: string | null;
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
  invoice?: { id: string; invoice_number: string; status: string } | null;
  can_generate_invoice?: boolean;
  customer_name?: string | null;
  tenant_name?: string | null;
  subscription_number?: string | null;

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
  status?: 'SUBMITTED' | 'VERIFIED' | 'REJECTED'; tenant_id?: string;
  bank_name?: string | null; account_name?: string | null; note?: string | null; verification_note?: string | null;
  proof?: { id: string; original_filename: string; mime_type: string; size: number } | null;
  id: string; invoice_id: string; amount: string; paid_at: string; method: string | null; reference: string | null; recorded_by: string | null;
}

export interface Invoice {
  billing_number?: string | null; customer_name?: string | null; subscription_number?: string | null;
  tenant_name?: string | null;

  id: string; invoice_number: string; billing_id: string; customer_id: string; tenant_id: string; subscription_id: string;
  issue_date: string | null; due_date: string; currency: string;
  subtotal: string; discount_total: string; tax_total: string; adjustment_total: string; total: string; balance_due: string;
  status: 'DRAFT' | 'ISSUED' | 'PARTIALLY_PAID' | 'PAID' | 'OVERDUE' | 'VOID' | 'CANCELLED';
  issued_at: string | null; paid_at: string | null; cancelled_at: string | null; metadata: Record<string, unknown> | null;
  items: InvoiceItem[]; payments: InvoicePayment[]; created_at: string;
}

// ---------------------------------------------------- Phase 3 Orchestration

export interface Policy {
  id: string; policy_code: string; name: string; description: string | null; policy_type: string;
  tenant_id: string | null; application_id: string | null; priority: number; effect: 'ALLOW' | 'DENY' | 'MATCH';
  status: 'DRAFT' | 'ACTIVE' | 'INACTIVE' | 'DEPRECATED'; condition_definition: Record<string, unknown>;
  effective_from: string | null; effective_until: string | null; version: number; created_at: string; updated_at: string;
}

export interface PolicySimulationResult {
  decision: 'ALLOW' | 'DENY' | 'NEUTRAL'; effect: string; reason_code: string;
  matched_policies: { id: string; policy_code: string; effect: string; priority: number; version: number }[];
}

export interface WorkflowStep {
  id: string; step_key: string; step_type: string; name: string | null; config: Record<string, unknown> | null; sort_order: number;
}
export interface WorkflowTransition {
  id: string; from_step_id: string; to_step_id: string; condition: Record<string, unknown> | null; sort_order: number;
}
export interface WorkflowVersion {
  id: string; version: number; status: string; activated_at: string | null;
  steps?: WorkflowStep[]; transitions?: WorkflowTransition[];
}
export interface Workflow {
  id: string; workflow_code: string; name: string; description: string | null; tenant_id: string | null; application_id: string | null;
  status: 'DRAFT' | 'ACTIVE' | 'INACTIVE' | 'DEPRECATED'; current_version: number;
  trigger_type: 'EVENT' | 'API' | 'MANUAL' | 'SCHEDULED'; trigger_event_key: string | null;
  draft_version?: WorkflowVersion | null; created_at: string; updated_at: string;
}
export interface WorkflowInstanceStep {
  id: string; workflow_step_id: string; status: string; attempt_count: number; output: Record<string, unknown> | null;
  last_error_code: string | null; last_error_message: string | null; started_at: string | null; completed_at: string | null;
}
export interface WorkflowInstance {
  id: string; workflow_id: string; workflow_version_id: string; tenant_id: string | null; application_id: string | null;
  status: 'PENDING' | 'RUNNING' | 'WAITING' | 'WAITING_APPROVAL' | 'COMPLETED' | 'FAILED' | 'CANCELLED';
  trigger_type: string; trigger_event_id: string | null; correlation_id: string | null; causation_id: string | null;
  last_error_code: string | null; last_error_message: string | null; started_at: string; completed_at: string | null;
  steps?: WorkflowInstanceStep[];
}

export interface ApprovalLevel {
  id: string; level_order: number; name: string; approver_type: 'USER' | 'ROLE' | 'TENANT_ROLE' | 'APPLICATION_ROLE' | 'DYNAMIC';
  approver_reference: string | null; condition: Record<string, unknown> | null;
}
export interface ApprovalDefinition {
  id: string; definition_code: string; name: string; description: string | null; tenant_id: string | null; application_id: string | null;
  rule_type: 'SEQUENTIAL' | 'ANY_OF' | 'ALL_OF'; self_approval_allowed: boolean; expires_after_hours: number | null;
  status: 'DRAFT' | 'ACTIVE' | 'INACTIVE'; levels?: ApprovalLevel[]; created_at: string;
}
export interface ApprovalDecision {
  id: string; decision: 'APPROVE' | 'REJECT' | 'RETURN'; comment: string | null; decided_by: string | null; decided_at: string;
}
export interface ApprovalRequestStep {
  id: string; level_order: number; status: string; resolved_approver_user_id: string | null; decision?: ApprovalDecision | null;
}
export interface ApprovalRequest {
  id: string; approval_definition_id: string; tenant_id: string | null; application_id: string | null;
  workflow_instance_id: string | null; requested_by: string | null; subject_type: string | null; subject_id: string | null;
  status: 'PENDING' | 'IN_PROGRESS' | 'APPROVED' | 'REJECTED' | 'RETURNED' | 'CANCELLED' | 'EXPIRED';
  current_level: number; context: Record<string, unknown> | null; correlation_id: string | null;
  expires_at: string | null; steps?: ApprovalRequestStep[]; created_at: string;
}
export interface ApprovalDelegation {
  id: string; delegator_user_id: string; delegate_user_id: string; approval_definition_id: string | null;
  status: 'ACTIVE' | 'REVOKED'; starts_at: string | null; ends_at: string | null;
}

export interface IntegrationEndpoint {
  id: string; endpoint_key: string; method: string; path: string;
}
export interface IntegrationCredential {
  id: string; credential_type: string; reference_label: string; status: 'ACTIVE' | 'ROTATED' | 'REVOKED';
  rotated_at: string | null; revoked_at: string | null; created_at: string;
}
export interface IntegrationLog {
  id: string; correlation_id: string | null; direction: 'OUTBOUND' | 'INBOUND'; response_status: number | null;
  duration_ms: number | null; status: 'PENDING' | 'SUCCESS' | 'FAILED' | 'TIMEOUT'; error_code: string | null;
  error_message: string | null; attempt_count: number; created_at: string;
}
export interface Integration {
  id: string; integration_code: string; name: string; tenant_id: string | null; source_application_id: string;
  target_application_id: string | null; integration_type: 'REST' | 'WEBHOOK' | 'EVENT' | 'INTERNAL';
  status: 'DRAFT' | 'ACTIVE' | 'INACTIVE'; base_url: string | null; timeout_seconds: number;
  endpoints?: IntegrationEndpoint[]; has_active_credential?: boolean; created_at: string;
}

export interface EventCatalogEntry {
  id: string; event_key: string; application_id: string | null; name: string; description: string | null;
  schema_version: string; payload_schema: Record<string, unknown> | null; status: 'ACTIVE' | 'DEPRECATED' | 'DISABLED';
}
export interface OrchestrationEvent {
  id: string; event_key: string; event_version: string; occurred_at: string; source_application_id: string | null;
  tenant_id: string | null; correlation_id: string | null; causation_id: string | null; data: Record<string, unknown>;
}
export interface EventDelivery {
  id: string; event_id: string; consumer_type: 'WORKFLOW' | 'NOTIFICATION' | 'WEBHOOK' | 'INTEGRATION';
  consumer_reference: string | null; status: 'PENDING' | 'DELIVERED' | 'FAILED' | 'DISCARDED';
  attempt_count: number; last_error_code: string | null; last_error_message: string | null; created_at: string;
}

export interface FeatureFlagOverride {
  id: string; feature_flag_id: string; scope_type: 'GLOBAL' | 'APPLICATION' | 'TENANT' | 'USER'; scope_id: string | null;
  value: unknown; effective_from: string | null; effective_until: string | null;
}
export interface FeatureFlag {
  id: string; flag_key: string; application_id: string | null; name: string; description: string | null;
  flag_type: 'BOOLEAN' | 'STRING' | 'NUMBER' | 'JSON'; default_value: unknown; status: 'DRAFT' | 'ACTIVE' | 'INACTIVE';
  overrides?: FeatureFlagOverride[]; created_at: string;
}
export interface FeatureFlagEvaluation {
  enabled: boolean; value: unknown; source: string;
}

export interface NotificationTemplate {
  id: string; template_code: string; name: string; channel: 'EMAIL' | 'IN_APP' | 'WEBHOOK';
  subject_template: string | null; body_template: string; language: string; version: number;
  status: 'DRAFT' | 'ACTIVE' | 'INACTIVE'; created_at: string;
}
export interface NotificationRule {
  id: string; rule_code: string; name: string; tenant_id: string | null; application_id: string | null;
  trigger_event_key: string | null; condition: Record<string, unknown> | null;
  recipient_type: string; recipient_reference: string | null; notification_template_id: string; channel: string;
  status: 'ACTIVE' | 'INACTIVE'; created_at: string;
}
export interface NotificationDelivery {
  id: string; attempt_number: number; status: 'SUCCESS' | 'FAILED'; error_message: string | null; created_at: string;
}
export interface Notification {
  id: string; tenant_id: string | null; application_id: string | null; notification_rule_id: string | null;
  notification_template_id: string | null; recipient_user_id: string | null; channel: string;
  subject: string | null; body: string; status: 'QUEUED' | 'PROCESSING' | 'SENT' | 'FAILED' | 'CANCELLED';
  correlation_id: string | null; attempt_count: number; last_error_code: string | null; last_error_message: string | null;
  deliveries?: NotificationDelivery[]; created_at: string;
}

export interface AccessEvaluation {
  allowed: boolean; checks: { entitlement: boolean; permission: boolean; policy: boolean; feature_flag: boolean };
  reason_code: string | null;
}

// ------------------------------------------------------- SSO (OpenID Connect)

export interface OidcClient {
  id: string;
  application_id: string;
  client_id: string;
  name: string;
  confidential: boolean;
  redirect_uris: string[];
  post_logout_redirect_uris: string[];
  launch_url: string | null;
  backchannel_logout_uri: string | null;
  require_pkce: boolean;
  status: 'ACTIVE' | 'REVOKED';
  created_at: string;
}

/** What the create call accepts. `confidential` and `application_id` are fixed once the client exists. */
export interface OidcClientCreate {
  application_id: string;
  name: string;
  confidential: boolean;
  require_pkce: boolean;
  redirect_uris: string[];
  post_logout_redirect_uris: string[];
  launch_url: string | null;
  backchannel_logout_uri: string | null;
}

export type OidcClientUpdate = Omit<OidcClientCreate, 'application_id' | 'confidential'>;

// ------------------------------------------------------- Gateway (vehicle / device mapping)

export type GatewayLinkStatus = 'ACTIVE' | 'UNMATCHED' | 'INACTIVE';

export interface GatewayLink {
  id: string;
  telematics_source: string;
  device_ref: string;
  device_name: string | null;
  device_registration: string | null;
  link_type: 'AUTO' | 'MANUAL';
  status: GatewayLinkStatus;
  fleet_vehicle_id: string | null;
  fleet_vehicle: { external_vehicle_id: string; registration_number: string } | null;
}

export interface GatewayVehicle {
  id: string;
  tenant_id?: string;
  application_id?: string;
  external_vehicle_id: string;
  registration_number: string;
  normalized_registration: string;
  vin: string | null;
  status: string;
}

export interface GatewayRequestLog {
  id: string;
  tenant_id?: string | null;
  application_id: string | null;
  service_account_id: string | null;
  method: string;
  path: string;
  status_code: number;
  duration_ms: number | null;
  correlation_id: string | null;
  created_at: string;
}

// ------------------------------------------------------- Ecosystem onboarding report

export type OnboardingStatus = 'OK' | 'MISSING' | 'WARN';

export interface OnboardingCheck {
  key: string;
  /** Application code the check belongs to; null for a tenant-level check. */
  application: string | null;
  status: OnboardingStatus;
  /** Stable code of the finding; the portal owns the (bilingual) wording. */
  message_code: string;
  detail?: { count?: number } | null;
  action?: { label_key: string; route: string } | null;
}

export interface OnboardingApplication {
  code: string;
  name: string;
  subscribed: boolean;
  oidc_client: { status: string; pkce_required: boolean; redirect_uris: number } | null;
  service_account: { present: boolean; last_used_at: string | null };
  manifest: { revision: string | null; synced_at: string | null };
  users_with_access: number;
  tenant_admins: number;
}

export interface OnboardingReport {
  tenant: { id: string; code: string; name: string; status: string };
  ready: boolean;
  checks: OnboardingCheck[];
  applications: OnboardingApplication[];
}

/** Seats of one application in one tenant, as OptiNexus counts them (GET /tenants/{id}/seats). Available is never negative; over-limit is separate. */
export type SeatState = 'NOT_CONTROLLED' | 'LIMITED' | 'UNLIMITED' | 'ENTITLEMENT_MISSING' | 'ENTITLEMENT_INVALID' | 'ENTITLEMENT_UNAVAILABLE';

export interface SeatStatus {
  application_code: string;
  controlled: boolean;
  state: SeatState;
  limit: number | null;
  unlimited: boolean;
  used: number;
  reserved: number;
  available: number | null;
  over_limit: boolean;
  over_by: number;
  can_add: boolean;
  blocked_reason: string | null;
  limit_source: string | null;
}
