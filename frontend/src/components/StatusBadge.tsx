import { hasMessage, useI18n } from '../i18n';

const TONE_BY_STATUS: Record<string, string> = {
  ACTIVE: 'success',
  PUBLISHED: 'success',
  APPROVED: 'success',
  SUSPENDED: 'warning',
  DRAFT: 'neutral',
  PROVISIONING: 'info',
  REVIEW: 'info',
  INVITED: 'info',
  DEPRECATED: 'warning',
  DISABLED: 'danger',
  TERMINATED: 'danger',
  RETIRED: 'danger',
  ARCHIVED: 'neutral',
  REVOKED: 'danger',
  COMPLETED: 'success',
  SENT: 'success',
  DELIVERED: 'success',
  RUNNING: 'info',
  PROCESSING: 'info',
  WAITING: 'info',
  WAITING_APPROVAL: 'info',
  PENDING: 'info',
  QUEUED: 'info',
  IN_PROGRESS: 'info',
  FAILED: 'danger',
  REJECTED: 'danger',
  DISCARDED: 'danger',
  TIMEOUT: 'danger',
  CANCELLED: 'warning',
  RETURNED: 'warning',
  EXPIRED: 'warning',
  INACTIVE: 'neutral',
  ROTATED: 'neutral',
};

export function StatusBadge({ status, tone: forcedTone }: { status: string; tone?: 'success' | 'warning' | 'danger' | 'info' | 'neutral' }) {
  const { translate } = useI18n();
  const tone = forcedTone ?? TONE_BY_STATUS[status] ?? 'neutral';
  const key = `status.${status}`;
  return <span className={`badge badge-${tone}`}>{hasMessage(key) ? translate(key) : status}</span>;
}
