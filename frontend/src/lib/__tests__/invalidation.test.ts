import { QueryClient } from '@tanstack/react-query';
import { describe, expect, it, vi } from 'vitest';
import { invalidatePhoneNumbers, invalidateRoutingResources } from '../invalidation';
import { queryKeys } from '../queryKeys';

function invalidatedKeys(fn: (qc: QueryClient) => unknown) {
  const qc = new QueryClient();
  const spy = vi.spyOn(qc, 'invalidateQueries');
  fn(qc);
  return spy.mock.calls.map(([f]) => JSON.stringify(f?.queryKey));
}

describe('targeted invalidation helpers', () => {
  it('routing writes refresh every routing resource, destination pickers and DID lists', () => {
    const keys = invalidatedKeys(invalidateRoutingResources);
    for (const k of [
      queryKeys.users.all,
      queryKeys.extensions.all,
      queryKeys.ringGroups.all,
      queryKeys.ringGroups.lookup(),
      queryKeys.conferenceRooms.all,
      queryKeys.ivrMenus.all,
      queryKeys.businessHours.all,
      queryKeys.aiAssistants.all,
      queryKeys.aiLoadBalancers.all,
      queryKeys.callQueues.all,
      queryKeys.destinations.all,
      queryKeys.phoneNumbers.all,
    ]) {
      expect(keys).toContain(JSON.stringify(k));
    }
  });

  it('DID writes refresh DID lookups and the default caller id, not unrelated resources', () => {
    const keys = invalidatedKeys(invalidatePhoneNumbers);
    expect(keys).toContain(JSON.stringify(queryKeys.phoneNumbers.all));
    expect(keys).toContain(JSON.stringify(queryKeys.phoneNumbers.defaultCallerId()));
    expect(keys).not.toContain(JSON.stringify(queryKeys.ivrMenus.all));
  });
});
