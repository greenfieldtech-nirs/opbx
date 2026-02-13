/**
 * Debug hook for useDestinations
 * Temporary helper to diagnose data fetching issues
 */

import { useDestinations } from './useDestinations';

export function useDestinationsDebug(organizationId?: string) {
  const result = useDestinations(organizationId);

  console.log('=== useDestinations Debug ===', {
    extensionsCount: result.data.extensions.length,
    ringGroupsCount: result.data.ringGroups.length,
    conferenceRoomsCount: result.data.conferenceRooms.length,
    ivrMenusCount: result.data.ivrMenus.length,
    businessHoursCount: result.data.businessHours.length,
    aiAssistantsCount: result.data.aiAssistants.length,
    aiLoadBalancersCount: result.data.aiLoadBalancers.length,
    isLoadingAny: result.isLoadingAny,
    isErrorAny: result.isErrorAny,
    sampleExtension: result.data.extensions[0],
    sampleAiAssistant: result.data.aiAssistants[0],
  });

  return result;
}
