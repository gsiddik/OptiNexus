import type { MessageKey } from '../../i18n';
import type { OidcClient, OidcClientCreate, OidcClientUpdate } from '../../api/types';

/** Limits the API enforces (OidcClientController): at most 20 URLs per list, 2048 characters each. */
export const MAX_URLS = 20;
export const MAX_URL_LENGTH = 2048;

export type UrlProblem = Extract<MessageKey, `oidc.err.${string}`>;

const LOOPBACK = new Set(['localhost', '127.0.0.1', '[::1]', '::1']);

/** Checks one redirect-style URL the way the API will, so the mistake is shown beside the field instead of after a round trip. */
export function urlProblem(raw: string): UrlProblem | null {
  const value = raw.trim();
  if (value === '') return 'oidc.err.required';
  if (value.length > MAX_URL_LENGTH) return 'oidc.err.tooLong';
  if (value.includes('*')) return 'oidc.err.wildcard';
  let url: URL;
  try {
    url = new URL(value);
  } catch {
    return 'oidc.err.invalid';
  }
  if (url.protocol !== 'http:' && url.protocol !== 'https:') return 'oidc.err.scheme';
  if (!url.hostname) return 'oidc.err.invalid';
  if (url.username || url.password) return 'oidc.err.credentials';
  if (value.includes('#')) return 'oidc.err.fragment';
  return null;
}

/** A well-formed URL that is still a bad idea: plain http outside the local machine. Shown as a warning, never blocks. */
export function isInsecureUrl(raw: string): boolean {
  try {
    const url = new URL(raw.trim());
    return url.protocol === 'http:' && !LOOPBACK.has(url.hostname);
  } catch {
    return false;
  }
}

/** One problem (or none) per row of a URL list; duplicates are flagged on the later row. `required` rows are the ones that must not be empty. */
export function listProblems(values: string[], opts: { min: number; max?: number }): { rows: (UrlProblem | null)[]; list: UrlProblem | null } {
  const seen = new Set<string>();
  const rows = values.map((value) => {
    if (opts.min === 0 && value.trim() === '') return null; // an empty row in an optional list is ignored
    const problem = urlProblem(value);
    if (problem) return problem;
    const key = value.trim();
    if (seen.has(key)) return 'oidc.err.duplicate' as const;
    seen.add(key);
    return null;
  });
  const filled = values.filter((v) => v.trim() !== '').length;
  let list: UrlProblem | null = values.length === 0 || (opts.min > 0 && filled === 0) ? 'oidc.err.minOne' : null;
  if (opts.max !== undefined && values.length > opts.max) list = 'oidc.err.tooMany';
  return { rows, list };
}

export interface OidcFormState {
  application_id: string;
  name: string;
  confidential: boolean;
  require_pkce: boolean;
  /** The user accepted the risk of leaving PKCE off. */
  pkce_ack: boolean;
  redirect_uris: string[];
  post_logout_redirect_uris: string[];
  launch_url: string;
  backchannel_logout_uri: string;
}

/** PKCE is on from the start: the safe choice is the default and a public client cannot turn it off at all. */
export function emptyForm(applicationId = ''): OidcFormState {
  return {
    application_id: applicationId,
    name: '',
    confidential: true,
    require_pkce: true,
    pkce_ack: false,
    redirect_uris: [''],
    post_logout_redirect_uris: [],
    launch_url: '',
    backchannel_logout_uri: '',
  };
}

export function formFromClient(client: OidcClient): OidcFormState {
  return {
    application_id: client.application_id,
    name: client.name,
    confidential: client.confidential,
    require_pkce: client.require_pkce,
    pkce_ack: false,
    redirect_uris: client.redirect_uris.length ? [...client.redirect_uris] : [''],
    post_logout_redirect_uris: [...client.post_logout_redirect_uris],
    launch_url: client.launch_url ?? '',
    backchannel_logout_uri: client.backchannel_logout_uri ?? '',
  };
}

