/**
 * Destination Helper Functions
 *
 * Utility functions for formatting, transforming, and working with
 * destination data throughout the application.
 */

import type {
  DestinationType,
  DestinationOption,
  ExtensionType,
  DestinationsData,
} from '../types/destination.types';
import {
  getBadgeConfig,
  getDestinationTypeLabel,
  DEFAULT_EXTENSION_TYPES,
} from './destination-config';

/**
 * Format an extension label with extension number and display name
 */
export function formatExtensionLabel(
  extensionNumber: string,
  displayName?: string,
  type?: string
): string {
  if (displayName && displayName !== 'Unassigned User') {
    return `Ext ${extensionNumber} - ${displayName}`;
  }
  return `Ext ${extensionNumber}`;
}

/**
 * Get display label for an extension based on its type
 */
export function getExtensionDisplayLabel(ext: {
  type: string;
  extension_number: string;
  user?: { name: string };
  configuration?: { forward_to: string };
  ai_assistant?: { name: string };
}): string {
  switch (ext.type) {
    case 'user':
      return ext.user?.name || 'Unassigned User';
    case 'forward':
      return ext.configuration?.forward_to
        ? `Forward to ${ext.configuration.forward_to}`
        : 'Forward Extension';
    case 'ai_assistant':
      return ext.ai_assistant?.name || 'AI Assistant';
    default:
      return ext.user?.name || 'Unknown';
  }
}

/**
 * Transform extensions data into destination options
 */
export function transformExtensionsToOptions(
  extensions: Array<{
    id: string | number;
    extension_number: string;
    type: string;
    user?: { name: string };
    configuration?: { forward_to: string };
    ai_assistant?: { name: string };
  }>,
  allowedTypes: ExtensionType[] = DEFAULT_EXTENSION_TYPES
): DestinationOption[] {
  return extensions
    .filter((ext) => allowedTypes.includes(ext.type as ExtensionType))
    .map((ext) => {
      const displayLabel = getExtensionDisplayLabel(ext);
      const subLabel = ext.type === 'forward' && ext.configuration?.forward_to
        ? `Forward to ${ext.configuration.forward_to}`
        : undefined;

      return {
        id: String(ext.id),
        type: 'extension',
        label: formatExtensionLabel(ext.extension_number, displayLabel, ext.type),
        subLabel,
        badge: getBadgeConfig('extension', ext.type as ExtensionType),
        metadata: {
          extensionNumber: ext.extension_number,
          extensionType: ext.type,
        },
      };
    });
}

/**
 * Transform ring groups data into destination options
 */
export function transformRingGroupsToOptions(
  ringGroups: Array<{
    id: string | number;
    name: string;
    description?: string;
  }>
): DestinationOption[] {
  return ringGroups.map((rg) => ({
    id: String(rg.id),
    type: 'ring_group',
    label: rg.name,
    subLabel: rg.description,
    badge: getBadgeConfig('ring_group'),
    metadata: {
      name: rg.name,
    },
  }));
}

/**
 * Transform conference rooms data into destination options
 */
export function transformConferenceRoomsToOptions(
  conferenceRooms: Array<{
    id: string | number;
    name: string;
    description?: string;
  }>
): DestinationOption[] {
  return conferenceRooms.map((cr) => ({
    id: String(cr.id),
    type: 'conference_room',
    label: cr.name,
    subLabel: cr.description,
    badge: getBadgeConfig('conference_room'),
    metadata: {
      name: cr.name,
    },
  }));
}

/**
 * Transform IVR menus data into destination options
 */
export function transformIvrMenusToOptions(
  ivrMenus: Array<{
    id: string | number;
    name: string;
    description?: string;
  }>
): DestinationOption[] {
  return ivrMenus.map((menu) => ({
    id: String(menu.id),
    type: 'ivr_menu',
    label: menu.name,
    subLabel: menu.description,
    badge: getBadgeConfig('ivr_menu'),
    metadata: {
      name: menu.name,
    },
  }));
}

/**
 * Transform business hours data into destination options
 */
export function transformBusinessHoursToOptions(
  businessHours: Array<{
    id: string | number;
    name: string;
    description?: string;
  }>
): DestinationOption[] {
  return businessHours.map((bh) => ({
    id: String(bh.id),
    type: 'business_hours',
    label: bh.name,
    subLabel: bh.description,
    badge: getBadgeConfig('business_hours'),
    metadata: {
      name: bh.name,
    },
  }));
}

/**
 * Transform AI assistants data into destination options
 * Note: AI assistants are extensions with type 'ai_assistant'
 */
export function transformAiAssistantsToOptions(
  aiAssistants: Array<{
    id: string | number;
    extension_number: string;
    type?: string;
    ai_assistant?: { name: string };
    label?: string;
    name?: string;
  }>
): DestinationOption[] {
  return aiAssistants
    .filter((ext) => !ext.type || ext.type === 'ai_assistant')
    .map((ext) => ({
      id: String(ext.id),
      type: 'ai_assistant',
      label: ext.label || `Ext ${ext.extension_number} - ${ext.ai_assistant?.name || ext.name || 'AI Assistant'}`,
      badge: getBadgeConfig('ai_assistant'),
      metadata: {
        extensionNumber: ext.extension_number,
        name: ext.ai_assistant?.name || ext.name,
      },
    }));
}

/**
 * Transform AI load balancers data into destination options
 */
export function transformAiLoadBalancersToOptions(
  aiLoadBalancers: Array<{
    id: string | number;
    name: string;
    description?: string;
  }>
): DestinationOption[] {
  return aiLoadBalancers.map((alb) => ({
    id: String(alb.id),
    type: 'ai_load_balancer',
    label: alb.name,
    subLabel: alb.description,
    badge: getBadgeConfig('ai_load_balancer'),
    metadata: {
      name: alb.name,
    },
  }));
}

/**
 * Get destination options for a specific type from all destinations data
 */
export function getDestinationOptionsForType(
  type: DestinationType,
  data: DestinationsData,
  extensionTypes?: ExtensionType[]
): DestinationOption[] {
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
}

/**
 * Check if there are any destination options for a type
 */
export function hasDestinationOptions(
  type: DestinationType,
  data: DestinationsData,
  extensionTypes?: ExtensionType[]
): boolean {
  return getDestinationOptionsForType(type, data, extensionTypes).length > 0;
}

/**
 * Format destination type for display
 */
export function formatDestinationType(type: DestinationType): string {
  return getDestinationTypeLabel(type);
}

/**
 * Parse destination value based on type
 * Some types use extension_number (string), others use id (number)
 */
export function parseDestinationValue(
  value: string,
  type: DestinationType
): string | number {
  if (type === 'extension' || type === 'ai_assistant') {
    // Extensions use extension_number as string
    return value;
  }
  // Other types use numeric ID
  return parseInt(value, 10);
}

/**
 * Get unique key for a destination option (for React keys)
 */
export function getDestinationOptionKey(
  type: DestinationType,
  id: string
): string {
  return `${type}-${id}`;
}

/**
 * Filter destination options by search query
 */
export function filterDestinationOptions(
  options: DestinationOption[],
  query: string
): DestinationOption[] {
  const lowerQuery = query.toLowerCase();
  return options.filter(
    (opt) =>
      opt.label.toLowerCase().includes(lowerQuery) ||
      opt.subLabel?.toLowerCase().includes(lowerQuery)
  );
}
