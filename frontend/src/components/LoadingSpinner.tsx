import { useI18n } from '../i18n';

export function LoadingSpinner({ label }: { label?: string }) {
  const { t } = useI18n();
  return (
    <div className="loading-spinner" role="status" aria-live="polite">
      <div className="spinner" aria-hidden="true" />
      <span>{label ?? t('common.loading')}</span>
    </div>
  );
}
