import { useCallback, useEffect, useState } from 'react';
import { describeError, unwrapError, type ApiEnvelope, type ApiRequestError } from './client';
import type { Pagination } from './types';

export function useApiList<T>(fetcher: (page: number) => Promise<ApiEnvelope<T[]>>, deps: unknown[] = []) {
  const [rows, setRows] = useState<T[]>([]);
  const [meta, setMeta] = useState<Pagination | undefined>(undefined);
  const [page, setPage] = useState(1);
  const [loading, setLoading] = useState(true);
  const [errorInfo, setErrorInfo] = useState<ApiRequestError | null>(null);
  const [reloadToken, setReloadToken] = useState(0);

  const reload = useCallback(() => setReloadToken((t) => t + 1), []);

  useEffect(() => {
    let cancelled = false;
    setLoading(true);
    setErrorInfo(null);
    fetcher(page)
      .then((envelope) => {
        if (cancelled) return;
        setRows(envelope.data);
        setMeta(envelope.meta?.pagination);
      })
      .catch((err) => {
        if (cancelled) return;
        setErrorInfo(unwrapError(err));
      })
      .finally(() => {
        if (!cancelled) setLoading(false);
      });
    return () => {
      cancelled = true;
    };
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [page, reloadToken, ...deps]);

  useEffect(() => {
    setPage(1);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, deps);

  return { rows, meta, page, setPage, loading, error: errorInfo ? describeError(errorInfo) : null, errorInfo, reload };
}

export function useApiResource<T>(fetcher: () => Promise<T>, deps: unknown[] = []) {
  const [data, setData] = useState<T | null>(null);
  const [loading, setLoading] = useState(true);
  const [errorInfo, setErrorInfo] = useState<ApiRequestError | null>(null);
  const [reloadToken, setReloadToken] = useState(0);

  const reload = useCallback(() => setReloadToken((t) => t + 1), []);

  useEffect(() => {
    let cancelled = false;
    setLoading(true);
    setErrorInfo(null);
    fetcher()
      .then((result) => {
        if (!cancelled) setData(result);
      })
      .catch((err) => {
        if (!cancelled) setErrorInfo(unwrapError(err));
      })
      .finally(() => {
        if (!cancelled) setLoading(false);
      });
    return () => {
      cancelled = true;
    };
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [reloadToken, ...deps]);

  return { data, loading, error: errorInfo ? describeError(errorInfo) : null, errorInfo, reload, setData };
}
