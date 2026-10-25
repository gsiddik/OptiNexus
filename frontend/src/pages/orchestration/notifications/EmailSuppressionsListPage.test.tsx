// @vitest-environment jsdom
import { cleanup, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { afterEach, beforeEach, expect, it } from 'vitest';
import { TOKEN_STORAGE_KEY } from '../../../api/client';
import { installMockApi, ok, operatorContext } from '../../../test/mockApi';
import { renderApp } from '../../../test/renderApp';
import { setLocale } from '../../../i18n';

let restore = () => {};
beforeEach(() => { localStorage.setItem(TOKEN_STORAGE_KEY, 'tok'); setLocale('en'); });
afterEach(() => { cleanup(); restore(); });

const meta = { meta: { current_page: 1, last_page: 1, per_page: 20, total: 1 } };
const row = { id: 's1', email: 'bounced@toko.test', reason: 'HARD_BOUNCE', provider: 'postmark', description: 'Unknown user', active: true, suppressed_at: '2026-10-25T01:00:00Z', released_at: null, released_by: null, created_at: '', updated_at: '' };

it('lists active suppressions and releases one after confirmation', async () => {
  const released: string[] = [];
  const listParams: unknown[] = [];
  const mock = installMockApi(({ url, method, params }) => {
    if (url === '/auth/context') return ok(operatorContext(['cgo.ecosystem.access', 'cgo.notification.delivery.view', 'cgo.notification.delivery.retry']));
    if (url === '/email-suppressions/s1/release' && method === 'POST') { released.push(url); return ok({ ...row, active: false }); }
    if (url === '/email-suppressions') { listParams.push(params); return ok(released.length ? [] : [row], meta); }
    return ok([]);
  }); restore = mock.restore;

  renderApp('/email-suppressions');
  expect(await screen.findByText('bounced@toko.test')).toBeTruthy();
  expect(screen.getByText('Hard bounce')).toBeTruthy();
  expect(listParams[0]).toMatchObject({ state: 'active' });

  await userEvent.click(screen.getByRole('button', { name: 'Release' }));
  const dialog = screen.getByRole('alertdialog');
  expect(within(dialog).getByText(/bounced@toko\.test again/)).toBeTruthy();
  await userEvent.click(within(dialog).getByRole('button', { name: 'Release' }));

  await waitFor(() => expect(released).toEqual(['/email-suppressions/s1/release']));
  expect(await screen.findByText('No suppressed addresses.')).toBeTruthy();
});

it('hides the release action without the retry permission and shows Indonesian labels', async () => {
  setLocale('id');
  const mock = installMockApi(({ url }) => {
    if (url === '/auth/context') return ok(operatorContext(['cgo.ecosystem.access', 'cgo.notification.delivery.view']));
    if (url === '/email-suppressions') return ok([{ ...row, reason: 'SPAM_COMPLAINT' }], meta);
    return ok([]);
  }); restore = mock.restore;

  renderApp('/email-suppressions');
  expect(await screen.findByText('Keluhan spam')).toBeTruthy();
  expect(screen.getByRole('heading', { name: 'E-mail Diblokir' })).toBeTruthy();
  expect(screen.queryByRole('button', { name: 'Lepaskan' })).toBeNull();
});
