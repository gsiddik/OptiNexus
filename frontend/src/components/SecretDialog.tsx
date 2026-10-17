import { useEffect, useRef, useState } from 'react';
import { useI18n } from '../i18n';

interface SecretDialogProps {
  title: string;
  clientId: string;
  secret: string;
  onClose: () => void;
}

/**
 * Shows a client secret exactly once. The secret lives only in the props of this dialog (the parent clears it on close), is never
 * written to storage, the address bar or the console, and the dialog cannot be dismissed by a stray click outside it. Closing without
 * having copied it asks once more, because there is no way to see the secret again.
 */
export function SecretDialog({ title, clientId, secret, onClose }: SecretDialogProps) {
  const { t } = useI18n();
  const [copied, setCopied] = useState(false);
  const [copyFailed, setCopyFailed] = useState(false);
  const [confirmClose, setConfirmClose] = useState(false);
  const secretRef = useRef<HTMLElement>(null);
  const closeRef = useRef<HTMLButtonElement>(null);

  useEffect(() => {
    closeRef.current?.focus();
  }, []);

  async function copy() {
    setCopyFailed(false);
    try {
      await navigator.clipboard.writeText(secret);
      setCopied(true);
      setConfirmClose(false);
    } catch {
      // No clipboard permission (insecure origin, blocked by the browser): select the text so Ctrl+C works.
      const node = secretRef.current;
      if (node) {
        const range = document.createRange();
        range.selectNodeContents(node);
        const selection = window.getSelection();
        selection?.removeAllRanges();
        selection?.addRange(range);
      }
      setCopyFailed(true);
    }
  }

  function requestClose() {
    if (!copied && !confirmClose) {
      setConfirmClose(true);
      return;
    }
    onClose();
  }

  return (
    <div className="modal-backdrop" role="presentation">
      <div className="modal modal-lg" role="alertdialog" aria-modal="true" aria-labelledby="secret-title" aria-describedby="secret-once" data-testid="secret-dialog">
        <div className="modal-header">
          <h3 id="secret-title">{title}</h3>
        </div>
        <p className="alert alert-warning" id="secret-once">{t('oidc.secret.once')}</p>
        <dl className="definition-list">
          <dt>{t('oidc.secret.clientId')}</dt>
          <dd><code>{clientId}</code></dd>
          <dt>{t('oidc.secret.secret')}</dt>
          <dd><code className="secret-value" ref={secretRef} data-testid="client-secret">{secret}</code></dd>
        </dl>
        {copyFailed && <div className="alert alert-error" role="alert">{t('oidc.secret.copyFailed')}</div>}
        {confirmClose && !copied && <div className="alert alert-error" role="alert">{t('oidc.secret.notCopied')}</div>}
        <div className="modal-actions">
          <button type="button" className="btn btn-secondary" onClick={copy}>{copied ? t('common.copied') : t('oidc.secret.copy')}</button>
          <button type="button" ref={closeRef} className={confirmClose && !copied ? 'btn btn-danger' : 'btn btn-primary'} onClick={requestClose}>
            {confirmClose && !copied ? t('oidc.secret.closeAnyway') : t('oidc.secret.close')}
          </button>
        </div>
      </div>
    </div>
  );
}
