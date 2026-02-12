/**
 * Destination Selectors - Public API
 *
 * Generic destination selector components for use across all pages.
 *
 * @example
 * ```tsx
 * import {
 *   DestinationTypeSelector,
 *   DestinationSelector,
 *   DestinationTypeAndSelector,
 *   DestinationBadge,
 *   useDestinations,
 * } from '@/components/destinations';
 * ```
 */

// Components
export { DestinationTypeSelector } from './DestinationTypeSelector';
export { DestinationSelector } from './DestinationSelector';
export { DestinationTypeAndSelector } from './DestinationTypeAndSelector';
export { DestinationBadge } from './DestinationBadge';

// Hooks
export {
  useDestinations,
  useDestinationOptions,
  destinationQueryKeys,
} from './hooks/useDestinations';

// Types
export type {
  DestinationType,
  DestinationCategory,
  DestinationOption,
  TypeMetadata,
  ExtensionType,
  DestinationTypeSelectorProps,
  DestinationSelectorProps,
  DestinationTypeAndSelectorProps,
  DestinationBadgeProps,
  DestinationsData,
  DestinationsLoadingState,
  DestinationsErrorState,
  UseDestinationsReturn,
} from './types/destination.types';

// Utilities
export {
  getDestinationTypeConfig,
  getAllDestinationTypeConfigs,
  getDestinationTypeIcon,
  getDestinationTypeLabel,
  getBadgeConfig,
  requiresDestination,
  getEmptyMessage,
  sortDestinationTypes,
  DEFAULT_EXTENSION_TYPES,
  ALL_EXTENSION_TYPES,
  EXTENSION_BADGE_CONFIG,
  DESTINATION_BADGE_CONFIG,
  DESTINATION_TYPE_CONFIG,
} from './utils/destination-config';

export {
  formatExtensionLabel,
  getExtensionDisplayLabel,
  transformExtensionsToOptions,
  transformRingGroupsToOptions,
  transformConferenceRoomsToOptions,
  transformIvrMenusToOptions,
  transformBusinessHoursToOptions,
  transformAiAssistantsToOptions,
  transformAiLoadBalancersToOptions,
  getDestinationOptionsForType,
  hasDestinationOptions,
  formatDestinationType,
  parseDestinationValue,
  getDestinationOptionKey,
  filterDestinationOptions,
} from './utils/destination-helpers';
