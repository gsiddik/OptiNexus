// @vitest-environment jsdom
import { cleanup, render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { setLocale } from '../i18n';
import { SecretDialog } from './SecretDialog';

afterEach(() => {
  cleanup();
  setLocale('en');
});

describe('SecretDialog', () => {
  it('tells the operator to copy by hand when the browser refuses clipboard access, and still asks before discarding', async () => {
    const user = userEvent.setup();
    vi.spyOn(navigator.clipboard, 'writeText').mockRejectedValue(new Error('denied'));
    const onClose = vi.fn();
    render(<SecretDialog title="Client secret for X" clientId="onx_x" secret="abc123" onClose={onClose} />);
    await user.click(screen.getByRole('button', { name: 'Copy secret' }));
    expect(screen.getByText('Copy failed. Select the secret and copy it by hand.')).toBeTruthy();
    await user.click(screen.getByRole('button', { name: 'Close' }));
    expect(onClose).not.toHaveBeenCalled();
    await user.click(screen.getByRole('button', { name: 'Close without copying' }));
    expect(onClose).toHaveBeenCalledTimes(1);
  });

  it('is in Indonesian', () => {
    setLocale('id');
    render(<SecretDialog title="Client secret untuk X" clientId="onx_x" secret="abc123" onClose={() => undefined} />);
    expect(screen.getByRole('button', { name: 'Salin secret' })).toBeTruthy();
    expect(screen.getByText(/hanya ditampilkan sekarang/)).toBeTruthy();
  });
});
