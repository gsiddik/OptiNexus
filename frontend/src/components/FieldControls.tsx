import { useId, useRef, useState, type ComponentProps, type ReactElement } from 'react';
import { createPortal } from 'react-dom';

/** Shared field help remains available on hover, keyboard focus, and to screen readers. */
function FieldHelp({ help, compact, children }: { help: string; compact?: boolean; children: (descriptionId: string) => ReactElement }) {
  const id = useId();
  const anchor = useRef<HTMLSpanElement>(null);
  const [position, setPosition] = useState<{ top: number; left: number } | null>(null);
  function show() {
    const bounds = anchor.current?.getBoundingClientRect();
    if (bounds) setPosition({ top: Math.max(8, Math.min(bounds.bottom + 4, window.innerHeight - 120)), left: Math.max(8, Math.min(bounds.left, window.innerWidth - 296)) });
  }
  function leave() {
    if (!anchor.current?.contains(document.activeElement)) setPosition(null);
  }
  return (
    <span ref={anchor} className={`field-control${compact ? ' field-control-compact' : ''}`}
      onMouseEnter={show} onFocus={show} onMouseLeave={leave} onBlur={() => setPosition(null)}
      onKeyDown={(event) => { if (event.key === 'Escape') { event.stopPropagation(); setPosition(null); } }}>
      {children(id)}
      {createPortal(<span id={id} role="tooltip" className={`field-tooltip${position ? ' field-tooltip-open' : ''}`} style={position ?? undefined}>{help}</span>, document.body)}
    </span>
  );
}

type HelpProps<T> = T & { help: string };

export function HelpInput({ help, ...props }: HelpProps<ComponentProps<'input'>>) {
  return <FieldHelp help={help} compact={props.type === 'checkbox' || props.type === 'radio'}>{(id) => (
    <input {...props} aria-describedby={[props['aria-describedby'], id].filter(Boolean).join(' ')} />
  )}</FieldHelp>;
}

export function HelpSelect({ help, ...props }: HelpProps<ComponentProps<'select'>>) {
  return <FieldHelp help={help}>{(id) => (
    <select {...props} aria-describedby={[props['aria-describedby'], id].filter(Boolean).join(' ')} />
  )}</FieldHelp>;
}

export function HelpTextarea({ help, ...props }: HelpProps<ComponentProps<'textarea'>>) {
  return <FieldHelp help={help}>{(id) => (
    <textarea {...props} aria-describedby={[props['aria-describedby'], id].filter(Boolean).join(' ')} />
  )}</FieldHelp>;
}
