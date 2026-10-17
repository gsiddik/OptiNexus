// @vitest-environment jsdom
import { cleanup, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { TOKEN_STORAGE_KEY } from '../../api/client';
import type { OidcClient } from '../../api/types';
import { setLocale } from '../../i18n';
import { fail, installMockApi, ok, operatorContext, type MockCall, type MockHandler } from '../../test/mockApi';
import { renderApp } from '../../test/renderApp';

const SECRET = 'S3CR3T-value-that-must-never-leak-0123456789abcdef';
const apps = [
  { id: 'app-fleet', application_code: 'optifleet', name: 'OptiFleet' },
  { id: 'app-entry', application_code: 'optiaccounting', name: 'OptiEntry' },
];
const entry: OidcClient = {
  id: 'c-1', application_id: 'app-entry', client_id: 'onx_entryclient', name: 'OptiEntry web', confidential: true,
  redirect_uris: ['https://entry.example.com/auth/callback', 'https://entry.example.com/alt'], post_logout_redirect_uris: [], launch_url: null,
  backchannel_logout_uri: null, require_pkce: true, status: 'ACTIVE', created_at: '2026-03-01T10:00:00Z',
};
const radar: OidcClient = { ...entry, id: 'c-2', application_id: 'app-fleet', client_id: 'onx_radarclient', name: 'Radar legacy', require_pkce: false, status: 'REVOKED', redirect_uris: ['https://radar.example.com/cb'] };

let restore: () => void = () => undefined;
let calls: MockCall[] = [];

function serve(permissions: string[], extra?: MockHandler, clients: OidcClient[] = [entry, radar]) {
  const mock = installMockApi((call) => {
    const custom = extra?.(call);
    if (custom) return custom;
    if (call.url === '/auth/context') return ok(operatorContext(permissions));
    if (call.url === '/applications') return ok(apps, { meta: { pagination: { current_page: 1, per_page: 100, total: 2, last_page: 1 } } });
    if (call.url === '/oidc-clients' && call.method === 'GET') return ok(clients);
    return undefined;
  });
  restore = mock.restore;
  calls = mock.calls;
}

beforeEach(() => {
  window.localStorage.clear();
  window.localStorage.setItem(TOKEN_STORAGE_KEY, 'tok');
  setLocale('en');
});
afterEach(() => {
  cleanup();
  restore();
  vi.restoreAllMocks();
});

const VIEW = 'cgo.sso.client.view';
const MANAGE = 'cgo.sso.client.manage';

describe('SSO clients list', () => {
  it('lists clients with application, type, PKCE and status badges, and the first redirect URL', async () => {
    serve([VIEW, MANAGE]);
    renderApp('/sso-clients');
    const row = (await screen.findByText('OptiEntry web')).closest('tr')!;
    expect(within(row).getByText('onx_entryclient')).toBeTruthy();
    expect(within(row).getByText('OptiEntry')).toBeTruthy();
    expect(within(row).getByText('Confidential')).toBeTruthy();
    expect(within(row).getByText('PKCE required')).toBeTruthy();
    expect(within(row).getByText('Active')).toBeTruthy();
    expect(within(row).getByText('+1')).toBeTruthy();
    const revoked = screen.getByText('Radar legacy').closest('tr')!;
    expect(within(revoked).getByText('PKCE off')).toBeTruthy();
    expect(within(revoked).getByText('Revoked')).toBeTruthy();
    expect(within(revoked).queryByRole('button')).toBeNull(); // a revoked client offers no action
  });

  it('is forbidden without cgo.sso.client.view and does not even ask the API for clients', async () => {
    serve(['cgo.ecosystem.access']);
    renderApp('/sso-clients');
    expect(await screen.findByTestId('forbidden-state')).toBeTruthy();
    expect(screen.getByTestId('forbidden-state').textContent).toContain(VIEW);
    expect(calls.some((c) => c.url === '/oidc-clients')).toBe(false);
  });

  it('with view only, shows the list but no create, edit, rotate or revoke', async () => {
    serve([VIEW]);
    renderApp('/sso-clients');
    await screen.findByText('OptiEntry web');
    expect(screen.queryByRole('button', { name: '+ New client' })).toBeNull();
    expect(screen.queryByRole('button', { name: 'Rotate secret' })).toBeNull();
    expect(screen.queryByRole('button', { name: 'Revoke' })).toBeNull();
  });

  it('shows an empty state with the create action when there is no client', async () => {
    serve([VIEW, MANAGE], undefined, []);
    renderApp('/sso-clients');
    expect(await screen.findByText('No SSO clients yet')).toBeTruthy();
    expect(within(screen.getByTestId('empty-state')).getByRole('button', { name: '+ New client' })).toBeTruthy();
  });

  it('shows the error with its reference and a working retry when the list cannot be loaded', async () => {
    let up = false;
    serve([VIEW], (c) => (c.url === '/oidc-clients' && !up ? fail(500, 'INTERNAL_ERROR', 'boom') : undefined));
    renderApp('/sso-clients');
    expect(await screen.findByTestId('error-state')).toBeTruthy();
    expect(screen.getByTestId('error-state').textContent).toMatch(/\(Ref: [\w]+\)/);
    up = true;
    await userEvent.setup().click(screen.getByRole('button', { name: 'Try again' }));
    expect(await screen.findByText('OptiEntry web')).toBeTruthy();
  });

  it('filters by application, status and text', async () => {
    serve([VIEW]);
    renderApp('/sso-clients');
    await screen.findByText('OptiEntry web');
    const user = userEvent.setup();
    await user.selectOptions(screen.getByLabelText('Status'), 'REVOKED');
    expect(screen.queryByText('OptiEntry web')).toBeNull();
    expect(screen.getByText('Radar legacy')).toBeTruthy();
    await user.selectOptions(screen.getByLabelText('Status'), '');
    await user.type(screen.getByLabelText('Search by name or client ID'), 'nomatch');
    expect(screen.getByText('No client matches the filter.')).toBeTruthy();
  });

  it('is in Indonesian when the screen is', async () => {
    setLocale('id');
    serve([VIEW, MANAGE]);
    renderApp('/sso-clients');
    expect(await screen.findByRole('heading', { name: 'Klien SSO' })).toBeTruthy();
    expect(await screen.findByText('PKCE wajib')).toBeTruthy();
    expect(screen.getByRole('button', { name: '+ Klien baru' })).toBeTruthy();
  });
});

describe('creating a client', () => {
  async function openCreate() {
    renderApp('/sso-clients');
    const user = userEvent.setup();
    await user.click(await screen.findByRole('button', { name: '+ New client' }));
    return { user, dialog: await screen.findByRole('dialog', { name: 'New SSO client' }) };
  }

  it('starts with PKCE required and highlighted, and a confidential type', async () => {
    serve([VIEW, MANAGE]);
    const { dialog } = await openCreate();
    const pkce = within(dialog).getByRole('checkbox', { name: /Require PKCE/ }) as HTMLInputElement;
    expect(pkce.checked).toBe(true);
    expect(within(dialog).getByTestId('pkce-box').className).not.toContain('off');
    expect((within(dialog).getByRole('radio', { name: /Confidential/ }) as HTMLInputElement).checked).toBe(true);
  });

  it('turning PKCE off shows a strong warning and blocks saving until the risk is accepted', async () => {
    serve([VIEW, MANAGE]);
    const { user, dialog } = await openCreate();
    await user.selectOptions(within(dialog).getByLabelText('Application'), 'app-entry');
    await user.type(within(dialog).getByLabelText('Client name'), 'Entry web');
    await user.type(within(dialog).getByLabelText(/Redirect URLs - URL 1/), 'https://entry.example.com/cb');
    await user.click(within(dialog).getByRole('checkbox', { name: /Require PKCE/ }));

    expect(within(dialog).getByTestId('pkce-warning').textContent).toContain('Turning PKCE off weakens sign-in security');
    expect(within(dialog).getByTestId('pkce-box').className).toContain('off');
    await user.click(within(dialog).getByRole('button', { name: 'Create client' }));
    expect(within(dialog).getByText('Confirm that you accept the risk to turn PKCE off.')).toBeTruthy();
    expect(calls.some((c) => c.method === 'POST')).toBe(false);

    await user.click(within(dialog).getByRole('checkbox', { name: /I understand the risk/ }));
    await user.click(within(dialog).getByRole('button', { name: 'Create client' }));
    await waitFor(() => expect(calls.some((c) => c.method === 'POST' && c.url === '/oidc-clients')).toBe(true));
    const post = calls.find((c) => c.method === 'POST') as MockCall;
    expect(post.body).toMatchObject({ require_pkce: false });
  });

  it('a public client cannot turn PKCE off', async () => {
    serve([VIEW, MANAGE]);
    const { user, dialog } = await openCreate();
    await user.click(within(dialog).getByRole('radio', { name: /Public/ }));
    const pkce = within(dialog).getByRole('checkbox', { name: /Require PKCE/ }) as HTMLInputElement;
    expect(pkce.disabled).toBe(true);
    expect(pkce.checked).toBe(true);
    expect(within(dialog).getByText(/cannot keep a secret, so PKCE is always required/)).toBeTruthy();
  });

  it('rejects wildcard, fragment and non-http URLs beside the row, and sends nothing', async () => {
    serve([VIEW, MANAGE]);
    const { user, dialog } = await openCreate();
    await user.selectOptions(within(dialog).getByLabelText('Application'), 'app-entry');
    await user.type(within(dialog).getByLabelText('Client name'), 'Entry web');
    const first = within(dialog).getByLabelText(/Redirect URLs - URL 1/);
    await user.type(first, 'https://*.example.com/cb');
    await user.click(within(dialog).getByRole('button', { name: 'Create client' }));
    expect(within(dialog).getByText('Wildcards are not allowed: the URL must be exact.')).toBeTruthy();
    await user.clear(first);
    await user.type(first, 'ftp://x.example.com/cb');
    expect(within(dialog).getByText('Use an http:// or https:// URL.')).toBeTruthy();
    expect(calls.some((c) => c.method === 'POST')).toBe(false);
  });

  it('shows the secret once, with a copy button; closing removes it from the page, storage, address and console', async () => {
    const log = vi.spyOn(console, 'log');
    const info = vi.spyOn(console, 'info');
    const warn = vi.spyOn(console, 'warn');
    const error = vi.spyOn(console, 'error');
    let created = false;
    serve([VIEW, MANAGE], (c) => {
      if (c.method === 'POST' && c.url === '/oidc-clients') {
        created = true;
        return { status: 201, data: { success: true, data: { client: { ...entry, id: 'c-9', name: 'New one', client_id: 'onx_new' }, client_secret: SECRET } } };
      }
      if (c.url === '/oidc-clients' && created) return ok([entry, radar, { ...entry, id: 'c-9', name: 'New one', client_id: 'onx_new' }]);
      return undefined;
    });
    const { user, dialog } = await openCreate();
    await user.selectOptions(within(dialog).getByLabelText('Application'), 'app-entry');
    await user.type(within(dialog).getByLabelText('Client name'), 'New one');
    await user.type(within(dialog).getByLabelText(/Redirect URLs - URL 1/), 'https://entry.example.com/auth/callback');
    await user.click(within(dialog).getByRole('button', { name: 'Create client' }));

    const secretDialog = await screen.findByTestId('secret-dialog');
    expect(within(secretDialog).getByTestId('client-secret').textContent).toBe(SECRET);
    expect(within(secretDialog).getByText('onx_new')).toBeTruthy();
    // The request carried exact URLs and PKCE on.
    const post = calls.find((c) => c.method === 'POST') as MockCall;
    expect(post.body).toMatchObject({ application_id: 'app-entry', confidential: true, require_pkce: true, redirect_uris: ['https://entry.example.com/auth/callback'] });

    // Not closable by clicking outside, and closing without copying asks once more.
    await user.click(document.querySelector('.modal-backdrop') as Element);
    expect(screen.getByTestId('secret-dialog')).toBeTruthy();
    await user.click(within(secretDialog).getByRole('button', { name: 'Close' }));
    expect(within(secretDialog).getByText(/You have not copied the secret/)).toBeTruthy();

    await user.click(within(secretDialog).getByRole('button', { name: 'Copy secret' }));
    expect(await navigator.clipboard.readText()).toBe(SECRET); // user-event's clipboard stands in for the real one
    await user.click(within(secretDialog).getByRole('button', { name: 'Close' }));

    expect(screen.queryByTestId('secret-dialog')).toBeNull();
    expect(document.body.textContent).not.toContain(SECRET);
    expect(document.body.innerHTML).not.toContain(SECRET);
    expect(JSON.stringify({ ...window.localStorage })).not.toContain(SECRET);
    expect(JSON.stringify({ ...window.sessionStorage })).not.toContain(SECRET);
    expect(window.location.href).not.toContain(SECRET);
    for (const spy of [log, info, warn, error]) expect(JSON.stringify(spy.mock.calls)).not.toContain(SECRET);
    // The list was refreshed and shows the new client without a secret anywhere.
    expect(await screen.findByText('New one')).toBeTruthy();
  });

  it('maps a server validation error to the row it belongs to', async () => {
    serve([VIEW, MANAGE], (c) =>
      c.method === 'POST' ? fail(422, 'VALIDATION_ERROR', 'The request contains invalid data.', { 'redirect_uris.0': ['The redirect_uris.0 field must be a valid URL.'] }) : undefined,
    );
    const { user, dialog } = await openCreate();
    await user.selectOptions(within(dialog).getByLabelText('Application'), 'app-entry');
    await user.type(within(dialog).getByLabelText('Client name'), 'Entry web');
    await user.type(within(dialog).getByLabelText(/Redirect URLs - URL 1/), 'https://entry.example.com/cb');
    await user.click(within(dialog).getByRole('button', { name: 'Create client' }));
    expect(await within(dialog).findByText('The redirect_uris.0 field must be a valid URL.')).toBeTruthy();
    expect(within(dialog).getByText('Fix the marked fields and try again.')).toBeTruthy();
  });

  it('opens pre-selected from the onboarding link (?new=1&application=<code>)', async () => {
    serve([VIEW, MANAGE]);
    renderApp('/sso-clients?new=1&application=optifleet');
    const dialog = await screen.findByRole('dialog', { name: 'New SSO client' });
    expect((within(dialog).getByLabelText('Application') as HTMLSelectElement).value).toBe('app-fleet');
  });
});

describe('editing, rotating and revoking', () => {
  it('rotating shows the new secret once and invalidates nothing in the page state afterwards', async () => {
    serve([VIEW, MANAGE], (c) => (c.url === '/oidc-clients/c-1/rotate-secret' ? ok({ client: entry, client_secret: SECRET }) : undefined));
    renderApp('/sso-clients');
    const user = userEvent.setup();
    await user.click(await screen.findByRole('button', { name: 'Rotate secret' }));
    const confirm = await screen.findByRole('alertdialog', { name: 'Rotate the secret of OptiEntry web?' });
    await user.click(within(confirm).getByRole('button', { name: 'Rotate secret' }));
    const dialog = await screen.findByTestId('secret-dialog');
    expect(within(dialog).getByTestId('client-secret').textContent).toBe(SECRET);
    await user.click(within(dialog).getByRole('button', { name: 'Copy secret' }));
    await user.click(within(dialog).getByRole('button', { name: 'Close' }));
    expect(document.body.innerHTML).not.toContain(SECRET);
  });

  it('revoking asks first, then revokes and reloads', async () => {
    serve([VIEW, MANAGE], (c) => (c.url === '/oidc-clients/c-1/revoke' ? ok({ ...entry, status: 'REVOKED' }) : undefined));
    renderApp('/sso-clients');
    const user = userEvent.setup();
    await user.click(await screen.findByRole('button', { name: 'Revoke' }));
    const confirm = await screen.findByRole('alertdialog', { name: 'Revoke OptiEntry web?' });
    expect(calls.some((c) => c.url.endsWith('/revoke'))).toBe(false);
    await user.click(within(confirm).getByRole('button', { name: 'Revoke client' }));
    await waitFor(() => expect(calls.some((c) => c.method === 'POST' && c.url === '/oidc-clients/c-1/revoke')).toBe(true));
    await waitFor(() => expect(calls.filter((c) => c.url === '/oidc-clients' && c.method === 'GET').length).toBe(2));
  });

  it('a public client offers no rotate action', async () => {
    serve([VIEW, MANAGE], undefined, [{ ...entry, confidential: false }]);
    renderApp('/sso-clients');
    await screen.findByText('OptiEntry web');
    expect(screen.queryByRole('button', { name: 'Rotate secret' })).toBeNull();
  });

  it('editing sends only the editable fields and keeps the type and application fixed', async () => {
    serve([VIEW, MANAGE], (c) => (c.method === 'PUT' ? ok(entry) : undefined));
    renderApp('/sso-clients');
    const user = userEvent.setup();
    await user.click(await screen.findByRole('button', { name: 'Edit' }));
    const dialog = await screen.findByRole('dialog', { name: 'Edit OptiEntry web' });
    expect((within(dialog).getByLabelText('Application') as HTMLInputElement).disabled).toBe(true);
    expect(within(dialog).queryByRole('radio')).toBeNull();
    const name = within(dialog).getByLabelText('Client name');
    await user.clear(name);
    await user.type(name, 'OptiEntry portal');
    await user.click(within(dialog).getByRole('button', { name: 'Save changes' }));
    await waitFor(() => expect(calls.some((c) => c.method === 'PUT')).toBe(true));
    const put = calls.find((c) => c.method === 'PUT') as MockCall;
    expect(put.url).toBe('/oidc-clients/c-1');
    expect(put.body).toEqual({
      name: 'OptiEntry portal', require_pkce: true, redirect_uris: entry.redirect_uris, post_logout_redirect_uris: [], launch_url: null, backchannel_logout_uri: null,
    });
  });
});
