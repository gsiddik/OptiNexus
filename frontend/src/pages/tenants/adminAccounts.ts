import { describeError, unwrapError } from '../../api/client';
import type { AdminAccount, Role } from '../../api/types';
import { hasMessage, translate } from '../../i18n';

/** Shortest and longest reason the API accepts for a manual e-mail verification (config/activation.php). */
export const REASON_MIN = 10;
export const REASON_MAX = 500;

/** The single label an operator reads for where an account stands. The e-mail and password columns show the two facts behind it. */
export type AdminStage = 'regular' | 'created' | 'verified' | 'expired' | 'emailPending' | 'active';

export function stageOf(account: AdminAccount): AdminStage {
  const { activation } = account;
  if (!activation.managed) return 'regular';
  switch (activation.state) {
    case 'ACTIVATED':
      return 'active';
    case 'CREDENTIAL_EXPIRED':
      return 'expired';
    case 'EMAIL_VERIFICATION_PENDING':
      return 'emailPending';
    default:
      return activation.email.verified ? 'verified' : 'created';
  }
}

export const STAGE_TONE: Record<AdminStage, 'success' | 'warning' | 'danger' | 'info' | 'neutral'> = {
  regular: 'neutral',
  created: 'info',
  verified: 'info',
  expired: 'danger',
  emailPending: 'warning',
  active: 'success',
};

/** The API's English message is replaced by the screen's language when the code is one this screen knows. */
export function adminErrorText(err: unknown): string {
  const failure = unwrapError(err);
  const key = `adminAcct.error.${failure.code}`;
  return hasMessage(key) ? translate(key) : describeError(failure);
}

/** Roles an operator can pick for the chosen applications: this tenant's own roles and the applications' roles (never platform roles). */
export function assignableRoles(roles: Role[], tenantId: string, applicationIds: string[]): Role[] {
  return roles.filter((role) => {
    if (role.status !== 'ACTIVE') return false;
    if (role.role_type === 'TENANT') return role.tenant_id === tenantId;
    if (role.role_type === 'APPLICATION') return !!role.application_id && applicationIds.includes(role.application_id) && (role.tenant_id === null || role.tenant_id === tenantId);
    return false;
  });
}

export function emailsMatch(a: string, b: string): boolean {
  return a.trim().toLowerCase() === b.trim().toLowerCase();
}

export function reasonProblem(reason: string): 'short' | null {
  return reason.trim().length < REASON_MIN ? 'short' : null;
}
