import { useEffect, useRef, useState } from 'react';
import { Outlet, useLocation, useNavigate, useNavigationType } from 'react-router-dom';
import { useAuth } from '../auth/useAuth';
import { buildTrail, parentOf } from '../navigation/breadcrumbs';
import { BrandLogo } from './BrandLogo';
import { BackButton, Breadcrumbs } from './PageNav';
import { SidebarNav } from './SidebarNav';

/** Sidebar, top bar, breadcrumb and Back around every page. Used as a layout route, so it stays mounted while you move between pages. */
export function Layout() {
  const { context, activeTenantId, setActiveTenantId, logout } = useAuth();
  const location = useLocation();
  const navigate = useNavigate();
  const navigationType = useNavigationType();
  const mainRef = useRef<HTMLElement>(null);

  // The off-canvas menu belongs to the page it was opened on, so navigating closes it without an effect.
  const [openAt, setOpenAt] = useState<string | null>(null);
  const menuOpen = openAt === location.pathname;

  // How many pages of this session lie behind the current one, so "Back" never leaves the console (e.g. back to the sign-in page).
  const [history, setHistory] = useState({ key: location.key, depth: 0 });
  if (history.key !== location.key) {
    const depth = navigationType === 'PUSH' ? history.depth + 1 : navigationType === 'POP' ? Math.max(0, history.depth - 1) : history.depth;
    setHistory({ key: location.key, depth });
  }

  // The title each address showed (its first heading), so the breadcrumb of a details page reads as the record's name.
  const [headings, setHeadings] = useState<Record<string, string>>({});
  useEffect(() => {
    const main = mainRef.current;
    if (!main) return;
    const path = location.pathname;
    const read = () => {
      const text = main.querySelector('h1')?.textContent?.trim();
      if (text) setHeadings((current) => (current[path] === text ? current : { ...current, [path]: text }));
    };
    read();
    const observer = new MutationObserver(read);
    observer.observe(main, { childList: true, subtree: true, characterData: true });
    return () => observer.disconnect();
  }, [location.pathname]);

  const trail = buildTrail(location.pathname, headings);
  const parent = parentOf(trail);
  const goBack = history.depth > 0 ? () => navigate(-1) : parent ? () => navigate(parent) : null;

  return (
    <div className="app-shell">
      <a className="skip-link" href="#main">Skip to content</a>
      <aside className={`sidebar${menuOpen ? ' open' : ''}`} id="sidebar" aria-label="Sidebar">
        <BrandLogo to="/" />
        <SidebarNav />
      </aside>
      <div className={`scrim${menuOpen ? ' open' : ''}`} onClick={() => setOpenAt(null)} aria-hidden="true" />
      <div className="main-column">
        <header className="topbar">
          <button
            type="button"
            className="btn btn-ghost menu-btn"
            aria-label="Open menu"
            aria-expanded={menuOpen}
            aria-controls="sidebar"
            onClick={() => setOpenAt(menuOpen ? null : location.pathname)}
          >
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" aria-hidden="true">
              <path d="M4 7h16M4 12h16M4 17h16" />
            </svg>
          </button>
          <div className="tenant-switcher">
            <label htmlFor="tenant-select">Tenant:</label>
            <select
              id="tenant-select"
              value={activeTenantId ?? ''}
              onChange={(e) => setActiveTenantId(e.target.value || null)}
            >
              <option value="">Global (platform scope)</option>
              {context?.available_tenants.map((t) => (
                <option key={t.id} value={t.id}>
                  {t.name} ({t.tenant_code})
                </option>
              ))}
            </select>
          </div>
          <div className="user-menu">
            <span>{context?.user.name}</span>
            <button className="btn btn-ghost" onClick={logout}>Log out</button>
          </div>
        </header>
        <main className="content" id="main" ref={mainRef}>
          <div className="page-nav">
            {goBack && <BackButton onBack={goBack} />}
            <Breadcrumbs trail={trail} />
          </div>
          <Outlet />
        </main>
      </div>
    </div>
  );
}
