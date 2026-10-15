import { describe, expect, it } from 'vitest';

// Source files as raw strings (eager), excluding tests.
const sources = import.meta.glob(['/src/**/*.{ts,tsx}', '!/src/**/*.test.*', '!/src/**/__tests__/**'], {
  query: '?raw',
  import: 'default',
  eager: true,
}) as Record<string, string>;

describe('polling stops while the tab is hidden', () => {
  it('never enables refetchIntervalInBackground', () => {
    const offenders = Object.entries(sources)
      .filter(([, src]) => /refetchIntervalInBackground\s*:\s*true/.test(src))
      .map(([path]) => path);
    expect(offenders).toEqual([]);
  });
});
