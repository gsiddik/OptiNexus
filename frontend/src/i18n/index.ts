import { useCallback, useSyncExternalStore } from 'react';
import { en } from './en';
import { id } from './id';

/**
 * Minimal bilingual (English / Indonesian) text lookup. A module-level store instead of a React provider so that code outside
 * components (the API layer, error boundaries, hooks) translates with the same locale the screen shows.
 * Pages written before this existed are still English only; everything that was added or changed since uses `t()`.
 */
export type Locale = 'en' | 'id';
export type MessageKey = keyof typeof en;
export type MessageParams = Record<string, string | number>;

export const LOCALES: readonly Locale[] = ['en', 'id'];
export const LOCALE_STORAGE_KEY = 'optinexus.locale';

const dictionaries: Record<Locale, Record<string, string>> = { en, id };

function readStored(): Locale | null {
  try {
    const stored = window.localStorage.getItem(LOCALE_STORAGE_KEY);
    return stored === 'en' || stored === 'id' ? stored : null;
  } catch {
    return null;
  }
}

function detect(): Locale {
  const stored = readStored();
  if (stored) return stored;
  const preferred = typeof navigator !== 'undefined' ? (navigator.languages?.[0] ?? navigator.language ?? '') : '';
  return preferred.toLowerCase().startsWith('id') ? 'id' : 'en';
}

let current: Locale = detect();
const listeners = new Set<() => void>();

function applyDocumentLanguage() {
  if (typeof document !== 'undefined') document.documentElement.lang = current;
}
applyDocumentLanguage();

export function getLocale(): Locale {
  return current;
}

export function setLocale(next: Locale) {
  if (next === current) return;
  current = next;
  try {
    window.localStorage.setItem(LOCALE_STORAGE_KEY, next);
  } catch {
    // Blocked storage: the choice just is not remembered.
  }
  applyDocumentLanguage();
  listeners.forEach((listener) => listener());
}

function subscribe(listener: () => void) {
  listeners.add(listener);
  return () => {
    listeners.delete(listener);
  };
}

export function hasMessage(key: string): key is MessageKey {
  return Object.prototype.hasOwnProperty.call(en, key);
}

function interpolate(template: string, params?: MessageParams): string {
  if (!params) return template;
  return template.replace(/\{(\w+)\}/g, (whole, name: string) => (name in params ? String(params[name]) : whole));
}

/** Looks a key up in the current language, then in English, then returns the key itself (never throws, never blank). */
export function translate(key: string, params?: MessageParams): string {
  const text = dictionaries[current][key] ?? dictionaries.en[key] ?? key;
  return interpolate(text, params);
}

export const t = (key: MessageKey, params?: MessageParams) => translate(key, params);

/** Re-renders the component when the language changes. `t` is checked against the English keys at compile time. */
export function useI18n() {
  const locale = useSyncExternalStore(subscribe, getLocale, getLocale);
  const tr = useCallback((key: MessageKey, params?: MessageParams) => translate(key, params), [locale]); // eslint-disable-line react-hooks/exhaustive-deps
  return { locale, setLocale, t: tr, translate };
}

const INTL_LOCALE: Record<Locale, string> = { en: 'en-GB', id: 'id-ID' };

/** Display in the browser timezone, consistently in both interface languages. Date-only values stay on their calendar day. */
export function formatDateTime(value: string | null | undefined): string {
  if (!value) return '—';
  const date = /^\d{4}-\d{2}-\d{2}$/.test(value) ? new Date(`${value}T00:00:00`) : new Date(value);
  if (Number.isNaN(date.getTime())) return '—';
  const pad = (n: number) => String(n).padStart(2, '0');
  return `${pad(date.getDate())}/${pad(date.getMonth() + 1)}/${date.getFullYear()}, ${pad(date.getHours())}:${pad(date.getMinutes())}:${pad(date.getSeconds())}`;
}

/** "5 minutes ago" / "5 menit lalu" in the language of the screen. */
export function formatRelative(value: string | null | undefined, now: number = Date.now()): string {
  if (!value) return '—';
  const time = new Date(value).getTime();
  if (Number.isNaN(time)) return value;
  const seconds = Math.round((time - now) / 1000);
  if (Math.abs(seconds) < 45) return translate('gw.relative.now');
  const formatter = new Intl.RelativeTimeFormat(INTL_LOCALE[current], { numeric: 'auto' });
  const steps: [Intl.RelativeTimeFormatUnit, number][] = [['minute', 60], ['hour', 3600], ['day', 86400], ['month', 2_592_000], ['year', 31_536_000]];
  let unit: Intl.RelativeTimeFormatUnit = 'minute';
  let size = 60;
  for (const [candidate, candidateSize] of steps) {
    if (Math.abs(seconds) >= candidateSize) {
      unit = candidate;
      size = candidateSize;
    }
  }
  return formatter.format(Math.round(seconds / size), unit);
}
