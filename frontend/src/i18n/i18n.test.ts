// @vitest-environment jsdom
import { beforeEach, describe, expect, it } from 'vitest';
import { en } from './en';
import { id } from './id';
import { getLocale, hasMessage, LOCALE_STORAGE_KEY, setLocale, translate } from './index';

const placeholders = (text: string) => [...text.matchAll(/\{(\w+)\}/g)].map((m) => m[1]).sort();

describe('dictionaries', () => {
  it('have the same keys in English and Indonesian', () => {
    expect(Object.keys(id).sort()).toEqual(Object.keys(en).sort());
  });

  it('never leave a text empty', () => {
    for (const [key, text] of [...Object.entries(en), ...Object.entries(id)]) expect(text.trim(), key).not.toBe('');
  });

  it('use the same placeholders in both languages', () => {
    for (const key of Object.keys(en) as (keyof typeof en)[]) expect(placeholders(id[key]), key).toEqual(placeholders(en[key]));
  });

  it('actually translate: no sentence is left in English in the Indonesian dictionary', () => {
    const productNames = new Set(['login.title']); // the console's name is the same in both languages
    const copies = (Object.keys(en) as (keyof typeof en)[]).filter((key) => en[key] === id[key] && en[key].split(' ').length > 2 && !productNames.has(key));
    expect(copies).toEqual([]);
  });
});

describe('translate', () => {
  beforeEach(() => {
    window.localStorage.clear();
    setLocale('en');
  });

  it('interpolates placeholders and leaves unknown ones visible', () => {
    expect(translate('common.page', { current: 2, last: 5, total: 41 })).toBe('Page 2 of 5 (41 total)');
    expect(translate('common.page', { current: 2 })).toContain('{last}');
  });

  it('switches language, remembers the choice and sets the document language', () => {
    setLocale('id');
    expect(translate('shell.logout')).toBe('Keluar');
    expect(window.localStorage.getItem(LOCALE_STORAGE_KEY)).toBe('id');
    expect(document.documentElement.lang).toBe('id');
    expect(getLocale()).toBe('id');
  });

  it('shows the key itself for an unknown key rather than a blank', () => {
    expect(translate('no.such.key')).toBe('no.such.key');
    expect(hasMessage('no.such.key')).toBe(false);
    expect(hasMessage('shell.logout')).toBe(true);
  });
});
