import { HelpLabel, HelpSelect } from './FieldControls';
import { Suspense, useEffect, useRef, useState } from 'react';
import { Outlet, useLocation, useNavigate, useNavigationType } from 'react-router-dom';
import { useAuth } from '../auth/useAuth';
import { useI18n } from '../i18n';
import { buildTrail, parentOf } from '../navigation/breadcrumbs';
import { BrandLogo } from './BrandLogo';
import { ErrorBoundary } from './ErrorBoundary';
import { LanguageSwitcher } from './LanguageSwitcher';
import { LoadingSpinner } from './LoadingSpinner';
import { BackButton, Breadcrumbs } from './PageNav';
import { SidebarNav } from './SidebarNav';
import { OfflineBanner } from './StateViews';

/** Sidebar, top bar, breadcrumb and Back around every page. Used as a layout route, so it stays mounted while you move between pages. */
export function Layout() {
  const { context, activeTenantId, setActiveTenantId, logout } = useAuth();
  const { t } = useI18n();
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
      <a className="skip-link" href="#main">{t('shell.skip')}</a>
      <aside className={`sidebar${menuOpen ? ' open' : ''}`} id="sidebar" aria-label={t('shell.sidebar')}>
        <BrandLogo to="/" />
        <SidebarNav />
      </aside>
      <div className={`scrim${menuOpen ? ' open' : ''}`} onClick={() => setOpenAt(null)} aria-hidden="true" />
      <div className="main-column">
        <header className="topbar">
          <button
            type="button"
            className="btn btn-ghost menu-btn"
            aria-label={t('shell.openMenu')}
            aria-expanded={menuOpen}
            aria-controls="sidebar"
            onClick={() => setOpenAt(menuOpen ? null : location.pathname)}
          >
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" aria-hidden="true">
              <path d="M4 7h16M4 12h16M4 17h16" />
            </svg>
          </button>
          <div className="tenant-switcher">
            <HelpLabel htmlFor="tenant-select">{t('shell.scope')}</HelpLabel>
            <HelpSelect help="Select the tenant context for tenant-scoped pages and operations."
              id="tenant-select"
              value={activeTenantId ?? ''}
              onChange={(e) => setActiveTenantId(e.target.value || null)}
            >
              <option value="">{t('shell.scopeGlobal')}</option>
              {context?.available_tenants.map((t) => (
                <option key={t.id} value={t.id}>
                  {t.name} ({t.tenant_code})
                </option>
              ))}
            </HelpSelect>
          </div>
          <div className="user-menu">
            <LanguageSwitcher />
            <span className="user-name">{context?.user.name}</span>
            <button className="btn btn-ghost" onClick={logout}>{t('shell.logout')}</button>
          </div>
        </header>
        <OfflineBanner />
        <main className="content" id="main" ref={mainRef}>
          <div className="page-nav">
            {goBack && <BackButton onBack={goBack} />}
            <Breadcrumbs trail={trail} />
          </div>
          {/* A page that fails to load or to render is contained here: the menu, top bar and Log out keep working. */}
          <ErrorBoundary scope="route" resetKey={location.pathname}>
            <Suspense fallback={<LoadingSpinner />}>
              <Outlet />
            </Suspense>
          </ErrorBoundary>
        </main>
      </div>
    </div>
  );
}
