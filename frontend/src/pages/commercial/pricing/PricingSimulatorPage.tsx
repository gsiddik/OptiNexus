import { useState } from 'react';
import { pricingApi, type PricingSimulationResult } from '../../../api/endpoints';
import { ErrorAlert } from '../../../components/ErrorAlert';
import { LoadingSpinner } from '../../../components/LoadingSpinner';
import { unwrapError } from '../../../api/client';

export function PricingSimulatorPage() {
  const [planId, setPlanId] = useState('');
  const [tenantId, setTenantId] = useState('');
  const [addonIds, setAddonIds] = useState('');
  const [quantitiesJson, setQuantitiesJson] = useState('{}');
  const [result, setResult] = useState<PricingSimulationResult | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [loading, setLoading] = useState(false);

  async function simulate() {
    setError(null);
    setResult(null);
    let quantities: Record<string, string | number> | undefined;
    try {
      quantities = quantitiesJson.trim() ? JSON.parse(quantitiesJson) : undefined;
    } catch {
      setError('Quantities must be valid JSON, e.g. {"api_calls": 1000}');
      return;
    }
    setLoading(true);
    try {
      const res = await pricingApi.simulate({
        plan_id: planId,
        tenant_id: tenantId || undefined,
        addon_ids: addonIds
          ? addonIds.split(',').map((s) => s.trim()).filter(Boolean)
          : undefined,
        quantities,
      });
      setResult(res);
    } catch (err) {
      setError(unwrapError(err).message);
    } finally {
      setLoading(false);
    }
  }

  return (
    <div>
      <div className="page-header">
        <h1>Pricing Simulator</h1>
      </div>
      <p className="muted">
        Estimate a subscription's charge for a plan (with optional add-ons and usage quantities). This is a
        read-only calculation — it never creates a billing record or any other persisted data.
      </p>

      <div className="card">
        <div className="form">
          <ErrorAlert message={error} />
          <label>Plan ID<input value={planId} onChange={(e) => setPlanId(e.target.value)} /></label>
          <label>Tenant ID (optional, applies tenant-specific overrides)<input value={tenantId} onChange={(e) => setTenantId(e.target.value)} /></label>
          <label>Addon IDs (comma-separated, optional)<input value={addonIds} onChange={(e) => setAddonIds(e.target.value)} /></label>
          <label>
            Quantities (JSON, meter_key -&gt; quantity)
            <textarea rows={4} value={quantitiesJson} onChange={(e) => setQuantitiesJson(e.target.value)} placeholder='{"api_calls": 1000}' />
          </label>
          <div className="modal-actions">
            <button className="btn btn-primary" onClick={simulate} disabled={loading || !planId}>Simulate</button>
          </div>
        </div>
      </div>

      {loading && <LoadingSpinner />}

      {result && (
        <div className="card" style={{ marginTop: '1rem' }}>
          <h3>Simulation Result</h3>
          <table className="data-table">
            <thead>
              <tr>
                <th>Code</th><th>Quantity</th><th>Included</th><th>Billable</th><th>Unit Amount</th><th>Tax</th><th>Amount</th>
              </tr>
            </thead>
            <tbody>
              {result.components.length === 0 && (
                <tr><td colSpan={7} className="empty-cell">No components returned.</td></tr>
              )}
              {result.components.map((c, i) => (
                <tr key={i}>
                  <td>{c.code ?? '—'}</td>
                  <td>{c.quantity ?? '—'}</td>
                  <td>{c.included_quantity ?? '—'}</td>
                  <td>{c.billable_quantity ?? '—'}</td>
                  <td>{c.unit_amount ?? '—'}</td>
                  <td>{c.tax ?? '—'}</td>
                  <td>{c.amount}</td>
                </tr>
              ))}
            </tbody>
          </table>
          <dl className="definition-list" style={{ marginTop: '1rem' }}>
            <dt>Currency</dt><dd>{result.currency}</dd>
            <dt>Subtotal</dt><dd>{result.subtotal}</dd>
            <dt>Tax</dt><dd>{result.tax}</dd>
            <dt>Total</dt><dd><strong>{result.total}</strong></dd>
          </dl>
        </div>
      )}
    </div>
  );
}
