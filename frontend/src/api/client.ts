import axios, { type AxiosError } from 'axios';
import type { ApiError, Pagination } from './types';

export const TOKEN_STORAGE_KEY = 'cgo_token';

export const apiClient = axios.create({
  baseURL: import.meta.env.VITE_API_BASE_URL ?? 'http://localhost:8000/api/v1',
  headers: { Accept: 'application/json' },
});

apiClient.interceptors.request.use((config) => {
  const token = localStorage.getItem(TOKEN_STORAGE_KEY);
  if (token) {
    config.headers.Authorization = `Bearer ${token}`;
  }
  return config;
});

export interface ApiEnvelope<T> {
  success: boolean;
  data: T;
  meta?: { pagination?: Pagination };
}

export class ApiRequestError extends Error {
  code: string;
  details: unknown;
  status: number | undefined;

  constructor(error: ApiError, status: number | undefined) {
    super(error.message);
    this.code = error.code;
    this.details = error.details;
    this.status = status;
  }
}

export function unwrapError(err: unknown): ApiRequestError {
  const axiosErr = err as AxiosError<{ error?: ApiError }>;
  if (axiosErr.response?.data?.error) {
    return new ApiRequestError(axiosErr.response.data.error, axiosErr.response.status);
  }
  return new ApiRequestError({ code: 'INTERNAL_ERROR', message: axiosErr.message ?? 'Unexpected error' }, axiosErr.response?.status);
}

let unauthorizedHandler: (() => void) | null = null;
export function onUnauthorized(handler: () => void) {
  unauthorizedHandler = handler;
}

apiClient.interceptors.response.use(
  (response) => response,
  (error: AxiosError) => {
    if (error.response?.status === 401 && unauthorizedHandler) {
      unauthorizedHandler();
    }
    return Promise.reject(error);
  },
);
