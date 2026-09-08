import { useState } from 'react';
import type { Capability } from '../api/types';
import { StatusBadge } from './StatusBadge';

export function CapabilityTree({ nodes, onSelect, selectedId }: { nodes: Capability[]; onSelect: (c: Capability) => void; selectedId?: string }) {
  if (!nodes.length) return <p className="muted">No capabilities defined yet.</p>;
  return (
    <ul className="capability-tree">
      {nodes.map((node) => (
        <CapabilityNode key={node.id} node={node} onSelect={onSelect} selectedId={selectedId} />
      ))}
    </ul>
  );
}

function CapabilityNode({ node, onSelect, selectedId }: { node: Capability; onSelect: (c: Capability) => void; selectedId?: string }) {
  const [expanded, setExpanded] = useState(true);
  const hasChildren = node.children?.length > 0;

  return (
    <li>
      <div className={`capability-row${selectedId === node.id ? ' selected' : ''}`}>
        {hasChildren ? (
          <button className="tree-toggle" onClick={() => setExpanded((v) => !v)} aria-label="Toggle">
            {expanded ? '▾' : '▸'}
          </button>
        ) : (
          <span className="tree-toggle-spacer" />
        )}
        <button className="capability-label" onClick={() => onSelect(node)}>
          <span className="capability-type">{node.type}</span>
          {node.name}
        </button>
        <StatusBadge status={node.status} />
      </div>
      {hasChildren && expanded && (
        <ul>
          {node.children.map((child) => (
            <CapabilityNode key={child.id} node={child} onSelect={onSelect} selectedId={selectedId} />
          ))}
        </ul>
      )}
    </li>
  );
}
