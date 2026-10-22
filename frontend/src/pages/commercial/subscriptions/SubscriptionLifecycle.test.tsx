// @vitest-environment jsdom
import { cleanup, screen } from '@testing-library/react';
import { afterEach, beforeEach, expect, it } from 'vitest';
import { TOKEN_STORAGE_KEY } from '../../../api/client';
import { installMockApi, ok, operatorContext } from '../../../test/mockApi';
import { renderApp } from '../../../test/renderApp';
import { setLocale } from '../../../i18n';
let restore = () => {};
beforeEach(() => { localStorage.setItem(TOKEN_STORAGE_KEY, 'tok'); setLocale('en'); });
afterEach(() => { cleanup(); restore(); });
it.each(['ACTIVE', 'EXPIRED', 'SUSPENDED'])('offers renewal only for EXPIRED and no manual deadline buttons (%s)', async (status) => {
  const mock = installMockApi(({ url }) => {
    if (url === '/auth/context') return ok(operatorContext(['cgo.ecosystem.access', 'cgo.subscription.view', 'cgo.subscription.renew', 'cgo.subscription.suspend', 'cgo.subscription.terminate']));
    if (url === '/subscriptions/s1') return ok({ id: 's1', subscription_number: 'SUB-1', tenant_id: 't1', status, grace_period_days: 7, items: [] });
    if (url === '/form-options/plans') return ok([]);
    return ok([]);
  });
  restore = mock.restore;
  renderApp('/subscriptions/s1');
  await screen.findByRole('heading', { name: 'SUB-1' });
  expect(screen.queryByRole('button', { name: 'Suspend' })).toBeNull();
  expect(screen.queryByRole('button', { name: 'Expire' })).toBeNull();
  expect(!!screen.queryByRole('button', { name: 'Renew' })).toBe(status === 'EXPIRED');
});
