import { Children, createContext, isValidElement, useContext, useId, useRef, useState, type ComponentProps, type ReactElement, type ReactNode } from 'react';
import { createPortal } from 'react-dom';

const Description = createContext<{ descriptionId: string; labelId: string; controlId: string } | null>(null);

function Info({ help, id }: { help: string; id: string }) {
  const anchor = useRef<HTMLButtonElement>(null);
  const [position, setPosition] = useState<{ top: number; left: number } | null>(null);
  function show() {
    const bounds = anchor.current?.getBoundingClientRect();
    if (bounds) setPosition({ top: Math.max(8, Math.min(bounds.bottom + 4, window.innerHeight - 120)), left: Math.max(8, Math.min(bounds.left, window.innerWidth - 296)) });
  }
  return <><button ref={anchor} type="button" className="field-info" aria-label="Field information" data-field-help="true" aria-describedby={id}
    onClick={(event) => { event.preventDefault(); event.stopPropagation(); show(); }}
    onMouseEnter={show} onMouseLeave={() => setPosition(null)} onFocus={show} onBlur={() => setPosition(null)}
    onKeyDown={(event) => { if (event.key === 'Escape') { event.stopPropagation(); setPosition(null); } }}>i</button>
    {createPortal(<span id={id} role="tooltip" className={`field-tooltip${position ? ' field-tooltip-open' : ''}`} style={position ?? undefined}>{help}</span>, document.body)}</>;
}

/** Keeps contextual help beside the label, with a separate keyboard-accessible trigger. */
export function HelpLabel({ children, ...props }: ComponentProps<'label'>) {
  const id = useId();
  const parts = Children.toArray(children);
  const controls = parts.filter((child) => isValidElement<{ help?: string; kind?: string }>(child) && (typeof child.props.help === 'string' || typeof child.props.kind === 'string'));
  const help = controls.map((child) => ((child as ReactElement<{ help?: string; kind?: string }>).props.help ?? `Select an active ${(child as ReactElement<{ kind: string }>).props.kind}.`)).join(' ');
  if (!help) return <label {...props}>{children}</label>;
  const text: ReactNode[] = [];
  const hints: ReactNode[] = [];
  for (const child of parts) {
    if (controls.includes(child)) continue;
    if (isValidElement<{ className?: string }>(child) && /field-(hint|error)/.test(child.props.className ?? '')) hints.push(child);
    else text.push(child);
  }
  const firstControl = controls[0];
  const controlId = props.htmlFor ?? (isValidElement<{ id?: string }>(firstControl) ? firstControl.props.id : undefined) ?? `${id}-control`;
  return <Description.Provider value={{ descriptionId: id, labelId: `${id}-label`, controlId }}><label {...props} htmlFor={controlId}><span className="field-label-heading"><span id={`${id}-label`}>{text}</span><Info help={help} id={id} /></span>{controls}{hints}</label></Description.Provider>;
}

function FieldHelp({ help, compact, children }: { help: string; compact?: boolean; children: (id: string) => ReactElement }) {
  const ownId = useId();
  const parentId = useContext(Description);
  return <span className={`field-control${compact ? ' field-control-compact' : ''}`}>
    {!parentId && <span className="field-label-heading"><Info help={help} id={ownId} /></span>}
    {children(parentId?.descriptionId ?? ownId)}
  </span>;
}
type HelpProps<T> = T & { help: string };
export function HelpInput({ help, ...props }: HelpProps<ComponentProps<'input'>>) {
  const label = useContext(Description);
  return <FieldHelp help={help} compact={props.type === 'checkbox' || props.type === 'radio'}>{(id) => <input {...props} id={props.id ?? label?.controlId} aria-labelledby={props['aria-labelledby'] ?? label?.labelId} aria-describedby={[props['aria-describedby'], id].filter(Boolean).join(' ')} />}</FieldHelp>;
}
export function HelpSelect({ help, ...props }: HelpProps<ComponentProps<'select'>>) {
  const label = useContext(Description);
  return <FieldHelp help={help}>{(id) => <select {...props} id={props.id ?? label?.controlId} aria-labelledby={props['aria-labelledby'] ?? label?.labelId} aria-describedby={[props['aria-describedby'], id].filter(Boolean).join(' ')} />}</FieldHelp>;
}
export function HelpTextarea({ help, ...props }: HelpProps<ComponentProps<'textarea'>>) {
  const label = useContext(Description);
  return <FieldHelp help={help}>{(id) => <textarea {...props} id={props.id ?? label?.controlId} aria-labelledby={props['aria-labelledby'] ?? label?.labelId} aria-describedby={[props['aria-describedby'], id].filter(Boolean).join(' ')} />}</FieldHelp>;
}