export interface OidcFormProblems {
  application?: MessageKey;
  name?: MessageKey;
  redirects: (UrlProblem | null)[];
  redirectsList?: UrlProblem;
  postLogout: (UrlProblem | null)[];
  postLogoutList?: UrlProblem;
  launch?: UrlProblem;
  backchannel?: UrlProblem;
  pkceAck?: MessageKey;
}

/**
 * Whether turning PKCE off needs the explicit acknowledgement: always on create, and on edit only when this change is what turns it off
 * (a client that already runs without it can still be edited; the form keeps telling the operator to turn it on).
 */
export function pkceNeedsAck(form: OidcFormState, original?: OidcClient): boolean {
  if (form.require_pkce) return false;
  return !original || original.require_pkce;
}

export function validateForm(form: OidcFormState, mode: 'create' | 'edit', original?: OidcClient): { problems: OidcFormProblems; valid: boolean } {
  const redirects = listProblems(form.redirect_uris, { min: 1, max: MAX_URLS });
  const postLogout = listProblems(form.post_logout_redirect_uris, { min: 0, max: MAX_URLS });
  const problems: OidcFormProblems = { redirects: redirects.rows, postLogout: postLogout.rows };
  if (mode === 'create' && !form.application_id) problems.application = 'oidc.err.applicationRequired';
  if (!form.name.trim()) problems.name = 'oidc.err.nameRequired';
  if (redirects.list) problems.redirectsList = redirects.list;
  if (postLogout.list === 'oidc.err.tooMany') problems.postLogoutList = postLogout.list;
  if (form.launch_url.trim() && urlProblem(form.launch_url)) problems.launch = urlProblem(form.launch_url) ?? undefined;
  if (form.backchannel_logout_uri.trim() && urlProblem(form.backchannel_logout_uri)) problems.backchannel = urlProblem(form.backchannel_logout_uri) ?? undefined;
  if (pkceNeedsAck(form, original) && !form.pkce_ack) problems.pkceAck = 'oidc.err.ackRequired';

  const valid =
    !problems.application && !problems.name && !problems.redirectsList && !problems.postLogoutList && !problems.launch && !problems.backchannel && !problems.pkceAck &&
    problems.redirects.every((p) => p === null) && problems.postLogout.every((p) => p === null);
  return { problems, valid };
}

const trimmed = (values: string[]) => values.map((v) => v.trim()).filter((v) => v !== '');

export function toUpdatePayload(form: OidcFormState): OidcClientUpdate {
  return {
    name: form.name.trim(),
    require_pkce: form.confidential ? form.require_pkce : true,
    redirect_uris: trimmed(form.redirect_uris),
    post_logout_redirect_uris: trimmed(form.post_logout_redirect_uris),
    launch_url: form.launch_url.trim() || null,
    backchannel_logout_uri: form.backchannel_logout_uri.trim() || null,
  };
}

export function toCreatePayload(form: OidcFormState): OidcClientCreate {
  return { application_id: form.application_id, confidential: form.confidential, ...toUpdatePayload(form) };
}

/** The API answers 422 with `details` like {"redirect_uris.2": ["..."], "name": ["..."]}; this groups them by the field they belong to. */
export function serverFieldErrors(details: unknown): { fields: Record<string, string[]>; rows: Record<string, Record<number, string[]>> } {
  const fields: Record<string, string[]> = {};
  const rows: Record<string, Record<number, string[]>> = {};
  if (!details || typeof details !== 'object') return { fields, rows };
  for (const [key, messages] of Object.entries(details as Record<string, unknown>)) {
    const list = Array.isArray(messages) ? messages.map(String) : [String(messages)];
    const match = key.match(/^(\w+)\.(\d+)$/);
    if (match) {
      (rows[match[1]] ??= {})[Number(match[2])] = list;
    } else {
      fields[key] = list;
    }
  }
  return { fields, rows };
}
