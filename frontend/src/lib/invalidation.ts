import type { QueryClient } from '@tanstack/react-query';
import { queryKeys } from '@/lib/queryKeys';

/**
 * Routing resources reference each other (destination pickers, ring group members, IVR options,
 * DID routes, deleting a user re-points extensions/memberships), so a write to any one of them
 * invalidates the whole group. Only mounted queries refetch; the rest are just marked stale.
 */
export function invalidateRoutingResources(queryClient: QueryClient): Promise<void[]> {
  return Promise.all(
    [
      queryKeys.users.all,
      queryKeys.extensions.all,
      queryKeys.ringGroups.all,
      queryKeys.ringGroups.lookup(),
      queryKeys.ringGroups.formCallQueues(),
      queryKeys.conferenceRooms.all,
      queryKeys.ivrMenus.all,
      queryKeys.businessHours.all,
      queryKeys.aiAssistants.all,
      queryKeys.aiLoadBalancers.all,
      queryKeys.callQueues.all,
      queryKeys.destinations.all,
      queryKeys.phoneNumbers.all, // DID list shows the routed-to resource
      queryKeys.myQueueMemberships.all,
      queryKeys.dashboard.all, // extension / room / DID counts
    ].map((queryKey) => queryClient.invalidateQueries({ queryKey })),
  );
}

/** DID writes: DID lists + caller-ID lookups used by other pages, and the dashboard count. */
export function invalidatePhoneNumbers(queryClient: QueryClient): Promise<void[]> {
  return Promise.all(
    [
      queryKeys.phoneNumbers.all,
      queryKeys.phoneNumbers.defaultCallerId(),
      queryKeys.extensions.all, // extension caller-ID shows the DID
      queryKeys.dashboard.all,
    ].map((queryKey) => queryClient.invalidateQueries({ queryKey })),
  );
}
