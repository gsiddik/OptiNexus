import axios, { type AxiosError } from 'axios';
import { storage } from '../auth/storage';
import { hasMessage, translate } from '../i18n';
import type { ApiError, Pagination } from './types';

export const TOKEN_STORAGE_KEY = 'cgo_token';

/** The code the API answers (403) when a signed-in account is not allowed to use the ecosystem portal. */
export const ECOSYSTEM_ACCESS_REQUIRED = 'ECOSYSTEM_ACCESS_REQUIRED';

const BASE_URL: string = import.meta.env.VITE_API_BASE_URL ?? 'http://localhost:8000/api/v1';

export const apiClient = axios.create({
  baseURL: BASE_URL,
  headers: { Accept: 'application/json' },
  timeout: 30_000,
});

/** A fresh id for one request; the API records `X-Request-Id` / `X-Correlation-Id` in its audit log, so the id shown on an error leads to the server side. */
export function newRequestId(): string {
  const cryptoApi = globalThis.crypto;
  if (cryptoApi?.randomUUID) return cryptoApi.randomUUID();
  return `${Date.now().toString(36)}-${Math.random().toString(36).slice(2, 10)}`;
}

apiClient.interceptors.request.use((config) => {
  const token = storage.get(TOKEN_STORAGE_KEY);
  if (token) {
    config.headers.Authorization = `Bearer ${token}`;
  }
  const requestId = newRequestId();
  config.headers.set('X-Request-Id', requestId);
  config.headers.set('X-Correlation-Id', requestId);
  return config;
});

export interface ApiEnvelope<T> {
  success: boolean;
  data: T;
  meta?: { pagination?: Pagination };
}

export type ApiErrorKind = 'network' | 'timeout' | 'session' | 'ecosystem' | 'forbidden' | 'notfound' | 'validation' | 'ratelimit' | 'server' | 'other';

export class ApiRequestError extends Error {
  code: string;
  details: unknown;
  status: number | undefined;
  /** Id of the request that failed (sent as X-Request-Id); quote it to support. */
  requestId: string | undefined;

  constructor(error: ApiError, status: number | undefined, requestId?: string) {
    super(error.message);
    this.code = error.code;
    this.details = error.details;
    this.status = status;
    this.requestId = requestId;
  }

  get kind(): ApiErrorKind {
    if (this.code === 'NETWORK_ERROR') return 'network';
    if (this.code === 'TIMEOUT') return 'timeout';
    if (this.code === ECOSYSTEM_ACCESS_REQUIRED) return 'ecosystem';
    if (this.status === 401 || this.code === 'UNAUTHENTICATED') return 'session';
    if (this.status === 403) return 'forbidden';
    if (this.status === 404) return 'notfound';
    if (this.status === 422 || this.code === 'VALIDATION_ERROR') return 'validation';
    if (this.status === 429 || this.code === 'RATE_LIMITED') return 'ratelimit';
    if (this.status !== undefined && this.status >= 500) return 'server';
    return 'other';
  }
}

function requestIdOf(err: AxiosError): string | undefined {
  const fromServer = err.response?.headers?.['x-request-id'];
  if (typeof fromServer === 'string' && fromServer) return fromServer;
  const sent = err.config?.headers?.get?.('X-Request-Id');
  return typeof sent === 'string' ? sent : undefined;
}

/** Turns anything a request can throw into one error type. Messages the client makes up (network, timeout, no JSON) are in the screen's language. */
/** A seat refusal (SEAT_* codes, GOVERNANCE_CONTRACTS.md 2d) is shown in the screen's language; the code and details stay as sent. */
function localized(error: ApiError): ApiError {
  const key = `seats.error.${error.code}`;
  return error.code.startsWith('SEAT_') && hasMessage(key) ? { ...error, message: translate(key) } : error;
}

export function unwrapError(err: unknown): ApiRequestError {
  if (err instanceof ApiRequestError) return err;
  const axiosErr = err as AxiosError<{ error?: ApiError }>;
  const requestId = axiosErr?.isAxiosError ? requestIdOf(axiosErr) : undefined;
  const status = axiosErr?.response?.status;

  if (axiosErr?.response?.data?.error) {
    return new ApiRequestError(localized(axiosErr.response.data.error), status, requestId);
  }
  if (axiosErr?.isAxiosError && !axiosErr.response) {
    if (axiosErr.code === 'ECONNABORTED' || axiosErr.code === 'ETIMEDOUT') {
      return new ApiRequestError({ code: 'TIMEOUT', message: translate('error.timeout') }, undefined, requestId);
    }
    return new ApiRequestError({ code: 'NETWORK_ERROR', message: translate('error.network') }, undefined, requestId);
  }
  if (status !== undefined) {
    // An answer that is not the API's own envelope (a proxy page, an HTML 502).
    const message = status >= 500 ? translate('error.server') : status === 404 ? translate('error.notFound') : translate('error.unexpected');
    return new ApiRequestError({ code: `HTTP_${status}`, message }, status, requestId);
  }
  return new ApiRequestError({ code: 'INTERNAL_ERROR', message: translate('error.unexpected') }, undefined, requestId);
}

/** The message plus its reference, for places that show one line. */
export function describeError(err: unknown): string {
  const e = unwrapError(err);
  return e.requestId ? `${e.message} (${translate('error.ref')}: ${shortRef(e.requestId)})` : e.message;
}

/** First segment of a request id: short enough to read out loud, long enough to find in the audit log. */
export function shortRef(id: string): string {
  return id.split('-')[0] ?? id;
}

interface AuthHandlers {
  onUnauthorized: () => void;
  onEcosystemDenied: () => void;
}
let handlers: Partial<AuthHandlers> = {};

/** The session layer registers what happens on a 401 (session ended) and on 403 ECOSYSTEM_ACCESS_REQUIRED. */
export function setAuthHandlers(next: Partial<AuthHandlers>) {
  handlers = next;
}

apiClient.interceptors.response.use(
  (response) => response,
  (error: AxiosError<{ error?: ApiError }>) => {
    const status = error.response?.status;
    const code = error.response?.data?.error?.code;
    const isLogin = error.config?.url === '/auth/login';
    if (status === 403 && code === ECOSYSTEM_ACCESS_REQUIRED) {
      handlers.onEcosystemDenied?.();
    } else if (status === 401 && !isLogin) {
      handlers.onUnauthorized?.();
    }
    return Promise.reject(error);
  },
);

/**
 * Revokes a token on the server without going through the interceptors above (so a failure can never start another logout).
 * Allowed even for an account that lost portal access: a token must always be revocable. Never throws.
 */
export async function revokeToken(token: string): Promise<void> {
  try {
    await axios.post(`${BASE_URL}/auth/logout`, null, {
      headers: { Accept: 'application/json', Authorization: `Bearer ${token}`, 'X-Request-Id': newRequestId() },
      timeout: 8_000,
    });
  } catch {
    // Offline or already expired: the local session is gone either way.
  }
}
