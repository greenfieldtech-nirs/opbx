/**
 * useDestinations Hook
 *
 * Custom React hook for fetching all destination data in parallel.
 * Uses React Query for caching and state management.
 */

import { useQuery } from '@tanstack/react-query';
import { useAuth } from '@/hooks/useAuth';
import { extensionsService } from '@/services/extensions.service';
import { createResourceService } from '@/services/createResourceService';
import type {
  DestinationType,
  DestinationsData,
  DestinationsLoadingState,
  DestinationsErrorState,
  UseDestinationsReturn,
} from '../types/destination.types';
import {
  transformExtensionsToOptions,
  transformRingGroupsToOptions,
  transformConferenceRoomsToOptions,
  transformIvrMenusToOptions,
  transformBusinessHoursToOptions,
  transformAiAssistantsToOptions,
  transformAiLoadBalancersToOptions,
} from '../utils/destination-helpers';

// Create resource services
const ringGroupsService = createResourceService('ring-groups');
const conferenceRoomsService = createResourceService('conference-rooms');
const ivrMenusService = createResourceService('ivr-menus');
const businessHoursService = createResourceService('business-hours-schedules');
const aiLoadBalancersService = createResourceService('ai-assistant-load-balancers');

/**
 * Default extension types to fetch
 */
const DEFAULT_EXTENSION_TYPES = ['user', 'forward', 'ai_assistant'];

/**
 * Query keys for destination caching
 */
export const destinationQueryKeys = {
  all: ['destinations'] as const,
  extensions: (orgId?: string) => ['destinations', 'extensions', orgId] as const,
  ringGroups: (orgId?: string) => ['destinations', 'ring-groups', orgId] as const,
  conferenceRooms: (orgId?: string) => ['destinations', 'conference-rooms', orgId] as const,
  ivrMenus: (orgId?: string) => ['destinations', 'ivr-menus', orgId] as const,
  businessHours: (orgId?: string) => ['destinations', 'business-hours', orgId] as const,
  aiAssistants: (orgId?: string) => ['destinations', 'ai-assistants', orgId] as const,
  aiLoadBalancers: (orgId?: string) => ['destinations', 'ai-load-balancers', orgId] as const,
};

/**
 * Hook for fetching all destination data
 *
 * @param organizationId - Optional organization ID override
 * @returns Destinations data, loading states, error states, and refetch functions
 *
 * @example
 * ```tsx
 * const { data, isLoading, isError } = useDestinations();
 *
 * // Access extensions
 * const extensionOptions = data.extensions;
 *
 * // Check if any loading
 * if (isLoadingAny) return <Loading />;
 * ```
 */
