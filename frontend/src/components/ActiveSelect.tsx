import type { ComponentProps } from 'react';
import { formOptionsApi } from '../api/endpoints';
import { useApiResource } from '../api/useApi';
import { HelpSelect } from './FieldControls';
import { ErrorAlert } from './ErrorAlert';

type Kind = 'plan' | 'tenant' | 'addon';
const loaders = { plan: formOptionsApi.plans, tenant: formOptionsApi.tenants, addon: formOptionsApi.addons };
/** Fully paginated active choices. Loading or failure never leaves an editable stale option. */
export function ActiveSelect({ kind, help, ...props }: ComponentProps<'select'> & { kind: Kind; help?: string }) {
  const resource = useApiResource<{ id: string; name: string; status: string }[]>(loaders[kind], [kind]);
  return <><HelpSelect {...props} help={help ?? `Select an active ${kind}.`} disabled={props.disabled || resource.loading || !!resource.error}>
    {!props.multiple && <option value="">Select a {kind}...</option>}
    {(resource.data ?? []).filter((row) => row.status === 'ACTIVE').map((row) => <option key={row.id} value={row.id}>{row.name}</option>)}
  </HelpSelect><ErrorAlert message={resource.error} /></>;
}
