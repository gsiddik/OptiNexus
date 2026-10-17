import { lazy, type ComponentType, type LazyExoticComponent } from 'react';

/**
 * A page loaded on first visit instead of with the first screen. The pages export named components, so the loader picks the name;
 * a failed download (stale file after a release, dropped connection) rejects here and is caught by the route error boundary.
 */
export function lazyPage<K extends string>(loader: () => Promise<Record<K, ComponentType>>, name: K): LazyExoticComponent<ComponentType> {
  return lazy(() =>
    loader().then((module) => {
      // When the bundler's preload failed and its error event was cancelled (to reload the page), the import resolves with nothing:
      // report that as the chunk failure it is, so the screen shows the reload message instead of a TypeError.
      if (!module) throw Object.assign(new Error('Failed to fetch dynamically imported module'), { name: 'ChunkLoadError' });
      return { default: module[name] };
    }),
  );
}
