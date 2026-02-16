/**
 * Destination Helper Functions
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
        type: 'extension' as const,
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
    metadata: { name: rg.name },
  }));
}

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
    metadata: { name: cr.name },
  }));
}

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
    metadata: { name: menu.name },
  }));
}

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
    metadata: { name: bh.name },
  }));
}

export function transformAiAssistantsToOptions(
  aiAssistants: Array<{
    id: string | number;
    ai_assistant_id?: string | number;
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
      // Use ai_assistant_id if available, otherwise fall back to id
      id: String(ext.ai_assistant_id || ext.id),
      type: 'ai_assistant' as const,
      label: ext.label || `Ext ${ext.extension_number} - ${ext.ai_assistant?.name || ext.name || 'AI Assistant'}`,
      badge: getBadgeConfig('ai_assistant'),
      metadata: {
        extensionNumber: ext.extension_number,
        name: ext.ai_assistant?.name || ext.name,
        ai_assistant_id: ext.ai_assistant_id,
      },
    }));
}

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
    metadata: { name: alb.name },
  }));
}

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
    case 'user':
      // Users are not currently in DestinationsData, but we can support them if added
      // or handle them separately. Ideally we should add users to DestinationsData.
      // For now, return empty as they are likely handled via a separate hook or extended data.
      // Wait, the plan says to extend DestinationsData.
      return [];
    case 'forward':
      return []; // Forward is an input field, no options
    case 'hangup':
      return [];
    default:
      return [];
  }
}

export function transformUsersToOptions(
  users: Array<{
    id: string | number;
    name: string;
    email: string;
  }>
): DestinationOption[] {
  return users.map((user) => ({
    id: String(user.id),
    type: 'user',
    label: user.name,
    subLabel: user.email,
    badge: getBadgeConfig('user'),
    metadata: { email: user.email },
  }));
}

export function hasDestinationOptions(
  type: DestinationType,
  data: DestinationsData,
  extensionTypes?: ExtensionType[]
): boolean {
  return getDestinationOptionsForType(type, data, extensionTypes).length > 0;
}

export function formatDestinationType(type: DestinationType): string {
  return getDestinationTypeLabel(type);
}

export function parseDestinationValue(
  value: string,
  type: DestinationType
): string | number {
  if (type === 'extension' || type === 'ai_assistant') {
    return value;
  }
  return parseInt(value, 10);
}

export function getDestinationOptionKey(
  type: DestinationType,
  id: string
): string {
  return `${type}-${id}`;
}

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