export function useDestinations(organizationId?: string): UseDestinationsReturn {
  const { user } = useAuth();
  const orgId = organizationId || user?.organization_id;

  // Fetch extensions (includes users, forwards, and AI assistants)
  const extensionsQuery = useQuery({
    queryKey: destinationQueryKeys.extensions(orgId),
    queryFn: async () => {
      const response = await extensionsService.getAll({
        organization_id: orgId,
        per_page: 1000,
      });
      return (response as any)?.data?.data || [];
    },
    enabled: !!orgId,
    staleTime: 5 * 60 * 1000,
  });

  // Fetch ring groups
  const ringGroupsQuery = useQuery({
    queryKey: destinationQueryKeys.ringGroups(orgId),
    queryFn: async () => {
      const response = await ringGroupsService.getAll({
        organization_id: orgId,
        per_page: 1000,
      });
      return (response as any)?.data?.data || [];
    },
    enabled: !!orgId,
    staleTime: 5 * 60 * 1000,
  });

  // Fetch conference rooms
  const conferenceRoomsQuery = useQuery({
    queryKey: destinationQueryKeys.conferenceRooms(orgId),
    queryFn: async () => {
      const response = await conferenceRoomsService.getAll({
        organization_id: orgId,
        per_page: 1000,
      });
      return (response as any)?.data?.data || [];
    },
    enabled: !!orgId,
    staleTime: 5 * 60 * 1000,
  });

  // Fetch IVR menus
  const ivrMenusQuery = useQuery({
    queryKey: destinationQueryKeys.ivrMenus(orgId),
    queryFn: async () => {
      const response = await ivrMenusService.getAll({
        organization_id: orgId,
        per_page: 1000,
      });
      return (response as any)?.data?.data || [];
    },
    enabled: !!orgId,
    staleTime: 5 * 60 * 1000,
  });

  // Fetch business hours schedules
  const businessHoursQuery = useQuery({
    queryKey: destinationQueryKeys.businessHours(orgId),
    queryFn: async () => {
      const response = await businessHoursService.getAll({
        organization_id: orgId,
        per_page: 1000,
      });
      return (response as any)?.data?.data || [];
    },
    enabled: !!orgId,
    staleTime: 5 * 60 * 1000,
  });

  // Fetch AI load balancers
  const aiLoadBalancersQuery = useQuery({
    queryKey: destinationQueryKeys.aiLoadBalancers(orgId),
    queryFn: async () => {
      const response = await aiLoadBalancersService.getAll({
        organization_id: orgId,
        per_page: 1000,
      });
      return (response as any)?.data?.data || [];
    },
    enabled: !!orgId,
    staleTime: 5 * 60 * 1000,
  });

  // Transform data
  const data: DestinationsData = {
    extensions: extensionsQuery.data || [],
    ringGroups: ringGroupsQuery.data || [],
    conferenceRooms: conferenceRoomsQuery.data || [],
    ivrMenus: ivrMenusQuery.data || [],
    businessHours: businessHoursQuery.data || [],
    aiAssistants: extensionsQuery.data?.filter(
      (ext: any) => ext.type === 'ai_assistant'
    ) || [],
    aiLoadBalancers: aiLoadBalancersQuery.data || [],
  };

  // Loading states
  const isLoading: DestinationsLoadingState = {
    extensions: extensionsQuery.isLoading,
    ringGroups: ringGroupsQuery.isLoading,
    conferenceRooms: conferenceRoomsQuery.isLoading,
    ivrMenus: ivrMenusQuery.isLoading,
    businessHours: businessHoursQuery.isLoading,
    aiAssistants: extensionsQuery.isLoading,
    aiLoadBalancers: aiLoadBalancersQuery.isLoading,
  };

  // Error states
  const isError: DestinationsErrorState = {
    extensions: extensionsQuery.error as Error | null,
    ringGroups: ringGroupsQuery.error as Error | null,
    conferenceRooms: conferenceRoomsQuery.error as Error | null,
    ivrMenus: ivrMenusQuery.error as Error | null,
    businessHours: businessHoursQuery.error as Error | null,
    aiAssistants: extensionsQuery.error as Error | null,
    aiLoadBalancers: aiLoadBalancersQuery.error as Error | null,
  };

  // Overall states
  const isLoadingAny = Object.values(isLoading).some(Boolean);
  const isErrorAny = Object.values(isError).some(Boolean);

  // Refetch functions
  const refetch = () => {
    extensionsQuery.refetch();
    ringGroupsQuery.refetch();
    conferenceRoomsQuery.refetch();
    ivrMenusQuery.refetch();
    businessHoursQuery.refetch();
    aiLoadBalancersQuery.refetch();
  };

  const refetchType = (type: DestinationType) => {
    switch (type) {
      case 'extension':
      case 'ai_assistant':
        extensionsQuery.refetch();
        break;
      case 'ring_group':
        ringGroupsQuery.refetch();
        break;
      case 'conference_room':
        conferenceRoomsQuery.refetch();
        break;
      case 'ivr_menu':
        ivrMenusQuery.refetch();
        break;
      case 'business_hours':
        businessHoursQuery.refetch();
        break;
      case 'ai_load_balancer':
        aiLoadBalancersQuery.refetch();
        break;
    }
  };

  return {
    data,
    isLoading,
    isError,
    isLoadingAny,
    isErrorAny,
    refetch,
    refetchType,
  };
}

/**
 * Hook for getting destination options for a specific type
 *
 * @param type - The destination type
 * @param extensionTypes - Optional filter for extension types
 * @returns Array of destination options for the specified type
 *
 * @example
 * ```tsx
 * const extensionOptions = useDestinationOptions('extension', ['user', 'forward']);
 * const ringGroupOptions = useDestinationOptions('ring_group');
 * ```
 */
export function useDestinationOptions(
  type: DestinationType,
  extensionTypes?: ('user' | 'forward' | 'ai_assistant')[]
) {
  const { data, isLoading, isError } = useDestinations();

  const options = (() => {
    switch (type) {
      case 'extension':
        return transformExtensionsToOptions(data.extensions, extensionTypes);
      case 'ring_group':
        return transformRingGroupsToOptions(data.ringGroups);
      case 'conference_room':
        return transformConferenceRoomsToOptions(data.conferenceRooms);
      case 'ivr_menu':
        return transformIvrMenusToOptions(data.ivrMenus);
      case 'business_hours':
        return transformBusinessHoursToOptions(data.businessHours);
      case 'ai_assistant':
        return transformAiAssistantsToOptions(data.aiAssistants);
      case 'ai_load_balancer':
        return transformAiLoadBalancersToOptions(data.aiLoadBalancers);
      case 'hangup':
        return [];
      default:
        return [];
    }
  })();

  // Get specific loading state based on type
  const isLoadingForType = (() => {
    switch (type) {
      case 'extension':
      case 'ai_assistant':
        return isLoading.extensions;
      case 'ring_group':
        return isLoading.ringGroups;
      case 'conference_room':
        return isLoading.conferenceRooms;
      case 'ivr_menu':
        return isLoading.ivrMenus;
      case 'business_hours':
        return isLoading.businessHours;
      case 'ai_load_balancer':
        return isLoading.aiLoadBalancers;
      case 'hangup':
        return false;
      default:
        return false;
    }
  })();

  const isErrorForType = (() => {
    switch (type) {
      case 'extension':
      case 'ai_assistant':
        return isError.extensions;
      case 'ring_group':
        return isError.ringGroups;
      case 'conference_room':
        return isError.conferenceRooms;
      case 'ivr_menu':
        return isError.ivrMenus;
      case 'business_hours':
        return isError.businessHours;
      case 'ai_load_balancer':
        return isError.aiLoadBalancers;
      case 'hangup':
        return null;
      default:
        return null;
    }
  })();

  return {
    options,
    isLoading: isLoadingForType,
    isError: isErrorForType,
  };
}
