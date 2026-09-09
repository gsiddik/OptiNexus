import { useState } from 'react';
import { applicationsApi } from '../api/endpoints';
import { useApiResource } from '../api/useApi';
import type { Application, Capability } from '../api/types';

function flatten(nodes: Capability[]): Capability[] {
  return nodes.flatMap((n) => [n, ...flatten(n.children ?? [])]);
}

/**
 * Attach-capability picker shared by Product/Plan/Add-on detail pages.
 * Capabilities are scoped per-application (see ApplicationDetailPage's
 * CapabilityTree), so attaching one to a commercial catalog entity is a
 * two-step pick: which of the entity's candidate applications, then which
 * of that application's capabilities.
 */
export function CapabilityPicker({
  applications,
  onAttach,
  disabled,
}: {
  applications: Application[];
  onAttach: (capabilityId: string) => Promise<void>;
  disabled?: boolean;
}) {
  const [applicationId, setApplicationId] = useState('');
  const [capabilityId, setCapabilityId] = useState('');
  const [submitting, setSubmitting] = useState(false);
  const { data: tree } = useApiResource<Capability[]>(
    () => (applicationId ? applicationsApi.capabilityTree(applicationId) : Promise.resolve([])),
    [applicationId],
  );
  const options = flatten(tree ?? []);

  async function attach() {
    if (!capabilityId) return;
    setSubmitting(true);
    try {
      await onAttach(capabilityId);
      setCapabilityId('');
    } finally {
      setSubmitting(false);
    }
  }

  return (
    <div className="toolbar">
      <select
        value={applicationId}
        onChange={(e) => {
          setApplicationId(e.target.value);
          setCapabilityId('');
        }}
      >
        <option value="">Select application...</option>
        {applications.map((a) => (
          <option key={a.id} value={a.id}>{a.name}</option>
        ))}
      </select>
      <select value={capabilityId} onChange={(e) => setCapabilityId(e.target.value)} disabled={!applicationId}>
        <option value="">Select capability...</option>
        {options.map((c) => (
          <option key={c.id} value={c.id}>{c.name} ({c.code})</option>
        ))}
      </select>
      <button className="btn btn-secondary" disabled={disabled || submitting || !capabilityId} onClick={attach}>
        Attach Capability
      </button>
    </div>
  );
}
