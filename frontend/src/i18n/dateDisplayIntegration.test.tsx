// @vitest-environment jsdom
import { cleanup, fireEvent, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { afterEach, expect, it } from 'vitest';
import { TOKEN_STORAGE_KEY } from '../api/client';
import { installMockApi, ok, operatorContext } from '../test/mockApi';
import { renderApp } from '../test/renderApp';

let restore = () => {};
afterEach(() => { cleanup(); restore(); });

it('keeps native date input values editable while formatting dates for display', async () => {
  localStorage.setItem(TOKEN_STORAGE_KEY, 'tok');
  restore = installMockApi(({ url }) => {
    if (url === '/auth/context') return ok(operatorContext(['cgo.ecosystem.access', 'cgo.pricing.view', 'cgo.pricing.create']));
    return ok([]);
  }).restore;
  renderApp('/prices');
  await userEvent.click(await screen.findByRole('button', { name: '+ New Price' }));
  const input = document.querySelector<HTMLInputElement>('input[type="date"]')!;
  expect(input).toBeTruthy();
  expect(input.value).toMatch(/^\d{4}-\d{2}-\d{2}$/);
  fireEvent.change(input, { target: { value: '2026-11-01' } });
  expect(input.value).toBe('2026-11-01');
});
