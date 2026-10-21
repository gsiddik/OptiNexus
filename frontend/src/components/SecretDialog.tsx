import { useEffect, useRef, useState, type ReactNode } from 'react';
import { useI18n } from '../i18n';

/** Texts that differ when the secret is not an SSO client secret (e.g. a temporary password); each falls back to the client-secret wording. */
export interface SecretDialogLabels {
  identity: string;
  secret: string;
  once: string;
  copy: string;
  copyFailed: string;
  notCopied: string;
  close: string;
  closeAnyway: string;
}

interface SecretDialogProps {
  title: string;
  clientId: string;
  secret: string;
  onClose: () => void;
  labels?: Partial<SecretDialogLabels>;
  /** Extra facts shown under the secret (e.g. when a temporary password expires). */
  note?: ReactNode;
}

/**
 * Shows a client secret exactly once. The secret lives only in the props of this dialog (the parent clears it on close), is never
 * written to storage, the address bar or the console, and the dialog cannot be dismissed by a stray click outside it. Closing without
 * having copied it asks once more, because there is no way to see the secret again.
 */
export function SecretDialog({ title, clientId, secret, onClose, labels, note }: SecretDialogProps) {
  const { t } = useI18n();
  const text: SecretDialogLabels = {
    identity: t('oidc.secret.clientId'),
    secret: t('oidc.secret.secret'),
    once: t('oidc.secret.once'),
    copy: t('oidc.secret.copy'),
    copyFailed: t('oidc.secret.copyFailed'),
    notCopied: t('oidc.secret.notCopied'),
    close: t('oidc.secret.close'),
    closeAnyway: t('oidc.secret.closeAnyway'),
    ...labels,
  };
  const [copied, setCopied] = useState(false);
  const [copyFailed, setCopyFailed] = useState(false);
  const [confirmClose, setConfirmClose] = useState(false);
  const secretRef = useRef<HTMLElement>(null);
  const closeRef = useRef<HTMLButtonElement>(null);

  useEffect(() => {
    closeRef.current?.focus();
  }, []);

  function legacyCopy(): boolean {
    const previousFocus = document.activeElement as HTMLElement | null;
    const input = document.createElement('textarea');
    input.value = secret;
    input.setAttribute('readonly', '');
    input.style.position = 'fixed';
    input.style.opacity = '0';
    // Keep it inside the dialog for browsers that restrict focus to a modal.
    secretRef.current?.parentElement?.appendChild(input);
    try {
      input.focus();
      input.select();
      input.setSelectionRange(0, secret.length);
      return typeof document.execCommand === 'function' && document.execCommand('copy');
    } catch { return false; }
    finally { input.remove(); previousFocus?.focus(); }
  }

  async function copy() {
    setCopyFailed(false);
    try {
      // HTTP localtest.me may not expose the secure-context Clipboard API.
      if (!navigator.clipboard?.writeText) {
        if (!legacyCopy()) throw new Error('Clipboard unavailable');
      } else {
        try { await navigator.clipboard.writeText(secret); }
        catch { if (!legacyCopy()) throw new Error('Clipboard denied'); }
      }
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
        <p className="alert alert-warning" id="secret-once">{text.once}</p>
        <dl className="definition-list">
          <dt>{text.identity}</dt>
          <dd><code>{clientId}</code></dd>
          <dt>{text.secret}</dt>
          <dd><code className="secret-value" ref={secretRef} data-testid="client-secret">{secret}</code></dd>
        </dl>
        {note}
        {copyFailed && <div className="alert alert-error" role="alert">{text.copyFailed}</div>}
        {confirmClose && !copied && <div className="alert alert-error" role="alert">{text.notCopied}</div>}
        <div className="modal-actions">
          <button type="button" className="btn btn-secondary" onClick={copy}>{copied ? t('common.copied') : text.copy}</button>
          <button type="button" ref={closeRef} className={confirmClose && !copied ? 'btn btn-danger' : 'btn btn-primary'} onClick={requestClose}>
            {confirmClose && !copied ? text.closeAnyway : text.close}
          </button>
        </div>
      </div>
    </div>
  );
}
