/**
 * A page of the portal is a separate file (route-level code splitting). After a new release the old file names are gone, and a tab
 * that was open during the release asks for files that no longer exist. That is a "chunk load error": the cure is a reload, which
 * fetches the new index.html and with it the new file names.
 */
const CHUNK_ERROR_PATTERNS = [
  /failed to fetch dynamically imported module/i, // Chromium
  /error loading dynamically imported module/i, // Firefox
  /importing a module script failed/i, // Safari
  /loading (css )?chunk [\w-]+ failed/i, // webpack style, kept for tooling that rewrites imports
  /unable to preload css/i, // Vite's CSS preload helper
];

export function isChunkLoadError(err: unknown): boolean {
  if (!err) return false;
  const name = err instanceof Error ? err.name : '';
  const message = err instanceof Error ? err.message : typeof err === 'string' ? err : '';
  return name === 'ChunkLoadError' || CHUNK_ERROR_PATTERNS.some((pattern) => pattern.test(message));
}

const RELOAD_KEY = 'optinexus.chunk-reload-at';
/** One automatic reload per minute at most: if the fresh page fails the same way, the user gets the message instead of a loop. */
export const RELOAD_WINDOW_MS = 60_000;

export function canAutoReload(now = Date.now()): boolean {
  // Offline, a reload would only replace the portal with the browser's "no internet" page: show the message instead.
  if (typeof navigator !== 'undefined' && navigator.onLine === false) return false;
  try {
    const last = Number(window.sessionStorage.getItem(RELOAD_KEY));
    return !(last > 0 && now - last < RELOAD_WINDOW_MS);
  } catch {
    return false; // no way to remember that we already tried: never risk a loop
  }
}

export function markAutoReload(now = Date.now()) {
  try {
    window.sessionStorage.setItem(RELOAD_KEY, String(now));
  } catch {
    // see canAutoReload
  }
}

/** Reloads once for a stale-chunk failure announced by the bundler; a second failure within the window is left to the error screen. */
export function installChunkErrorHandling(target: Window = window, reload: () => void = () => window.location.reload()) {
  const onPreloadError = (event: Event) => {
    if (!canAutoReload()) return; // not prevented: the import rejects and the route error screen offers the reload button
    markAutoReload();
    event.preventDefault();
    reload();
  };
  target.addEventListener('vite:preloadError', onPreloadError);
  return () => target.removeEventListener('vite:preloadError', onPreloadError);
}
