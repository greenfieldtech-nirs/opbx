/**
 * Ring Groups Utility Functions
 */

import type {
  RingGroup,
  RingGroupStrategy,
  RingGroupMember,
} from '@/types';

export type RingGroupStatus = 'active' | 'inactive';
export type FallbackAction = 'voicemail' | 'extension' | 'ring_group' | 'ivr_menu' | 'ai_assistant' | 'hangup' | 'repeat';

/**
 * Get next ring group ID
 */
export function getNextRingGroupId(existingGroups: RingGroup[]): string {
  const maxId = existingGroups.reduce((max, group) => {
    const parts = group.id.split('-');
    const num = parts[1] ? parseInt(parts[1], 10) : 0;
    return num > max ? num : max;
  }, 0);
  return `rg-${String(maxId + 1).padStart(3, '0')}`;
}

/**
 * Get strategy display name
 */
export function getStrategyDisplayName(strategy: RingGroupStrategy): string {
  const names: Record<RingGroupStrategy, string> = {
    simultaneous: 'Ring All',
    round_robin: 'Round Robin',
    sequential: 'Sequential',
  };
  return names[strategy];
}

/**
 * Get strategy description
 */
export function getStrategyDescription(strategy: RingGroupStrategy): string {
  const descriptions: Record<RingGroupStrategy, string> = {
    simultaneous: 'All members ring at the same time. First to answer gets the call.',
    round_robin: 'Calls distributed evenly across members in rotation. Balances workload.',
    sequential: 'Ring members one at a time based on priority order. Higher priority rings first.',
  };
  return descriptions[strategy];
}

/**
 * Get fallback display text
 */
export function getFallbackDisplayText(
  fallbackAction: FallbackAction,
  fallbackExtensionNumber?: string
): string {
  switch (fallbackAction) {
    case 'voicemail':
      return 'Voicemail';
    case 'extension':
      return fallbackExtensionNumber ? `→ Ext ${fallbackExtensionNumber}` : '→ Extension';
    case 'ring_group':
      return '→ Ring Group';
    case 'ivr_menu':
      return '→ IVR Menu';
    case 'ai_assistant':
      return '→ AI Assistant';
    case 'hangup':
      return 'Hangup';
    case 'repeat':
      return 'Repeat';
    default:
      return 'Unknown';
  }
}
