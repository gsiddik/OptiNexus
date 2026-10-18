import { describe, expect, it } from 'vitest';
import type { OidcClient } from '../../api/types';
import { emptyForm, formFromClient, isInsecureUrl, listProblems, pkceNeedsAck, serverFieldErrors, toCreatePayload, toUpdatePayload, urlProblem, validateForm } from './oidcForm';

const client: OidcClient = {
  id: 'c1', application_id: 'a1', client_id: 'onx_abc', name: 'Entry', confidential: true, redirect_uris: ['https://entry.example.com/cb'],
  post_logout_redirect_uris: [], launch_url: null, backchannel_logout_uri: null, require_pkce: true, status: 'ACTIVE', created_at: '2026-01-01T00:00:00Z',
};

describe('urlProblem', () => {
  it.each([
    ['https://app.example.com/auth/callback', null],
    ['http://localhost:3000/cb', null],
    ['', 'oidc.err.required'],
    ['   ', 'oidc.err.required'],
    ['app.example.com/cb', 'oidc.err.invalid'],
    ['ftp://app.example.com/cb', 'oidc.err.scheme'],
    ['javascript:alert(1)', 'oidc.err.scheme'],
    ['https://*.example.com/cb', 'oidc.err.wildcard'],
    ['https://app.example.com/*', 'oidc.err.wildcard'],
    ['https://app.example.com/cb#frag', 'oidc.err.fragment'],
    ['https://user:pw@app.example.com/cb', 'oidc.err.credentials'],
    [`https://app.example.com/${'a'.repeat(2050)}`, 'oidc.err.tooLong'],
  ])('%s -> %s', (value, expected) => {
    expect(urlProblem(value)).toBe(expected);
  });

  it('warns about plain http except on the local machine', () => {
    expect(isInsecureUrl('http://app.example.com/cb')).toBe(true);
    expect(isInsecureUrl('http://localhost:5173/cb')).toBe(false);
    expect(isInsecureUrl('http://127.0.0.1/cb')).toBe(false);
    expect(isInsecureUrl('https://app.example.com/cb')).toBe(false);
  });
});

describe('listProblems', () => {
  it('flags the second copy of a URL, and needs one redirect URL', () => {
    const { rows } = listProblems(['https://a.test/cb', 'https://a.test/cb', 'https://b.test/cb'], { min: 1 });
    expect(rows).toEqual([null, 'oidc.err.duplicate', null]);
    expect(listProblems([], { min: 1 }).list).toBe('oidc.err.minOne');
    expect(listProblems([''], { min: 1 }).list).toBe('oidc.err.minOne');
  });

  it('ignores an empty row in an optional list and caps the length', () => {
    expect(listProblems([''], { min: 0 }).rows).toEqual([null]);
    expect(listProblems(Array.from({ length: 21 }, (_, i) => `https://a.test/${i}`), { min: 1, max: 20 }).list).toBe('oidc.err.tooMany');
  });
});

describe('PKCE default and acknowledgement', () => {
  it('a new client starts with PKCE required', () => {
    expect(emptyForm().require_pkce).toBe(true);
    expect(toCreatePayload({ ...emptyForm('a1'), name: 'X', redirect_uris: ['https://a.test/cb'] }).require_pkce).toBe(true);
  });

  it('turning PKCE off on create needs the acknowledgement, and without it the form is invalid', () => {
    const form = { ...emptyForm('a1'), name: 'X', redirect_uris: ['https://a.test/cb'], require_pkce: false };
    expect(pkceNeedsAck(form)).toBe(true);
    const refused = validateForm(form, 'create');
    expect(refused.valid).toBe(false);
    expect(refused.problems.pkceAck).toBe('oidc.err.ackRequired');
    expect(validateForm({ ...form, pkce_ack: true }, 'create').valid).toBe(true);
  });

  it('a public client always sends PKCE required, whatever the form says', () => {
    const form = { ...emptyForm('a1'), name: 'SPA', confidential: false, require_pkce: false, redirect_uris: ['https://a.test/cb'] };
    expect(toCreatePayload(form).require_pkce).toBe(true);
  });

  it('on edit, only the change that switches PKCE off needs the acknowledgement', () => {
    expect(pkceNeedsAck({ ...formFromClient(client), require_pkce: false }, client)).toBe(true);
    const off = { ...client, require_pkce: false };
    expect(pkceNeedsAck(formFromClient(off), off)).toBe(false);
    expect(validateForm(formFromClient(off), 'edit', off).valid).toBe(true);
  });
});

describe('payloads', () => {
  it('sends trimmed exact URLs, drops empty rows and turns blank optionals into null', () => {
    const payload = toCreatePayload({
      ...emptyForm('a1'), name: '  Entry  ', redirect_uris: [' https://a.test/cb ', ''], post_logout_redirect_uris: ['', 'https://a.test/bye'],
      launch_url: '  ', backchannel_logout_uri: 'https://a.test/bc',
    });
    expect(payload).toEqual({
      application_id: 'a1', name: 'Entry', confidential: true, require_pkce: true,
      redirect_uris: ['https://a.test/cb'], post_logout_redirect_uris: ['https://a.test/bye'], launch_url: null, backchannel_logout_uri: 'https://a.test/bc',
    });
    expect(toUpdatePayload(formFromClient(client))).not.toHaveProperty('application_id');
    expect(toUpdatePayload(formFromClient(client))).not.toHaveProperty('confidential');
  });

  it('requires an application and a name on create', () => {
    const { problems, valid } = validateForm({ ...emptyForm(), redirect_uris: ['https://a.test/cb'] }, 'create');
    expect(valid).toBe(false);
    expect(problems.application).toBe('oidc.err.applicationRequired');
    expect(problems.name).toBe('oidc.err.nameRequired');
  });
});

describe('serverFieldErrors', () => {
  it('groups API validation messages by field and by row', () => {
    const { fields, rows } = serverFieldErrors({ name: ['Required.'], 'redirect_uris.1': ['Must be a valid URL.'] });
    expect(fields.name).toEqual(['Required.']);
    expect(rows.redirect_uris[1]).toEqual(['Must be a valid URL.']);
    expect(serverFieldErrors(null)).toEqual({ fields: {}, rows: {} });
  });
});
