// @vitest-environment jsdom
import { afterEach, describe, expect, it, vi } from 'vitest';
import { apiClient, describeError, ECOSYSTEM_ACCESS_REQUIRED, setAuthHandlers, TOKEN_STORAGE_KEY, unwrapError } from './client';
import { setLocale } from '../i18n';
import { fail, installMockApi, ok } from '../test/mockApi';

/** The error a request is expected to fail with. */
const failure = (request: Promise<unknown>) =>
  request.then(
    () => {
      throw new Error('the request was expected to fail');
    },
    (err: unknown) => unwrapError(err),
  );

afterEach(() => {
  setAuthHandlers({});
  window.localStorage.clear();
  setLocale('en');
});

describe('request ids', () => {
  it('sends a fresh X-Request-Id (also as X-Correlation-Id) with every request, and the bearer token when signed in', async () => {
    window.localStorage.setItem(TOKEN_STORAGE_KEY, 'tok-123');
    const mock = installMockApi(() => ok({}));
    await apiClient.get('/a');
    await apiClient.get('/b');
    mock.restore();
    const [first, second] = mock.calls;
    expect(first.headers['x-request-id']).toMatch(/\S{8,}/);
    expect(first.headers['x-correlation-id']).toBe(first.headers['x-request-id']);
    expect(second.headers['x-request-id']).not.toBe(first.headers['x-request-id']);
    expect(first.headers.authorization).toBe('Bearer tok-123');
  });
});

describe('unwrapError', () => {
  it('keeps the API error code, message and the id of the failing request', async () => {
    const mock = installMockApi(() => fail(409, 'ROLE_IN_USE', 'Role is assigned.'));
    const err = await failure(apiClient.get('/roles/1'));
    mock.restore();
    expect(err.code).toBe('ROLE_IN_USE');
    expect(err.message).toBe('Role is assigned.');
    expect(err.status).toBe(409);
    expect(err.requestId).toBe(mock.calls[0].headers['x-request-id']);
    expect(describeError(err)).toContain(`(Ref: ${err.requestId?.split('-')[0]})`);
  });

  it('classifies a request that got no answer as a network error, in the language of the screen', async () => {
    const mock = installMockApi(() => ({ status: 0, network: true }));
    const en = await failure(apiClient.get('/x'));
    setLocale('id');
    const id = await failure(apiClient.get('/x'));
    mock.restore();
    expect(en.kind).toBe('network');
    expect(en.message).toMatch(/Cannot reach the server/);
    expect(id.message).toMatch(/Tidak dapat menghubungi server/);
  });

  it('does not trust an answer that is not the API envelope (proxy error page)', async () => {
    const mock = installMockApi(() => ({ status: 502, data: '<html>Bad gateway</html>' }));
    const err = await failure(apiClient.get('/x'));
    mock.restore();
    expect(err.code).toBe('HTTP_502');
    expect(err.kind).toBe('server');
    expect(err.message).not.toContain('<html>');
  });

  it('maps statuses to kinds', () => {
    const kind = (status: number, code: string) => unwrapError(Object.assign(new Error(), { isAxiosError: true, response: { status, data: { error: { code, message: code } } } })).kind;
    expect(kind(401, 'UNAUTHENTICATED')).toBe('session');
    expect(kind(403, 'UNAUTHORIZED')).toBe('forbidden');
    expect(kind(403, ECOSYSTEM_ACCESS_REQUIRED)).toBe('ecosystem');
    expect(kind(404, 'NOT_FOUND')).toBe('notfound');
    expect(kind(422, 'VALIDATION_ERROR')).toBe('validation');
    expect(kind(429, 'RATE_LIMITED')).toBe('ratelimit');
    expect(kind(500, 'INTERNAL_ERROR')).toBe('server');
  });
});

describe('response handlers', () => {
  it('calls the ecosystem handler on 403 ECOSYSTEM_ACCESS_REQUIRED, whichever request it is', async () => {
    const onEcosystemDenied = vi.fn();
    const onUnauthorized = vi.fn();
    setAuthHandlers({ onEcosystemDenied, onUnauthorized });
    const mock = installMockApi(() => fail(403, ECOSYSTEM_ACCESS_REQUIRED));
    await apiClient.post('/auth/login', {}).catch(() => undefined);
    await apiClient.get('/tenants').catch(() => undefined);
    mock.restore();
    expect(onEcosystemDenied).toHaveBeenCalledTimes(2);
    expect(onUnauthorized).not.toHaveBeenCalled();
  });

  it('treats 401 as an ended session, except for the sign-in request itself (wrong password)', async () => {
    const onUnauthorized = vi.fn();
    setAuthHandlers({ onUnauthorized });
    const mock = installMockApi(() => fail(401, 'UNAUTHENTICATED'));
    await apiClient.post('/auth/login', {}).catch(() => undefined);
    expect(onUnauthorized).not.toHaveBeenCalled();
    await apiClient.get('/tenants').catch(() => undefined);
    mock.restore();
    expect(onUnauthorized).toHaveBeenCalledTimes(1);
  });

  it('leaves an ordinary permission 403 to the page', async () => {
    const onEcosystemDenied = vi.fn();
    setAuthHandlers({ onEcosystemDenied });
    const mock = installMockApi(() => fail(403, 'UNAUTHORIZED'));
    await apiClient.get('/tenants').catch(() => undefined);
    mock.restore();
    expect(onEcosystemDenied).not.toHaveBeenCalled();
  });
});
