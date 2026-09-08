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
};

export function StatusBadge({ status }: { status: string }) {
  const tone = TONE_BY_STATUS[status] ?? 'neutral';
  return <span className={`badge badge-${tone}`}>{status}</span>;
}
