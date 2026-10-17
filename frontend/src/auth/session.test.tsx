// @vitest-environment jsdom
import { cleanup, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import axios from 'axios';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { ECOSYSTEM_ACCESS_REQUIRED, TOKEN_STORAGE_KEY } from '../api/client';
import { setLocale } from '../i18n';
import { fail, installMockApi, ok, operatorContext, type MockHandler } from '../test/mockApi';
import { renderApp } from '../test/renderApp';

let restore: () => void = () => undefined;
let revoke: ReturnType<typeof vi.spyOn>;

function useApi(handler: MockHandler) {
  const mock = installMockApi(handler);
  restore = mock.restore;
  return mock.calls;
}

beforeEach(() => {
  window.localStorage.clear();
  setLocale('en');
  revoke = vi.spyOn(axios, 'post').mockResolvedValue({ data: { success: true } });
});
afterEach(() => {
  cleanup();
  restore();
  vi.restoreAllMocks();
});

const denied = () => fail(403, ECOSYSTEM_ACCESS_REQUIRED, 'This account cannot use the ecosystem portal.');

async function signIn(email = 'tenant.admin@example.test') {
  const user = userEvent.setup();
  await user.type(await screen.findByLabelText('Email'), email);
  await user.type(screen.getByLabelText('Password'), 'correct horse battery');
  await user.click(screen.getByRole('button', { name: 'Sign in' }));
}

describe('R07/A24: an account that may not use the portal', () => {
  it('right password but no ecosystem access: explanation page, no token kept, nothing else requested', async () => {
    const calls = useApi((c) => (c.url === '/auth/login' ? denied() : undefined));
    renderApp('/login');
    await signIn();

    expect(await screen.findByRole('heading', { name: 'This account cannot use the ecosystem portal' })).toBeTruthy();
    expect(window.localStorage.getItem(TOKEN_STORAGE_KEY)).toBeNull();
    expect(calls.map((c) => c.url)).toEqual(['/auth/login']); // no /auth/context, no data call
    expect(screen.queryByRole('navigation')).toBeNull(); // no portal chrome, no menu, no switcher
  });

  it('shows the explanation in Indonesian too, and the page offers no link to any product', async () => {
    useApi((c) => (c.url === '/auth/login' ? denied() : undefined));
    setLocale('id');
    renderApp('/login');
    const user = userEvent.setup();
    await user.type(await screen.findByLabelText('Email'), 'a@example.test');
    await user.type(screen.getByLabelText('Kata sandi'), 'x'.repeat(12));
    await user.click(screen.getByRole('button', { name: 'Masuk' }));

    expect(await screen.findByRole('heading', { name: 'Akun ini tidak dapat menggunakan portal ekosistem' })).toBeTruthy();
    expect(screen.queryAllByRole('link')).toEqual([]); // nothing to jump to from here
    expect(screen.getByText(/OptiFleet, OptiEntry atau OptiRadar/)).toBeTruthy();
  });

  it('a stored token of such an account, opened on any portal URL: ends on the explanation page and the token is revoked and removed', async () => {
    window.localStorage.setItem(TOKEN_STORAGE_KEY, 'stale-token');
    const calls = useApi(() => denied());
    renderApp('/tenants/abc/entitlements');

    expect(await screen.findByTestId('access-denied')).toBeTruthy();
    expect(window.localStorage.getItem(TOKEN_STORAGE_KEY)).toBeNull();
    await waitFor(() => expect(revoke).toHaveBeenCalledTimes(1));
    expect(String(revoke.mock.calls[0][0])).toMatch(/\/auth\/logout$/);
    expect(JSON.stringify(revoke.mock.calls[0][2])).toContain('Bearer stale-token');
    expect(calls.length).toBe(1); // one refused request; no retry loop
  });

  it('a page request refused with the same code while signed in leads there as well (no blank page, no loop)', async () => {
    window.localStorage.setItem(TOKEN_STORAGE_KEY, 'tok');
    useApi((c) => {
      if (c.url === '/auth/context') return ok(operatorContext());
      return denied();
    });
    renderApp('/customers');
    expect(await screen.findByTestId('access-denied')).toBeTruthy();
    expect(window.localStorage.getItem(TOKEN_STORAGE_KEY)).toBeNull();
  });

  it('the button signs in with another account without a redirect loop, and the login page stays usable', async () => {
    useApi((c) => (c.url === '/auth/login' ? denied() : undefined));
    renderApp('/login');
    await signIn();
    const user = userEvent.setup();
    await user.click(await screen.findByRole('button', { name: 'Sign in with another account' }));
    expect(await screen.findByRole('button', { name: 'Sign in' })).toBeTruthy();
    expect(screen.queryByTestId('access-denied')).toBeNull();
  });

  it('a protected URL without any session goes to sign-in, not to a loop or a blank page', async () => {
    useApi(() => undefined);
    renderApp('/roles/xyz');
    expect(await screen.findByRole('button', { name: 'Sign in' })).toBeTruthy();
  });
});

describe('session lifecycle', () => {
  it('an operator signs in and reaches the dashboard', async () => {
    useApi((c) => {
      if (c.url === '/auth/login') return ok({ token: 'good-token', user: operatorContext().user });
      if (c.url === '/auth/context') return ok(operatorContext());
      return undefined;
    });
    renderApp('/login');
    await signIn('ops@example.test');
    expect(await screen.findByText('Welcome, Ops Admin')).toBeTruthy();
    expect(window.localStorage.getItem(TOKEN_STORAGE_KEY)).toBe('good-token');
  });

  it('logout always works: the device is cleared even when the server cannot be reached', async () => {
    window.localStorage.setItem(TOKEN_STORAGE_KEY, 'tok');
    useApi((c) => (c.url === '/auth/context' ? ok(operatorContext()) : undefined));
    revoke.mockRejectedValue(new Error('offline'));
    renderApp('/');
    const user = userEvent.setup();
    await user.click(await screen.findByRole('button', { name: 'Log out' }));
    expect(await screen.findByRole('button', { name: 'Sign in' })).toBeTruthy();
    expect(window.localStorage.getItem(TOKEN_STORAGE_KEY)).toBeNull();
    expect(revoke).toHaveBeenCalledTimes(1);
  });

  it('an expired session (401) returns to sign-in with a notice, not a silent redirect', async () => {
    window.localStorage.setItem(TOKEN_STORAGE_KEY, 'old');
    useApi(() => fail(401, 'UNAUTHENTICATED'));
    renderApp('/');
    expect(await screen.findByText(/Your session has expired/)).toBeTruthy();
    expect(window.localStorage.getItem(TOKEN_STORAGE_KEY)).toBeNull();
  });

  it('a wrong password shows the error on the form and is not mistaken for an expired session', async () => {
    useApi((c) => (c.url === '/auth/login' ? fail(401, 'UNAUTHENTICATED', 'The provided credentials are incorrect.') : undefined));
    renderApp('/login');
    await signIn('ops@example.test');
    expect(await screen.findByText(/The provided credentials are incorrect/)).toBeTruthy();
    expect(screen.queryByText(/session has expired/)).toBeNull();
  });

  it('the server being unreachable while the session is checked keeps the sign-in and offers a retry', async () => {
    window.localStorage.setItem(TOKEN_STORAGE_KEY, 'tok');
    let up = false;
    useApi((c) => {
      if (c.url !== '/auth/context') return undefined;
      return up ? ok(operatorContext()) : { status: 0, network: true };
    });
    renderApp('/');
    expect(await screen.findByText('We could not load your session')).toBeTruthy();
    expect(window.localStorage.getItem(TOKEN_STORAGE_KEY)).toBe('tok'); // not signed out
    up = true;
    await userEvent.setup().click(screen.getByRole('button', { name: 'Try again' }));
    expect(await screen.findByText('Welcome, Ops Admin')).toBeTruthy();
  });

  it('a remembered tenant the account no longer belongs to falls back to the global scope instead of signing out', async () => {
    window.localStorage.setItem(TOKEN_STORAGE_KEY, 'tok');
    window.localStorage.setItem('cgo_active_tenant', 'gone');
    const calls = useApi((c) => {
      if (c.url !== '/auth/context') return undefined;
      return (c.params as { tenant_id?: string } | undefined)?.tenant_id ? fail(403, 'TENANT_ACCESS_DENIED') : ok(operatorContext());
    });
    renderApp('/');
    expect(await screen.findByText('Welcome, Ops Admin')).toBeTruthy();
    expect(window.localStorage.getItem('cgo_active_tenant')).toBeNull();
    expect(calls.filter((c) => c.url === '/auth/context').length).toBe(2);
  });
});

describe('no product switcher', () => {
  it('the signed-in shell links to portal pages only: no launch URL, no tenant_hint, no product list', async () => {
    window.localStorage.setItem(TOKEN_STORAGE_KEY, 'tok');
    useApi((c) => (c.url === '/auth/context' ? ok(operatorContext()) : undefined));
    renderApp('/');
    await screen.findByText('Welcome, Ops Admin');
    const hrefs = screen.getAllByRole('link').map((a) => a.getAttribute('href') ?? '');
    expect(hrefs.every((h) => h.startsWith('/') || h.startsWith('#'))).toBe(true);
    expect(document.body.textContent).not.toMatch(/OptiFleet|OptiEntry|OptiRadar/);
  });
});
