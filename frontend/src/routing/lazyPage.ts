import { lazy, type ComponentType, type LazyExoticComponent } from 'react';

export type LazyPage = LazyExoticComponent<ComponentType> & {
  /** Starts downloading the page file without rendering it (a failure is left for the moment the page is actually opened). */
  preload: () => void;
};

/**
 * A page loaded on first visit instead of with the first screen. The pages export named components, so the loader picks the name;
 * a failed download (stale file after a release, dropped connection) rejects here and is caught by the route error boundary.
 */
export function lazyPage<K extends string>(loader: () => Promise<Record<K, ComponentType>>, name: K): LazyPage {
  let loading: Promise<{ default: ComponentType }> | undefined;
  let loaded: { default: ComponentType } | undefined;
  const load = () =>
    (loading ??= loader()
      .then((module) => {
        // When the bundler's preload failed and its error event was cancelled (to reload the page), the import resolves with nothing:
        // report that as the chunk failure it is, so the screen shows the reload message instead of a TypeError.
        if (!module) throw Object.assign(new Error('Failed to fetch dynamically imported module'), { name: 'ChunkLoadError' });
        loaded = { default: module[name] };
        return loaded;
      })
      .catch((error: unknown) => {
        loading = undefined; // the next attempt downloads again
        throw error;
      }));
  // A file that has already arrived (preloaded while the session was being checked) is handed over synchronously, so the page is drawn
  // at once; a promise, even a settled one, would make React draw the loading state first and hold it for a third of a second.
  const ready = (): Promise<{ default: ComponentType }> => (loaded ? ({ then: (resolve: (value: { default: ComponentType }) => void) => resolve(loaded!) } as unknown as Promise<{ default: ComponentType }>) : load());
  const page = lazy(ready) as LazyPage;
  page.preload = () => {
    load().catch(() => undefined);
  };
  return page;
}
