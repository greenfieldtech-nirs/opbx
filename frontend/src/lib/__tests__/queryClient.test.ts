import { describe, expect, it } from 'vitest';
import { queryClient } from '../queryClient';
import { queryKeys } from '../queryKeys';

describe('queryClient defaults', () => {
  it('caches for 30s without background polling', () => {
    const q = queryClient.getDefaultOptions().queries!;
    expect(q.staleTime).toBe(30_000);
    expect(q.gcTime).toBeUndefined();
    expect(q.refetchIntervalInBackground).toBe(false);
  });

  it('does not invalidate unrelated queries after a successful mutation', async () => {
    queryClient.setQueryData(queryKeys.phoneNumbers.list({ page: 1 }), { data: [] });
    queryClient.setQueryData(queryKeys.ivrMenus.list({ per_page: 100 }), { data: [] });
    await queryClient.getMutationCache().build(queryClient, { mutationFn: async () => 'ok' }).execute(undefined);
    expect(queryClient.getQueryState(queryKeys.phoneNumbers.list({ page: 1 }))?.isInvalidated).toBe(false);
    expect(queryClient.getQueryState(queryKeys.ivrMenus.list({ per_page: 100 }))?.isInvalidated).toBe(false);
  });
});
