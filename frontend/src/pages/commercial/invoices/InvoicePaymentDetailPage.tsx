import { useEffect, useState } from 'react';
import { Link, useParams } from 'react-router-dom';
import { apiClient, unwrapError } from '../../../api/client';
import { useApiResource } from '../../../api/useApi';
import type { InvoicePayment } from '../../../api/types';
import { LoadingSpinner } from '../../../components/LoadingSpinner';
import { ErrorAlert } from '../../../components/ErrorAlert';
import { StatusBadge } from '../../../components/StatusBadge';
import { PermissionGuard } from '../../../auth/PermissionGuard';

export function InvoicePaymentDetailPage() {
  const { id } = useParams<{ id: string }>();
  const { data: payment, loading, error, reload } = useApiResource<InvoicePayment>(async () => (await apiClient.get(`/invoice-payments/${id}`)).data.data, [id]);
  const [actionError, setActionError] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);
  const [note, setNote] = useState('');
  const [previewUrl, setPreviewUrl] = useState<string | null>(null);
  useEffect(() => () => { if (previewUrl) URL.revokeObjectURL(previewUrl); }, [previewUrl]);
  async function review(action: 'verify' | 'reject') {
    setBusy(true); setActionError(null);
    try { await apiClient.post(`/invoice-payments/${id}/${action}`, { note }); reload(); }
    catch (err) { setActionError(unwrapError(err).message); }
    finally { setBusy(false); }
  }
  async function proof(download: boolean) {
    try {
      const res = await apiClient.get(`/invoice-payments/${id}/proof`, { params: { download }, responseType: 'blob' });
      const url = URL.createObjectURL(new Blob([res.data], { type: payment?.proof?.mime_type }));
      if (download) { const a = document.createElement('a'); a.href = url; a.download = payment?.proof?.original_filename ?? 'proof'; a.click(); }
      else setPreviewUrl(url);
      if (download) window.setTimeout(() => URL.revokeObjectURL(url), 60000);
    } catch (err) { setActionError(unwrapError(err).message); }
  }
  if (loading) return <LoadingSpinner />;
  if (error) return <ErrorAlert message={error} />;
  if (!payment) return null;
  return <div>
    <Link to={`/invoices/${payment.invoice_id}`}>Back to Invoice</Link>
    <h1>Payment Details</h1><StatusBadge status={payment.status ?? 'VERIFIED'} />
    <ErrorAlert message={actionError} />
    <div className="card"><dl className="definition-list">
      <dt>Payment Date</dt><dd>{payment.paid_at}</dd><dt>Amount</dt><dd>{payment.amount}</dd>
      <dt>Payment Method</dt><dd>{payment.method ?? '—'}</dd><dt>Bank Name</dt><dd>{payment.bank_name ?? '—'}</dd>
      <dt>Account Name</dt><dd>{payment.account_name ?? '—'}</dd><dt>Transaction Reference</dt><dd>{payment.reference ?? '—'}</dd>
      <dt>Note</dt><dd>{payment.note ?? '—'}</dd><dt>Review Note</dt><dd>{payment.verification_note ?? '—'}</dd>
      <dt>Proof of Payment</dt><dd>{payment.proof ? <><span>{payment.proof.original_filename}</span> <button onClick={() => void proof(false)}>View</button> <button onClick={() => void proof(true)}>Download</button></> : '—'}</dd>
    </dl></div>
    {previewUrl && <div className="card"><button onClick={() => setPreviewUrl(null)}>Close Preview</button><iframe title="Proof of Payment" src={previewUrl} style={{ width: '100%', height: 600, border: 0 }} /></div>}
    {payment.status === 'SUBMITTED' && <PermissionGuard permission="cgo.invoice.payment.record"><div className="card">
      <label>Review Note<textarea value={note} onChange={(e) => setNote(e.target.value)} maxLength={5000} /></label>
      <button className="btn-primary" disabled={busy} onClick={() => void review('verify')}>Mark Fully Paid</button>
      <button className="btn-danger" disabled={busy} onClick={() => void review('reject')}>Reject</button>
    </div></PermissionGuard>}
  </div>;
}
