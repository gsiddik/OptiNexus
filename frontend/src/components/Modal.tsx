import { useEffect, type ReactNode } from 'react';
import { useI18n } from '../i18n';

export function Modal({ open, title, onClose, children, dismissible = true }: { open: boolean; title: string; onClose: () => void; children: ReactNode; dismissible?: boolean }) {
  const { t } = useI18n();
  useEffect(() => {
    if (!open || !dismissible) return;
    const onKey = (event: KeyboardEvent) => {
      if (event.key === 'Escape') onClose();
    };
    window.addEventListener('keydown', onKey);
    return () => window.removeEventListener('keydown', onKey);
  }, [open, dismissible, onClose]);

  if (!open) return null;
  return (
    <div className="modal-backdrop" onClick={dismissible ? onClose : undefined}>
      <div className="modal modal-lg" role="dialog" aria-modal="true" aria-label={title} onClick={(e) => e.stopPropagation()}>
        <div className="modal-header">
          <h3>{title}</h3>
          {dismissible && <button className="btn-icon" onClick={onClose} aria-label={t('common.close')}>×</button>}
        </div>
        {children}
      </div>
    </div>
  );
}
