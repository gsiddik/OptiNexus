// @vitest-environment jsdom
import { cleanup, render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { Suspense } from 'react';
import { MemoryRouter } from 'react-router-dom';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { ErrorBoundary } from '../components/ErrorBoundary';
import { ErrorState, ForbiddenState } from '../components/StateViews';
import { ApiRequestError } from '../api/client';
import { setLocale } from '../i18n';
import { lazyPage } from '../routing/lazyPage';
import { canAutoReload, installChunkErrorHandling, isChunkLoadError, markAutoReload, RELOAD_WINDOW_MS } from './chunkErrors';

beforeEach(() => {
  setLocale('en');
  window.sessionStorage.clear();
  vi.spyOn(console, 'error').mockImplementation(() => undefined);
});
afterEach(() => {
  cleanup();
  vi.restoreAllMocks();
});

function Boom(): never {
  throw new Error('render exploded');
}

describe('isChunkLoadError', () => {
  it('recognises the stale-chunk failures of the main browsers and nothing else', () => {
    expect(isChunkLoadError(new TypeError('Failed to fetch dynamically imported module: https://x/assets/Page-abc.js'))).toBe(true);
    expect(isChunkLoadError(new Error('error loading dynamically imported module'))).toBe(true);
    expect(isChunkLoadError(new Error('Importing a module script failed.'))).toBe(true);
    expect(isChunkLoadError(Object.assign(new Error('x'), { name: 'ChunkLoadError' }))).toBe(true);
    expect(isChunkLoadError(new Error('Cannot read properties of undefined'))).toBe(false);
    expect(isChunkLoadError(undefined)).toBe(false);
  });
});

describe('stale chunk reload', () => {
  it('reloads once per minute, then leaves the error screen to the user', () => {
    const target = new EventTarget() as unknown as Window;
    const reload = vi.fn();
    installChunkErrorHandling(target, reload);
    const first = new Event('vite:preloadError', { cancelable: true });
    target.dispatchEvent(first);
    expect(reload).toHaveBeenCalledTimes(1);
    expect(first.defaultPrevented).toBe(true);

    const second = new Event('vite:preloadError', { cancelable: true });
    target.dispatchEvent(second);
    expect(reload).toHaveBeenCalledTimes(1); // no loop
    expect(second.defaultPrevented).toBe(false); // the import rejects and the boundary shows the reload button
  });

  it('allows another automatic reload after the window has passed', () => {
    const t0 = 1_000_000;
    markAutoReload(t0);
    expect(canAutoReload(t0 + 1000)).toBe(false);
    expect(canAutoReload(t0 + RELOAD_WINDOW_MS + 1)).toBe(true);
  });
});

describe('error boundary', () => {
  it('a render error shows a message, a reference and a reload action instead of a blank page', () => {
    render(<ErrorBoundary scope="route"><Boom /></ErrorBoundary>);
    expect(screen.getByRole('alert').textContent).toContain('Something went wrong on this page');
    expect(screen.getByText(/Reference: /)).toBeTruthy();
    expect(screen.getByRole('button', { name: 'Reload page' })).toBeTruthy();
    expect(screen.getByRole('link', { name: 'Go to the dashboard' }).getAttribute('href')).toBe('/');
  });

  it('is in Indonesian when the screen is', () => {
    setLocale('id');
    render(<ErrorBoundary scope="app"><Boom /></ErrorBoundary>);
    expect(screen.getByText('Portal mengalami masalah yang tidak terduga')).toBeTruthy();
  });

  it('"Try again" renders the page again and the route boundary clears when the address changes', async () => {
    let broken = true;
    function Flaky() {
      if (broken) throw new Error('boom');
      return <p>recovered</p>;
    }
    const { rerender } = render(<ErrorBoundary scope="route" resetKey="/a"><Flaky /></ErrorBoundary>);
    broken = false;
    await userEvent.setup().click(screen.getByRole('button', { name: 'Try again' }));
    expect(screen.getByText('recovered')).toBeTruthy();

    broken = true;
    rerender(<ErrorBoundary scope="route" resetKey="/b"><Flaky /></ErrorBoundary>);
    expect(screen.getByRole('alert')).toBeTruthy();
    broken = false;
    rerender(<ErrorBoundary scope="route" resetKey="/c"><Flaky /></ErrorBoundary>);
    expect(screen.getByText('recovered')).toBeTruthy();
  });

  it('a page file that cannot be downloaded shows the chunk message with a reload action and no retry that cannot work', async () => {
    const Missing = lazyPage(() => Promise.reject(new TypeError('Failed to fetch dynamically imported module: /assets/X.js')) as never, 'X');
    render(
      <MemoryRouter>
        <ErrorBoundary scope="route">
          <Suspense fallback={<p>loading</p>}>
            <Missing />
          </Suspense>
        </ErrorBoundary>
      </MemoryRouter>,
    );
    expect(await screen.findByTestId('chunk-error')).toBeTruthy();
    expect(screen.getByText('Part of the portal could not be loaded')).toBeTruthy();
    expect(screen.getByRole('button', { name: 'Reload page' })).toBeTruthy();
    expect(screen.queryByRole('button', { name: 'Try again' })).toBeNull();
  });
});

describe('state views', () => {
  const error = (status: number | undefined, code: string) => new ApiRequestError({ code, message: `${code} message` }, status, 'abcd1234-0000');

  it('shows forbidden for a permission 403 and names the permission', () => {
    render(<ErrorState error={error(403, 'UNAUTHORIZED')} permission="cgo.sso.client.view" />);
    expect(screen.getByTestId('forbidden-state').textContent).toContain('cgo.sso.client.view');
  });

  it('shows an offline view with the reference and a working retry for a network failure', async () => {
    const retry = vi.fn();
    render(<ErrorState error={error(undefined, 'NETWORK_ERROR')} onRetry={retry} />);
    expect(screen.getByTestId('offline-state').textContent).toContain('(Ref: abcd1234)');
    await userEvent.setup().click(screen.getByRole('button', { name: 'Try again' }));
    expect(retry).toHaveBeenCalledTimes(1);
  });

  it('shows the generic failure with the reference for a server error', () => {
    render(<ErrorState error={error(500, 'INTERNAL_ERROR')} />);
    expect(screen.getByTestId('error-state').textContent).toContain('abcd1234');
  });

  it('forbidden view in Indonesian', () => {
    setLocale('id');
    render(<ForbiddenState />);
    expect(screen.getByText('Anda tidak memiliki akses ke halaman ini')).toBeTruthy();
  });
});
