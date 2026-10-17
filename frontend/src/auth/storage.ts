/** localStorage that never throws (blocked or full storage): a missing value is simply "nothing stored". */
export const storage = {
  get(key: string): string | null {
    try {
      return window.localStorage.getItem(key);
    } catch {
      return null;
    }
  },
  set(key: string, value: string) {
    try {
      window.localStorage.setItem(key, value);
    } catch {
      // Storage unavailable: the value only lives for this page load.
    }
  },
  remove(key: string) {
    try {
      window.localStorage.removeItem(key);
    } catch {
      // Nothing to remove.
    }
  },
};
