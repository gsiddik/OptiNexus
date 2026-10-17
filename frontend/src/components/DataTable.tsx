import type { ReactNode } from 'react';
import { useI18n } from '../i18n';

export interface Column<T> {
  header: string;
  render: (row: T) => ReactNode;
  key: string;
  /** Extra class for the header and cells, e.g. `hide-narrow` to drop a secondary column on small screens. */
  className?: string;
}

interface DataTableProps<T> {
  columns: Column<T>[];
  rows: T[];
  rowKey: (row: T) => string;
  onRowClick?: (row: T) => void;
  emptyMessage?: string;
}

export function DataTable<T>({ columns, rows, rowKey, onRowClick, emptyMessage }: DataTableProps<T>) {
  const { t } = useI18n();
  return (
    <div className="table-wrap">
      <table className="data-table">
        <thead>
          <tr>
            {columns.map((c) => (
              <th key={c.key} className={c.className}>{c.header}</th>
            ))}
          </tr>
        </thead>
        <tbody>
          {rows.length === 0 && (
            <tr>
              <td colSpan={columns.length} className="empty-cell">{emptyMessage ?? t('common.noRecords')}</td>
            </tr>
          )}
          {rows.map((row) => (
            <tr key={rowKey(row)} onClick={onRowClick ? () => onRowClick(row) : undefined} className={onRowClick ? 'clickable' : ''}>
              {columns.map((c) => (
                <td key={c.key} className={c.className}>{c.render(row)}</td>
              ))}
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  );
}
