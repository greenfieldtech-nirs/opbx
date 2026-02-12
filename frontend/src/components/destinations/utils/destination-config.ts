/**
 * Destination Configuration
 *
 * Centralized configuration for all destination types including
 * labels, descriptions, icons, and UI metadata.
 */

import {
  Phone,
  Users,
  Menu,
  Bot,
  Scale,
  Clock,
  PhoneOff,
  type LucideIcon,
} from 'lucide-react';
import type { DestinationType, TypeMetadata, ExtensionType } from '../types/destination.types';

/**
 * Default allowed extension types for destination selectors
 */
export const DEFAULT_EXTENSION_TYPES: ExtensionType[] = ['user', 'forward'];

/**
 * All extension types available
 */
export const ALL_EXTENSION_TYPES: ExtensionType[] = ['user', 'forward', 'ai_assistant'];

/**
 * Configuration for each destination type
 * Used by DestinationTypeSelector to render options
 */
export const DESTINATION_TYPE_CONFIG: Record<DestinationType, TypeMetadata> = {
  extension: {
    value: 'extension',
    label: 'PBX User Extension',
    description: 'Forward to a specific user or forward extension',
    icon: Phone,
    category: 'pbx',
    requiresDestination: true,
  },
  ring_group: {
    value: 'ring_group',
    label: 'Ring Group',
    description: 'Forward to a ring group for simultaneous or sequential ringing',
    icon: Users,
    category: 'pbx',
    requiresDestination: true,
  },
  conference_room: {
    value: 'conference_room',
    label: 'Conference',
    description: 'Forward to a conference room for group meetings',
    icon: Users,
    category: 'pbx',
    requiresDestination: true,
  },
  ivr_menu: {
    value: 'ivr_menu',
    label: 'IVR Menu',
    description: 'Route to an interactive voice response menu',
    icon: Menu,
    category: 'routing',
    requiresDestination: true,
  },
  business_hours: {
    value: 'business_hours',
    label: 'Business Hours',
    description: 'Route based on business hours schedule',
    icon: Clock,
    category: 'routing',
    requiresDestination: true,
  },
  ai_assistant: {
    value: 'ai_assistant',
    label: 'AI Assistant',
    description: 'Connect to an AI-powered assistant extension',
    icon: Bot,
    category: 'ai',
    requiresDestination: true,
  },
  ai_load_balancer: {
    value: 'ai_load_balancer',
    label: 'AI Load Balancer',
    description: 'Distribute calls across multiple AI assistants',
    icon: Scale,
    category: 'ai',
    requiresDestination: true,
  },
  hangup: {
    value: 'hangup',
    label: 'Hang Up',
    description: 'End the call',
    icon: PhoneOff,
    category: 'termination',
    requiresDestination: false,
  },
};

/**
 * Get configuration for a specific destination type
 */
export function getDestinationTypeConfig(type: DestinationType): TypeMetadata {
  return DESTINATION_TYPE_CONFIG[type];
}

/**
 * Get all destination type configurations
 * Optionally filtered by allowed types
 */
export function getAllDestinationTypeConfigs(
  allowedTypes?: DestinationType[],
  includeHangup: boolean = false
): TypeMetadata[] {
  let types = Object.values(DESTINATION_TYPE_CONFIG);

  // Filter by allowed types if provided
  if (allowedTypes && allowedTypes.length > 0) {
    types = types.filter((config) => allowedTypes.includes(config.value));
  }

  // Exclude hangup unless explicitly included
  if (!includeHangup) {
    types = types.filter((config) => config.value !== 'hangup');
  }

  return types;
}

/**
 * Get icon for a destination type
 */
export function getDestinationTypeIcon(type: DestinationType): LucideIcon {
  return DESTINATION_TYPE_CONFIG[type].icon;
}

/**
 * Get label for a destination type
 */
export function getDestinationTypeLabel(type: DestinationType): string {
  return DESTINATION_TYPE_CONFIG[type].label;
}

