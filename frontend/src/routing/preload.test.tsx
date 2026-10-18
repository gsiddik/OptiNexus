// @vitest-environment jsdom
import { cleanup, render, screen, waitFor } from '@testing-library/react';
import { Suspense } from 'react';
import { MemoryRouter } from 'react-router-dom';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { TOKEN_STORAGE_KEY } from '../api/client';
import { AppRoutes } from '../App';
import { AuthProvider } from '../auth/AuthContext';
import { preloadPageFor } from '../routes';
import { installMockApi, ok, operatorContext } from '../test/mockApi';
import { lazyPage } from './lazyPage';

// Page files counted instead of downloaded: the module factory runs when (and only when) the file is requested.
const loaded = vi.hoisted(() => ({ roles: vi.fn(), onboarding: vi.fn(), dashboard: vi.fn() }));
vi.mock('../pages/roles/RolesListPage', () => {
  loaded.roles();
  return { RolesListPage: () => <h1>Roles page</h1> };
});
vi.mock('../pages/onboarding/OnboardingPage', () => {
  loaded.onboarding();
  return { OnboardingPage: () => <h1>Onboarding page</h1> };
});
vi.mock('../pages/DashboardPage', () => {
  loaded.dashboard();
  return { DashboardPage: () => <h1>Dashboard page</h1> };
});

beforeEach(() => {
  loaded.roles.mockClear();
  loaded.onboarding.mockClear();
  loaded.dashboard.mockClear();
});
afterEach(() => {
  cleanup();
  vi.restoreAllMocks();
});

describe('preloadPageFor', () => {
  it('starts downloading the file of the page that the address opens, and only that one', async () => {
    preloadPageFor('/roles');
    await waitFor(() => expect(loaded.roles).toHaveBeenCalledTimes(1));
    expect(loaded.dashboard).not.toHaveBeenCalled();
    expect(loaded.onboarding).not.toHaveBeenCalled();
  });

  it('follows parameters in the address', async () => {
    preloadPageFor('/tenants/t-1/onboarding');
    await waitFor(() => expect(loaded.onboarding).toHaveBeenCalledTimes(1));
    expect(loaded.roles).not.toHaveBeenCalled();
  });

  it('downloads nothing for the sign-in page or an address that does not exist', async () => {
    preloadPageFor('/login');
    preloadPageFor('/no/such/page');
    await new Promise((resolve) => setTimeout(resolve, 20));
    expect(loaded.roles).not.toHaveBeenCalled();
    expect(loaded.dashboard).not.toHaveBeenCalled();
  });

  it('the page opens from the file that was fetched ahead', async () => {
    preloadPageFor('/roles');
    const mock = installMockApi((call) => (call.url === '/auth/context' ? ok(operatorContext()) : ok([], { pagination: { current_page: 1, per_page: 25, total: 0, last_page: 1 } })));
    window.localStorage.setItem(TOKEN_STORAGE_KEY, 'token');
    render(
      <MemoryRouter initialEntries={['/roles']}>
        <AuthProvider>
          <AppRoutes />
        </AuthProvider>
      </MemoryRouter>,
    );
    expect(await screen.findByText('Roles page')).toBeTruthy();
    mock.restore();
    window.localStorage.clear();
  });
});

describe('lazyPage', () => {
  const Page = () => <p>the page</p>;

  it('preload downloads once and a later render reuses it', async () => {
    const loader = vi.fn(() => Promise.resolve({ Page }));
    const Lazy = lazyPage(loader, 'Page');
    Lazy.preload();
    Lazy.preload();
    await Promise.resolve();
    expect(loader).toHaveBeenCalledTimes(1);
    render(
      <Suspense fallback={<p>loading</p>}>
        <Lazy />
      </Suspense>,
    );
    expect(await screen.findByText('the page')).toBeTruthy();
    expect(loader).toHaveBeenCalledTimes(1);
  });

  it('a file that has already arrived is drawn at once, without the loading state', async () => {
    const Lazy = lazyPage(() => Promise.resolve({ Page }), 'Page');
    Lazy.preload();
    await new Promise((resolve) => setTimeout(resolve, 0));
    render(
      <Suspense fallback={<p>loading</p>}>
        <Lazy />
      </Suspense>,
    );
    expect(screen.getByText('the page')).toBeTruthy(); // synchronously: no findBy
    expect(screen.queryByText('loading')).toBeNull();
  });

  it('a failed preload is not remembered: opening the page downloads again', async () => {
    const loader = vi
      .fn<() => Promise<{ Page: typeof Page }>>()
      .mockRejectedValueOnce(new TypeError('Failed to fetch dynamically imported module'))
      .mockResolvedValue({ Page });
    const Lazy = lazyPage(loader, 'Page');
    Lazy.preload();
    await new Promise((resolve) => setTimeout(resolve, 0));
    render(
      <Suspense fallback={<p>loading</p>}>
        <Lazy />
      </Suspense>,
    );
    expect(await screen.findByText('the page')).toBeTruthy();
    expect(loader).toHaveBeenCalledTimes(2);
  });
});
