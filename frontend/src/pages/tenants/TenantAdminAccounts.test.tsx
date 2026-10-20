// @vitest-environment jsdom
import { cleanup, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { TOKEN_STORAGE_KEY } from '../../api/client';
import type { AdminAccount, AdminActivation } from '../../api/types';
import { setLocale } from '../../i18n';
import { fail, installMockApi, ok, operatorContext, type MockCall, type MockHandler } from '../../test/mockApi';
import { renderApp } from '../../test/renderApp';

const PASSWORD = 'QWERT-YUPAS-DFGHJ-KMNPQ';
const tenant = { id: 't-1', tenant_code: 'ACME', customer_id: 'c', name: 'Acme Logistics', status: 'ACTIVE', region: null, timezone: null, currency: null, language: null };
const fleet = { id: 'app-fleet', name: 'OptiFleet', application_code: 'optifleet' };

const activation = (over: Partial<AdminActivation> = {}): AdminActivation => ({
  managed: true,
  state: 'PASSWORD_CHANGE_REQUIRED',
  created_at: '2026-10-18T07:00:00Z',
  email: { verified: false, verified_at: null, method: null, verified_by: null },
  password: { change_required: true, issued_at: '2026-10-18T07:00:00Z', expires_at: '2026-10-20T07:00:00Z', expired: false, locked_seconds: 0, changed_at: null },
  activated_at: null,
  next_steps: ['verify_email', 'hand_over_credential'],
  ...over,
});
const account = (id: string, name: string, act: AdminActivation): AdminAccount => ({
  user: { id, name, email: `${id}@example.test`, status: act.state === 'ACTIVATED' ? 'ACTIVE' : 'INVITED', created_at: '2026-10-18T07:00:00Z' },
  membership: { status: 'ACTIVE', is_tenant_admin: true },
  applications: [{ id: 'app-fleet', code: 'optifleet', name: 'OptiFleet' }],
  roles: [],
  activation: act,
});

const verifiedBy = { id: 'op', name: 'Operator One' };
const created = account('created', 'Created Admin', activation());
const verified = account('verified', 'Verified Admin', activation({
  email: { verified: true, verified_at: '2026-10-18T08:00:00Z', method: 'OPERATOR_MANUAL', verified_by: verifiedBy }, next_steps: ['hand_over_credential'],
}));
const expired = account('expired', 'Expired Admin', activation({
  state: 'CREDENTIAL_EXPIRED', password: { change_required: true, issued_at: '2026-10-10T07:00:00Z', expires_at: '2026-10-12T07:00:00Z', expired: true, locked_seconds: 0, changed_at: null }, next_steps: ['verify_email', 'reissue_credential'],
}));
const emailPending = account('pending', 'Pending Admin', activation({
  state: 'EMAIL_VERIFICATION_PENDING', password: { change_required: false, issued_at: '2026-10-18T07:00:00Z', expires_at: null, expired: false, locked_seconds: 0, changed_at: '2026-10-18T09:00:00Z' }, next_steps: ['verify_email'],
}));
const active = account('active', 'Active Admin', activation({
  state: 'ACTIVATED', email: { verified: true, verified_at: '2026-10-18T08:00:00Z', method: 'OPERATOR_MANUAL', verified_by: verifiedBy },
  password: { change_required: false, issued_at: null, expires_at: null, expired: false, locked_seconds: 0, changed_at: '2026-10-18T09:00:00Z' }, activated_at: '2026-10-18T09:00:00Z', next_steps: [],
}));
const regular = account('regular', 'Regular Admin', activation({ managed: false, state: null, email: { verified: true, verified_at: '2026-09-01T00:00:00Z', method: 'INVITATION', verified_by: null }, password: { change_required: false, issued_at: null, expires_at: null, expired: false, locked_seconds: 0, changed_at: null }, next_steps: [] }));

const ALL = ['cgo.tenant.view', 'cgo.tenant.admin.assign', 'cgo.user.email.verify'];
let restore: () => void = () => undefined;
let calls: MockCall[] = [];
let rows: AdminAccount[] = [];

function serve(permissions: string[] = ALL, extra?: MockHandler) {
  const mock = installMockApi((call) => {
    const custom = extra?.(call);
    if (custom) return custom;
    if (call.url === '/auth/context') return ok(operatorContext(permissions));
    if (call.url === '/ecosystem/tenants/t-1/admin-accounts/account-options') return ok([{ id: '2a0e3a62-2b8c-4a73-9a5e-0c6f3f1c9a11', name: 'Regular Admin', email: 'regular@example.test' }]);
    if (call.url === '/tenants/t-1') return ok(tenant);
    if (call.url === '/tenants/t-1/applications') return ok([fleet]);
    if (call.url === '/tenants/t-1/admins') return ok([]);
    if (call.url === '/roles') return ok([], { meta: { pagination: { current_page: 1, per_page: 100, total: 0, last_page: 1 } } });
    if (call.url === '/ecosystem/tenants/t-1/admin-accounts' && call.method === 'GET') return ok(rows);
    return undefined;
  });
  restore = mock.restore;
  calls = mock.calls;
}

beforeEach(() => {
  window.localStorage.clear();
  window.localStorage.setItem(TOKEN_STORAGE_KEY, 'tok');
  setLocale('en');
  regular.user.status = 'ACTIVE';
  rows = [created, verified, expired, emailPending, active, regular];
});
afterEach(() => {
  cleanup();
  restore();
  vi.restoreAllMocks();
  setLocale('en');
});

const rowOf = async (name: string) => (await screen.findByText(name, {}, { timeout: 5000 })).closest('tr')!;

describe('first Admin Tenant panel', () => {
  it('shows the five activation states apart, and the e-mail and password facts behind them', async () => {
    serve();
    renderApp('/tenants/t-1/admins');
    expect(within(await rowOf('Created Admin')).getByText('Account created')).toBeTruthy();
    expect(within(await rowOf('Created Admin')).getByText('Not verified')).toBeTruthy();
    expect(within(await rowOf('Verified Admin')).getByText('E-mail verified, password change required')).toBeTruthy();
    expect(within(await rowOf('Verified Admin')).getByText(/Verified manually by Operator One/)).toBeTruthy();
    expect(within(await rowOf('Expired Admin')).getByText('Temporary password expired')).toBeTruthy();
    expect(within(await rowOf('Expired Admin')).getByText(/^Expired \d/)).toBeTruthy();
    expect(within(await rowOf('Pending Admin')).getByText('Password changed, e-mail not verified')).toBeTruthy();
    expect(within(await rowOf('Active Admin')).getByText('Activation complete')).toBeTruthy();
    expect(within(await rowOf('Regular Admin')).getByText('Regular account')).toBeTruthy();
    expect(within(await rowOf('Regular Admin')).getByText(/Verified through an invitation/)).toBeTruthy();
  });

  it('offers verification for unverified accounts and password reset for active administrators', async () => {
    serve();
    renderApp('/tenants/t-1/admins');
    const verify = (name: string) => within(screen.getByText(name).closest('tr')!).queryByRole('button', { name: /Record e-mail verification/ });
    const reissue = (name: string) => within(screen.getByText(name).closest('tr')!).queryByRole('button', { name: /New temporary password/ });
    await rowOf('Created Admin');
    expect(verify('Created Admin')).toBeTruthy();
    expect(verify('Verified Admin')).toBeNull();
    expect(verify('Expired Admin')).toBeTruthy();
    expect(verify('Pending Admin')).toBeTruthy();
    expect(verify('Active Admin')).toBeNull();
    expect(verify('Regular Admin')).toBeNull();
    expect(reissue('Created Admin')).toBeTruthy();
    expect(reissue('Expired Admin')).toBeTruthy();
    expect(reissue('Pending Admin')).toBeNull();
    expect(reissue('Active Admin')).toBeTruthy();
    expect(reissue('Regular Admin')).toBeTruthy();
  });

  it('hides what the operator may not do (the server refuses it as well)', async () => {
    serve(['cgo.tenant.view']);
    renderApp('/tenants/t-1/admins');
    await rowOf('Created Admin');
    expect(screen.queryByRole('button', { name: 'Create admin account' })).toBeNull();
    expect(screen.queryByRole('button', { name: /Record e-mail verification/ })).toBeNull();
    expect(screen.queryByRole('button', { name: /New temporary password/ })).toBeNull();
  });

  it('creates an account, shows the temporary password once, and it is gone after closing and after a reload of the list', async () => {
    const user = userEvent.setup();
    vi.spyOn(navigator.clipboard, 'writeText').mockResolvedValue();
    serve(ALL, (call) => {
      if (call.url === '/ecosystem/tenants/t-1/admin-accounts' && call.method === 'POST') {
        rows = [...rows, account('new', 'New Admin', activation())];
        return { status: 201, data: { success: true, data: { ...rows[rows.length - 1], outcome: 'created', temporary_credential: { password: PASSWORD, expires_at: '2026-10-20T07:00:00Z', shown_once: true } } } };
      }
      return undefined;
    });
    renderApp('/tenants/t-1/admins');
    await rowOf('Created Admin');
    await user.click(screen.getByRole('button', { name: 'Create admin account' }));
    const form = screen.getByRole('dialog', { name: 'Create the first Admin Tenant' });
    await user.type(within(form).getByLabelText('Name', { exact: true }), 'New Admin');
    await user.type(within(form).getByLabelText(/^E-mail address/), 'new@example.test');
    await user.click(within(form).getByRole('button', { name: 'Create account' }));

    const post = calls.find((c) => c.method === 'POST')!;
    expect(post.body).toEqual({ name: 'New Admin', email: 'new@example.test', application_ids: ['app-fleet'], role_ids: [] });
    expect(JSON.stringify(post.body)).not.toMatch(/password/i); // the operator never types a password

    const dialog = await screen.findByTestId('secret-dialog');
    expect(within(dialog).getByTestId('client-secret').textContent).toBe(PASSWORD);
    expect(within(dialog).getByText(/never e-mailed/)).toBeTruthy();
    await user.click(within(dialog).getByRole('button', { name: 'Copy password' }));
    await user.click(within(dialog).getByRole('button', { name: 'Close' }));

    await waitFor(() => expect(screen.queryByTestId('secret-dialog')).toBeNull());
    expect(document.body.textContent).not.toContain(PASSWORD);
    expect(await rowOf('New Admin')).toBeTruthy(); // list reloaded; the credential is not part of it
    expect(document.body.textContent).not.toContain(PASSWORD);
  });

  it('never writes the temporary password to browser storage, the address bar, the history or the console', async () => {
    const user = userEvent.setup();
    vi.spyOn(navigator.clipboard, 'writeText').mockResolvedValue();
    const consoleSinks = (['log', 'info', 'warn', 'error', 'debug'] as const).map((method) => vi.spyOn(console, method).mockImplementation(() => undefined));
    serve(ALL, (call) => {
      if (call.url === '/ecosystem/tenants/t-1/admin-accounts' && call.method === 'POST') {
        rows = [...rows, account('new', 'New Admin', activation())];
        return { status: 201, data: { success: true, data: { ...rows[rows.length - 1], outcome: 'created', temporary_credential: { password: PASSWORD, expires_at: '2026-10-20T07:00:00Z', shown_once: true } } } };
      }
      return undefined;
    });

    /** Everything a reload could bring back: both storages, cookies, the address and the history state. */
    const persisted = () => {
      const dump = (store: Storage) => Array.from({ length: store.length }, (_, i) => [store.key(i), store.getItem(store.key(i) ?? '')]);
      return JSON.stringify({ local: dump(window.localStorage), session: dump(window.sessionStorage), cookie: document.cookie, url: window.location.href, state: window.history.state });
    };

    renderApp('/tenants/t-1/admins');
    await rowOf('Created Admin');
    await user.click(screen.getByRole('button', { name: 'Create admin account' }));
    const form = screen.getByRole('dialog', { name: 'Create the first Admin Tenant' });
    await user.type(within(form).getByLabelText('Name', { exact: true }), 'New Admin');
    await user.type(within(form).getByLabelText(/^E-mail address/), 'new@example.test');
    await user.click(within(form).getByRole('button', { name: 'Create account' }));

    const dialog = await screen.findByTestId('secret-dialog');
    expect(within(dialog).getByTestId('client-secret').textContent).toBe(PASSWORD);
    expect(persisted()).not.toContain(PASSWORD); // while it is on screen
    await user.click(within(dialog).getByRole('button', { name: 'Copy password' }));
    await user.click(within(dialog).getByRole('button', { name: 'Close' }));
    await waitFor(() => expect(screen.queryByTestId('secret-dialog')).toBeNull());

    expect(persisted()).not.toContain(PASSWORD); // after it was closed
    expect(JSON.stringify(consoleSinks.map((sink) => sink.mock.calls))).not.toContain(PASSWORD);
  });

  it('keeps the temporary password on screen while the rest of the tab reloads slowly', async () => {
    const user = userEvent.setup();
    let adminsCalls = 0;
    serve(ALL, (call) => {
      if (call.url === '/ecosystem/tenants/t-1/admin-accounts' && call.method === 'POST') {
        return ok({ ...created, outcome: 'created', temporary_credential: { password: PASSWORD, expires_at: null, shown_once: true } });
      }
      if (call.url === '/tenants/t-1/admins') {
        adminsCalls += 1;
        return adminsCalls > 1 ? new Promise((resolve) => setTimeout(() => resolve(ok([])), 300)) : ok([]);
      }
      return undefined;
    });
    renderApp('/tenants/t-1/admins');
    await rowOf('Created Admin');
    await user.click(screen.getByRole('button', { name: 'Create admin account' }));
    const form = screen.getByRole('dialog', { name: 'Create the first Admin Tenant' });
    await user.type(within(form).getByLabelText('Name', { exact: true }), 'X Admin');
    await user.type(within(form).getByLabelText(/^E-mail address/), 'x@example.test');
    await user.click(within(form).getByRole('button', { name: 'Create account' }));
    await screen.findByTestId('secret-dialog');
    await waitFor(() => expect(adminsCalls).toBeGreaterThan(1)); // the tab reloaded its own list ...
    await new Promise((resolve) => setTimeout(resolve, 500)); // ... and finished doing so
    expect(screen.getByTestId('client-secret').textContent).toBe(PASSWORD); // ... without losing the dialog
  });

  it('asks before closing a password that was not copied', async () => {
    const user = userEvent.setup();
    serve(ALL, (call) => (call.method === 'POST' ? ok({ ...created, outcome: 'created', temporary_credential: { password: PASSWORD, expires_at: null, shown_once: true } }) : undefined));
    renderApp('/tenants/t-1/admins');
    await rowOf('Created Admin');
    await user.click(screen.getByRole('button', { name: 'Create admin account' }));
    const form = screen.getByRole('dialog', { name: 'Create the first Admin Tenant' });
    await user.type(within(form).getByLabelText('Name', { exact: true }), 'X Admin');
    await user.type(within(form).getByLabelText(/^E-mail address/), 'x@example.test');
    await user.click(within(form).getByRole('button', { name: 'Create account' }));
    const dialog = await screen.findByTestId('secret-dialog');
    await user.click(within(dialog).getByRole('button', { name: 'Close' }));
    expect(screen.getByText(/You have not copied the password/)).toBeTruthy();
    await user.click(screen.getByRole('button', { name: 'Close without copying' }));
    await waitFor(() => expect(screen.queryByTestId('secret-dialog')).toBeNull());
  });

  it('links an existing account by id with the e-mail confirmed, and shows no password for it', async () => {
    const user = userEvent.setup();
    serve(ALL, (call) => (call.method === 'POST' ? ok({ ...regular, outcome: 'linked', temporary_credential: null }) : undefined));
    renderApp('/tenants/t-1/admins');
    await rowOf('Created Admin');
    await user.click(screen.getByRole('button', { name: 'Create admin account' }));
    const form = screen.getByRole('dialog', { name: 'Create the first Admin Tenant' });
    await user.click(within(form).getByLabelText('Existing OptiNexus account'));
    await within(form).findByRole('option', { name: /Regular Admin/ });
    await user.selectOptions(within(form).getByLabelText(/^Account ID/), '2a0e3a62-2b8c-4a73-9a5e-0c6f3f1c9a11');
    expect((within(form).getByLabelText(/^E-mail address of that account/) as HTMLInputElement).value).toBe('regular@example.test');
    await user.click(within(form).getByRole('button', { name: 'Create account' }));

    expect(calls.find((c) => c.method === 'POST')!.body).toEqual({ user_id: '2a0e3a62-2b8c-4a73-9a5e-0c6f3f1c9a11', confirm_email: 'regular@example.test', application_ids: ['app-fleet'], role_ids: [] });
    expect(await screen.findByText(/was linked as tenant administrator/)).toBeTruthy();
    expect(screen.queryByTestId('secret-dialog')).toBeNull();
  });

  it('refuses a creation without an application before asking the server', async () => {
    const user = userEvent.setup();
    serve(ALL, (call) => (call.url === '/tenants/t-1/applications' ? ok([fleet, { id: 'app-entry', name: 'OptiEntry', application_code: 'optiaccounting' }]) : undefined));
    renderApp('/tenants/t-1/admins');
    await rowOf('Created Admin');
    await user.click(screen.getByRole('button', { name: 'Create admin account' }));
    const form = screen.getByRole('dialog', { name: 'Create the first Admin Tenant' });
    await user.type(within(form).getByLabelText('Name', { exact: true }), 'X Admin');
    await user.type(within(form).getByLabelText(/^E-mail address/), 'x@example.test');
    await user.click(within(form).getByRole('button', { name: 'Create account' }));
    expect(within(form).getByText('Choose at least one application.')).toBeTruthy();
    expect(calls.some((c) => c.method === 'POST')).toBe(false);
  });

  it('records a manual e-mail verification: the address must match and a reason is required', async () => {
    const user = userEvent.setup();
    serve(ALL, (call) => {
      if (call.method === 'POST' && call.url === '/ecosystem/tenants/t-1/admin-accounts/created/email-verification') {
        rows = rows.map((r) => (r.user.id === 'created' ? verified : r));
        return ok(verified);
      }
      return undefined;
    });
    renderApp('/tenants/t-1/admins');
    await user.click(within(await rowOf('Created Admin')).getByRole('button', { name: /Record e-mail verification/ }));
    const dialog = screen.getByRole('dialog', { name: 'Record e-mail verification for Created Admin' });

    await user.type(within(dialog).getByLabelText(/^Verified e-mail address/), 'someone.else@example.test');
    await user.type(within(dialog).getByLabelText(/^How did you verify it/), 'short');
    await user.click(within(dialog).getByRole('button', { name: 'Record verification' }));
    expect(within(dialog).getByText('This is not the e-mail address of the account.')).toBeTruthy();
    expect(within(dialog).getByText(/Describe the check in at least 10 characters/)).toBeTruthy();
    expect(calls.some((c) => c.method === 'POST')).toBe(false);

    await user.clear(within(dialog).getByLabelText(/^Verified e-mail address/));
    await user.type(within(dialog).getByLabelText(/^Verified e-mail address/), 'Created@Example.test');
    await user.clear(within(dialog).getByLabelText(/^How did you verify it/));
    await user.type(within(dialog).getByLabelText(/^How did you verify it/), 'Called the customer contact on the number in the contract');
    await user.click(within(dialog).getByRole('button', { name: 'Record verification' }));

    await waitFor(() => expect(calls.some((c) => c.method === 'POST')).toBe(true));
    expect(calls.find((c) => c.method === 'POST')!.body).toEqual({ email: 'Created@Example.test', reason: 'Called the customer contact on the number in the contract' });
    expect(await screen.findByText('E-mail verification recorded for Created Admin.')).toBeTruthy();
    await waitFor(() => expect(screen.queryByRole('dialog', { name: /Record e-mail verification for/ })).toBeNull());
  });

  it('shows the server\'s refusal in the screen\'s language (an operator cannot verify their own address)', async () => {
    const user = userEvent.setup();
    setLocale('id');
    serve(ALL, (call) => (call.method === 'POST' ? fail(403, 'SELF_VERIFICATION_DENIED', 'You cannot verify your own e-mail address.') : undefined));
    renderApp('/tenants/t-1/admins');
    await user.click(within(await rowOf('Created Admin')).getByRole('button', { name: /Catat verifikasi e-mail/ }));
    const dialog = screen.getByRole('dialog', { name: 'Catat verifikasi e-mail untuk Created Admin' });
    await user.type(within(dialog).getByLabelText(/^Alamat e-mail yang diverifikasi/), 'created@example.test');
    await user.type(within(dialog).getByLabelText(/^Bagaimana Anda memverifikasinya/), 'Menelepon kontak pelanggan');
    await user.click(within(dialog).getByRole('button', { name: 'Catat verifikasi' }));
    expect(await within(dialog).findByText('Anda tidak dapat memverifikasi alamat e-mail Anda sendiri.')).toBeTruthy();
  });

  it('issues a new temporary password after a confirmation, and shows it once', async () => {
    const user = userEvent.setup();
    serve(ALL, (call) => (call.method === 'POST' && call.url.endsWith('/expired/temporary-credential')
      ? ok({ ...expired, temporary_credential: { password: PASSWORD, expires_at: '2026-10-21T07:00:00Z', shown_once: true } })
      : undefined));
    renderApp('/tenants/t-1/admins');
    await user.click(within(await rowOf('Expired Admin')).getByRole('button', { name: /New temporary password/ }));
    expect(calls.some((c) => c.method === 'POST')).toBe(false);
    await user.click(screen.getByRole('button', { name: 'Issue new password' }));
    const dialog = await screen.findByTestId('secret-dialog');
    expect(within(dialog).getByTestId('client-secret').textContent).toBe(PASSWORD);
    expect(within(dialog).getByText(/Valid until/)).toBeTruthy();
  });

  it('is in Indonesian', async () => {
    setLocale('id');
    serve();
    renderApp('/tenants/t-1/admins');
    expect(await screen.findByText('Akun Admin Tenant pertama')).toBeTruthy();
    expect(within(await rowOf('Created Admin')).getByText('Akun dibuat')).toBeTruthy();
    expect(within(await rowOf('Expired Admin')).getByText('Kata sandi sementara kedaluwarsa')).toBeTruthy();
    expect(within(await rowOf('Active Admin')).getByText('Aktivasi selesai')).toBeTruthy();
    expect(screen.getByRole('button', { name: 'Buat akun admin' })).toBeTruthy();
  });
});
