import type { Pagination as PaginationMeta } from '../api/types';

export function Pagination({ meta, onPageChange }: { meta: PaginationMeta | undefined; onPageChange: (page: number) => void }) {
  if (!meta || meta.last_page <= 1) return null;
  return (
    <div className="pagination">
      <button className="btn btn-ghost" disabled={meta.current_page <= 1} onClick={() => onPageChange(meta.current_page - 1)}>
        Previous
      </button>
      <span>
        Page {meta.current_page} of {meta.last_page} ({meta.total} total)
      </span>
      <button className="btn btn-ghost" disabled={meta.current_page >= meta.last_page} onClick={() => onPageChange(meta.current_page + 1)}>
        Next
      </button>
    </div>
  );
}
