import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { renderHook, waitFor } from '@testing-library/react';
import type { ReactNode } from 'react';
import { describe, expect, it, vi } from 'vitest';
import { queryKeys } from '@/lib/queryKeys';

vi.mock('@/services/autoDialerCampaignsApi', () => ({
  autoDialerCampaignsApi: { start: vi.fn().mockResolvedValue({}) },
}));

vi.mock('@/services/platformApi', () => ({
  platformApi: { users: { delete: vi.fn().mockResolvedValue({}) }, organizations: {} },
}));

import { useDeletePlatformUser } from '@/hooks/platform';
import { autoDialerKeys, useStartCampaign } from '@/hooks/useAutoDialerCampaigns';
import { monitorKeys } from '@/hooks/useAutoDialerMonitor';

describe('mutation hooks invalidate their own keys', () => {
  it('deleting a platform user refreshes platform data and tenant routing resources', async () => {
    const qc = new QueryClient();
    const spy = vi.spyOn(qc, 'invalidateQueries');
    const wrapper = ({ children }: { children: ReactNode }) => (
      <QueryClientProvider client={qc}>{children}</QueryClientProvider>
    );
    const { result } = renderHook(() => useDeletePlatformUser(), { wrapper });
    result.current.mutate('1');
    await waitFor(() => expect(result.current.isSuccess).toBe(true));
    const keys = spy.mock.calls.map(([f]) => JSON.stringify(f?.queryKey));
    expect(keys).toContain(JSON.stringify(queryKeys.platform.all));
    expect(keys).toContain(JSON.stringify(queryKeys.extensions.all));
    expect(keys).toContain(JSON.stringify(queryKeys.ringGroups.all));
    expect(keys).toContain(JSON.stringify(queryKeys.destinations.all));
  });

  it('starting a campaign refreshes the campaign list page and the live monitor', async () => {
    const qc = new QueryClient();
    const spy = vi.spyOn(qc, 'invalidateQueries');
    const wrapper = ({ children }: { children: ReactNode }) => (
      <QueryClientProvider client={qc}>{children}</QueryClientProvider>
    );
    const { result } = renderHook(() => useStartCampaign(), { wrapper });
    result.current.mutate('7');
    await waitFor(() => expect(result.current.isSuccess).toBe(true));
    const keys = spy.mock.calls.map(([f]) => JSON.stringify(f?.queryKey));
    expect(keys).toContain(JSON.stringify(autoDialerKeys.all));
    expect(keys).toContain(JSON.stringify(monitorKeys.all));
  });
});
