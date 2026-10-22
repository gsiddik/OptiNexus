import { describe, expect, it } from 'vitest';
import { formatDateTime } from './index';

describe('timestamp display', () => {
  it('preserves date-only calendar values and includes 24 hour time with seconds', () => {
    expect(formatDateTime('2026-10-22')).toBe('22/10/2026, 00:00:00');
    expect(formatDateTime('2026-10-22T23:05:09')).toBe('22/10/2026, 23:05:09');
    expect(formatDateTime(null)).toBe('—');
    expect(formatDateTime('invalid')).toBe('—');
  });
});
