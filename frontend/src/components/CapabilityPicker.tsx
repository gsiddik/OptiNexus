import { HelpInput, HelpLabel } from './FieldControls';
import { useState } from 'react';
import type { Capability } from '../api/types';

export interface CapabilityGroup { application: { id: string; name: string }; capabilities: Capability[] }
/** Only the capabilities already included in the parent product can be selected. */
export function CapabilityPicker({ groups, attachedIds, onAttach, disabled }: {
  groups: CapabilityGroup[];
  attachedIds: string[];
  onAttach: (id: string) => Promise<void>;
  disabled?: boolean;
}) {
  const [selected, setSelected] = useState<string[]>([]);
  const [busy, setBusy] = useState(false);
  const pending = selected.filter((id) => !attachedIds.includes(id) && groups.some((group) => group.capabilities.some((c) => c.id === id)));
  async function attach() {
    if (!pending.length || busy) return;
    setBusy(true);
    try {
      for (const id of pending) {
        await onAttach(id);
        setSelected((ids) => ids.filter((value) => value !== id));
      }
    } catch { /* The parent displays the API error; retain pending choices for retry. */ } finally { setBusy(false); }
  }
  return <div>
    {groups.map(({ application, capabilities }) => <fieldset key={application.id} disabled={disabled || busy} className="capability-group">
      <legend>{application.name}</legend>
      {capabilities.map((capability) => <HelpLabel key={capability.id} className="checkbox-label">
        <HelpInput type="checkbox" help="Select a product capability to include. Already attached capabilities are checked." checked={attachedIds.includes(capability.id) || pending.includes(capability.id)} disabled={attachedIds.includes(capability.id)}
          onChange={(event) => setSelected((ids) => event.target.checked ? [...ids, capability.id] : ids.filter((id) => id !== capability.id))} />
        {capability.name}
      </HelpLabel>)}
    </fieldset>)}
    {!groups.length && <p className="muted">No capabilities selected in the parent product.</p>}
    <button className="btn btn-secondary" disabled={disabled || busy || !pending.length} onClick={attach}>Attach Capability</button>
  </div>;
}