/**
 * Badge configurations for extension subtypes
 */
export const EXTENSION_BADGE_CONFIG: Record<ExtensionType, { color: string; text: string }> = {
  user: {
    color: 'bg-blue-100 text-blue-800 border-blue-200',
    text: 'User',
  },
  forward: {
    color: 'bg-indigo-100 text-indigo-800 border-indigo-200',
    text: 'Forward',
  },
  ai_assistant: {
    color: 'bg-cyan-100 text-cyan-800 border-cyan-200',
    text: 'AI Assistant',
  },
};

/**
 * Badge configurations for destination types
 */
export const DESTINATION_BADGE_CONFIG: Record<string, { color: string; icon: string }> = {
  user: { color: 'bg-blue-100 text-blue-800 border-blue-200', icon: 'Phone' },
  forward: { color: 'bg-indigo-100 text-indigo-800 border-indigo-200', icon: 'ArrowRight' },
  ring_group: { color: 'bg-orange-100 text-orange-800 border-orange-200', icon: 'Users' },
  conference: { color: 'bg-purple-100 text-purple-800 border-purple-200', icon: 'Users' },
  conference_room: { color: 'bg-purple-100 text-purple-800 border-purple-200', icon: 'Users' },
  ivr_menu: { color: 'bg-green-100 text-green-800 border-green-200', icon: 'Menu' },
  business_hours: { color: 'bg-yellow-100 text-yellow-800 border-yellow-200', icon: 'Clock' },
  ai_assistant: { color: 'bg-cyan-100 text-cyan-800 border-cyan-200', icon: 'Bot' },
  ai_load_balancer: { color: 'bg-cyan-100 text-cyan-800 border-cyan-200', icon: 'Scale' },
  hangup: { color: 'bg-red-100 text-red-800 border-red-200', icon: 'PhoneOff' },
};

/**
 * Get badge configuration for a destination type or extension subtype
 */
export function getBadgeConfig(
  type: string,
  subType?: ExtensionType
): { color: string; text: string } {
  // For extensions, use the extension subtype
  if (type === 'extension' && subType) {
    return EXTENSION_BADGE_CONFIG[subType];
  }

  // For other types, map from destination badge config
  const config = DESTINATION_BADGE_CONFIG[type];
  if (config) {
    return {
      color: config.color,
      text: getDestinationTypeLabel(type as DestinationType),
    };
  }

  // Default fallback
  return {
    color: 'bg-gray-100 text-gray-800 border-gray-200',
    text: type,
  };
}

/**
 * Check if a destination type requires a destination selection
 */
export function requiresDestination(type: DestinationType): boolean {
  return DESTINATION_TYPE_CONFIG[type].requiresDestination;
}

/**
 * Default empty state messages
 */
export const DEFAULT_EMPTY_MESSAGES: Record<DestinationType, string> = {
  extension: 'No extensions available',
  ring_group: 'No ring groups available',
  conference_room: 'No conference rooms available',
  ivr_menu: 'No IVR menus available',
  business_hours: 'No business hours schedules available',
  ai_assistant: 'No AI assistants available',
  ai_load_balancer: 'No AI load balancers available',
  hangup: '',
};

/**
 * Get empty state message for a destination type
 */
export function getEmptyMessage(type: DestinationType): string {
  return DEFAULT_EMPTY_MESSAGES[type];
}

/**
 * Category order for sorting destination types
 */
export const CATEGORY_ORDER: Record<string, number> = {
  pbx: 1,
  routing: 2,
  ai: 3,
  termination: 4,
};

/**
 * Sort destination types by category and label
 */
export function sortDestinationTypes(types: TypeMetadata[]): TypeMetadata[] {
  return [...types].sort((a, b) => {
    // First sort by category
    const categoryDiff = CATEGORY_ORDER[a.category] - CATEGORY_ORDER[b.category];
    if (categoryDiff !== 0) return categoryDiff;

    // Then sort by label
    return a.label.localeCompare(b.label);
  });
}
