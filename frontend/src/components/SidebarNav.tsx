import { useEffect, useId, useState } from 'react';
import { matchPath, NavLink, useLocation } from 'react-router-dom';
import { NAV_GROUPS } from '../navigation/menu';

const STORAGE_KEY = 'optinexus.sidebar.collapsed';

function readCollapsed(): Set<string> {
  try {
    const raw = window.localStorage.getItem(STORAGE_KEY);
    const parsed: unknown = raw ? JSON.parse(raw) : [];
    return new Set(Array.isArray(parsed) ? parsed.filter((g): g is string => typeof g === 'string') : []);
  } catch {
    return new Set();
  }
}

function writeCollapsed(collapsed: Set<string>) {
  try {
    window.localStorage.setItem(STORAGE_KEY, JSON.stringify([...collapsed]));
  } catch {
    // Private mode or blocked storage: the menu still works, it just does not remember.
  }
}

function Chevron() {
  return (
    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true">
      <path d="m6 9 6 6 6-6" />
    </svg>
  );
}

/**
 * The sidebar menu. Each group header expands and collapses its links (a button with `aria-expanded`); the choice is remembered.
 * The group that holds the current page is always opened when you arrive on it, so the active link is never hidden.
 */
export function SidebarNav() {
  const { pathname } = useLocation();
  const idBase = useId();
  const headed = NAV_GROUPS.flatMap((g) => (g.label ? [g.label] : []));
  const activeGroup = NAV_GROUPS.find((g) => g.label && g.items.some((i) => matchPath({ path: i.to, end: i.end ?? false }, pathname)))?.label ?? undefined;

  const [collapsed, setCollapsed] = useState(() => {
    const stored = readCollapsed();
    if (activeGroup) stored.delete(activeGroup);
    return stored;
  });
  // Arriving in another group (link, breadcrumb, Back) opens it; adjusted while rendering so no stale frame is shown.
  const [seenGroup, setSeenGroup] = useState(activeGroup);
  if (seenGroup !== activeGroup) {
    setSeenGroup(activeGroup);
    if (activeGroup && collapsed.has(activeGroup)) {
      const next = new Set(collapsed);
      next.delete(activeGroup);
      setCollapsed(next);
    }
  }
  useEffect(() => writeCollapsed(collapsed), [collapsed]);

  const toggle = (group: string) => {
    const next = new Set(collapsed);
    if (!next.delete(group)) next.add(group);
    setCollapsed(next);
  };
  const allCollapsed = headed.every((g) => collapsed.has(g));

  return (
    <nav aria-label="Main menu" className="nav">
      <button type="button" className="nav-toggle-all" onClick={() => setCollapsed(allCollapsed ? new Set() : new Set(headed))}>
        {allCollapsed ? 'Expand all' : 'Collapse all'}
      </button>
      {NAV_GROUPS.map((group, index) => {
        const links = group.items.map((item) => (
          <NavLink key={item.to} to={item.to} end={item.end} className={({ isActive }) => `nav-link${isActive ? ' active' : ''}`}>
            {item.label}
          </NavLink>
        ));
        if (!group.label) return <div key={`section-${index}`} className="nav-section">{links}</div>;

        const label = group.label;
        const open = !collapsed.has(label);
        const panel = `${idBase}-group-${index}`;
        return (
          <div key={label} className="nav-section">
            <div className="nav-group">
              <button
                type="button"
                className={`nav-group-toggle${!open && label === activeGroup ? ' has-active' : ''}`}
                aria-expanded={open}
                aria-controls={panel}
                onClick={() => toggle(label)}
              >
                <span>{label}</span>
                <Chevron />
              </button>
            </div>
            <div id={panel} className="nav-items" hidden={!open}>
              {links}
            </div>
          </div>
        );
      })}
    </nav>
  );
}
