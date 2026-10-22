import { useState } from 'react';
import { apiClient, unwrapError } from '../api/client';
import { ErrorAlert } from './ErrorAlert';

export function DownloadInvoice({ id, number }: { id: string; number: string }) {
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  async function download() {
    setBusy(true); setError(null);
    try {
      const response = await apiClient.get(`/invoices/${id}/download`, { responseType: 'blob' });
      const url = URL.createObjectURL(response.data);
      const link = document.createElement('a');
      link.href = url; link.download = `${number.replace(/[^A-Za-z0-9_-]/g, '-')}.html`; link.click();
      window.setTimeout(() => URL.revokeObjectURL(url), 1000);
    } catch (err) { setError(unwrapError(err).message); }
    finally { setBusy(false); }
  }
  return <><button className="btn btn-secondary" disabled={busy} onClick={download}>Download Invoice</button><ErrorAlert message={error} /></>;
}
