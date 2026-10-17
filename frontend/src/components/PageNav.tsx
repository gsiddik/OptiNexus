import { Link } from 'react-router-dom';
import { useI18n } from '../i18n';
import type { Crumb } from '../navigation/breadcrumbs';

/** "Back": the previous page of this session, or the parent page when there is none (see Layout). */
export function BackButton({ onBack }: { onBack: () => void }) {
  const { t } = useI18n();
  return (
    <button type="button" className="btn btn-secondary btn-sm back-btn" onClick={onBack} aria-label={t('common.backTitle')}>
      <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true">
        <path d="M19 12H5M11 6l-6 6 6 6" />
      </svg>
      <span>{t('common.back')}</span>
    </button>
  );
}

/** Where the current page sits: every step but the last is a link, the last is the current page. */
export function Breadcrumbs({ trail }: { trail: Crumb[] }) {
  const { t, translate } = useI18n();
  return (
    <nav aria-label={t('shell.breadcrumb')} className="breadcrumbs">
      <ol>
        {trail.map((crumb, index) => {
          const current = index === trail.length - 1;
          const label = crumb.labelKey ? translate(crumb.labelKey) : crumb.label;
          return (
            <li key={`${index}-${crumb.label}`}>
              {crumb.to && !current ? <Link to={crumb.to}>{label}</Link> : <span aria-current={current ? 'page' : undefined}>{label}</span>}
            </li>
          );
        })}
      </ol>
    </nav>
  );
}
