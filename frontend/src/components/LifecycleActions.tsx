import { useState } from 'react';
import { ConfirmDialog } from './ConfirmDialog';
import { unwrapError } from '../api/client';

export interface LifecycleAction {
  key: string;
  label: string;
  allowedFrom: string[];
  action: () => Promise<unknown>;
  permission?: string;
  danger?: boolean;
}

export function LifecycleActions({
  status,
  actions,
  onDone,
  hasPermission,
}: {
  status: string;
  actions: LifecycleAction[];
  onDone: () => void;
  hasPermission: (key: string) => boolean;
}) {
  const [pending, setPending] = useState<LifecycleAction | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);

  const visible = actions.filter((a) => a.allowedFrom.includes(status) && (!a.permission || hasPermission(a.permission)));

  if (!visible.length) return null;

  async function run(action: LifecycleAction) {
    setBusy(true);
    setError(null);
    try {
      await action.action();
      onDone();
    } catch (err) {
      setError(unwrapError(err).message);
    } finally {
      setBusy(false);
      setPending(null);
    }
  }

  return (
    <div className="lifecycle-actions">
      {error && <div className="alert alert-error">{error}</div>}
      {visible.map((a) => (
        <button key={a.key} className={a.danger ? 'btn btn-danger' : 'btn btn-secondary'} disabled={busy} onClick={() => setPending(a)}>
          {a.label}
        </button>
      ))}
      <ConfirmDialog
        open={!!pending}
        title={pending?.label ?? ''}
        message={`Are you sure you want to ${pending?.label.toLowerCase()} this record?`}
        confirmLabel={pending?.label ?? 'Confirm'}
        danger={pending?.danger}
        onConfirm={() => pending && run(pending)}
        onCancel={() => setPending(null)}
      />
    </div>
  );
}
