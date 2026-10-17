import type { Pagination as PaginationMeta } from '../api/types';
import { useI18n } from '../i18n';

export function Pagination({ meta, onPageChange }: { meta: PaginationMeta | undefined; onPageChange: (page: number) => void }) {
  const { t } = useI18n();
  if (!meta || meta.last_page <= 1) return null;
  return (
    <div className="pagination">
      <button className="btn btn-ghost" disabled={meta.current_page <= 1} onClick={() => onPageChange(meta.current_page - 1)}>
        {t('common.previous')}
      </button>
      <span>{t('common.page', { current: meta.current_page, last: meta.last_page, total: meta.total })}</span>
      <button className="btn btn-ghost" disabled={meta.current_page >= meta.last_page} onClick={() => onPageChange(meta.current_page + 1)}>
        {t('common.next')}
      </button>
    </div>
  );
}
