import { AxiosError, type AxiosResponse, type InternalAxiosRequestConfig } from 'axios';
import { apiClient } from '../api/client';

export interface MockCall {
  method: string;
  url: string;
  params?: Record<string, unknown>;
  body?: unknown;
  headers: Record<string, string>;
}
export interface MockReply {
  status: number;
  data?: unknown;
  /** A request that never got an answer (offline, DNS, CORS): rejects without a response. */
  network?: boolean;
}
export type MockHandler = (call: MockCall) => MockReply | Promise<MockReply> | undefined;

export const ok = (data: unknown, extra: Record<string, unknown> = {}): MockReply => ({ status: 200, data: { success: true, data, ...extra } });
export const fail = (status: number, code: string, message = code, details: unknown = null): MockReply => ({
  status,
  data: { success: false, error: { code, message, details } },
});

/** Replaces the HTTP adapter of the app's axios client: no network, every request is recorded and answered by `handler`. */
export function installMockApi(handler: MockHandler) {
  const calls: MockCall[] = [];
  const previous = apiClient.defaults.adapter;
  apiClient.defaults.adapter = async (config: InternalAxiosRequestConfig) => {
    const headers: Record<string, string> = {};
    for (const [key, value] of Object.entries(config.headers.toJSON() as Record<string, unknown>)) headers[key.toLowerCase()] = String(value);
    let body: unknown = config.data;
    if (typeof body === 'string') {
      try {
        body = JSON.parse(body);
      } catch {
        // keep the raw string
      }
    }
    const call: MockCall = { method: (config.method ?? 'get').toUpperCase(), url: config.url ?? '', params: config.params, body, headers };
    calls.push(call);
    const reply = (await handler(call)) ?? fail(404, 'NOT_FOUND', `no mock for ${call.method} ${call.url}`);
    if (reply.network) throw new AxiosError('Network Error', 'ERR_NETWORK', config);
    const response: AxiosResponse = { data: reply.data, status: reply.status, statusText: String(reply.status), headers: {}, config };
    if (reply.status >= 200 && reply.status < 300) return response;
    throw new AxiosError(`Request failed with status code ${reply.status}`, 'ERR_BAD_REQUEST', config, null, response);
  };
  return {
    calls,
    restore: () => {
      apiClient.defaults.adapter = previous;
    },
  };
}

/** An operator session: the context the API returns for an account that holds the given permissions. */
export function operatorContext(permissions: string[] = ['cgo.ecosystem.access']) {
  return {
    user: { id: 'u-1', name: 'Ops Admin', email: 'ops@example.test', status: 'ACTIVE', mfa_enabled: false, email_verified_at: null, last_login_at: null, metadata: null },
    active_tenant_id: null,
    available_tenants: [],
    roles: [],
    effective_permissions: permissions.map((permission_key) => ({ permission_key, scope: 'GLOBAL' })),
  };
}
