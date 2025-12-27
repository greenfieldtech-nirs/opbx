/**
 * Mock Ring Groups Data
 * For UI/UX development and testing
 */

export type RingGroupStrategy = 'simultaneous' | 'round_robin' | 'sequential';
export type RingGroupStatus = 'active' | 'inactive';
export type FallbackAction = 'voicemail' | 'extension' | 'hangup' | 'repeat';

export interface RingGroupMember {
  extension_id: string;
  extension_number: string;
  user_name: string | null;
  priority: number;
}

export interface RingGroup {
  id: string;
  organization_id: string;
  name: string;
  description?: string;
  strategy: RingGroupStrategy;
  timeout: number; // Extension ring timeout in seconds (how long each extension rings)
  ring_turns: number; // Number of complete cycles through all extensions (1-9)
  fallback_action: FallbackAction;
  fallback_extension_id?: string;
  fallback_extension_number?: string;
  status: RingGroupStatus;
  members: RingGroupMember[];
  created_at: string;
  updated_at: string;
}

// Mock user for permissions
export const mockCurrentUserForRingGroups = {
  id: 'user-001',
  name: 'Admin User',
  email: 'admin@example.com',
  role: 'owner' as const,
  organization_id: 'org-001',
};

// Mock available extensions (type: user, status: active)
export const mockAvailableExtensions = [
  { id: 'ext-001', extension_number: '101', user_name: 'John Doe', type: 'user', status: 'active' },
  { id: 'ext-002', extension_number: '102', user_name: 'Jane Smith', type: 'user', status: 'active' },
  { id: 'ext-003', extension_number: '103', user_name: 'Bob Johnson', type: 'user', status: 'active' },
  { id: 'ext-004', extension_number: '104', user_name: 'Alice Williams', type: 'user', status: 'active' },
  { id: 'ext-005', extension_number: '105', user_name: 'Charlie Brown', type: 'user', status: 'active' },
  { id: 'ext-006', extension_number: '106', user_name: 'Diana Prince', type: 'user', status: 'active' },
  { id: 'ext-007', extension_number: '107', user_name: 'Edward Norton', type: 'user', status: 'active' },
  { id: 'ext-008', extension_number: '108', user_name: 'Fiona Apple', type: 'user', status: 'active' },
  { id: 'ext-009', extension_number: '109', user_name: null, type: 'user', status: 'active' }, // Unassigned
  { id: 'ext-010', extension_number: '110', user_name: 'George Martin', type: 'user', status: 'active' },
];

// Mock ring groups
export const mockRingGroups: RingGroup[] = [
  {
    id: 'rg-001',
    organization_id: 'org-001',
    name: 'Sales Team',
    description: 'Main sales team for inbound leads',
    strategy: 'simultaneous',
    timeout: 30,
    ring_turns: 2,
    fallback_action: 'voicemail',
    status: 'active',
    members: [
      { extension_id: 'ext-001', extension_number: '101', user_name: 'John Doe', priority: 1 },
      { extension_id: 'ext-002', extension_number: '102', user_name: 'Jane Smith', priority: 2 },
      { extension_id: 'ext-003', extension_number: '103', user_name: 'Bob Johnson', priority: 3 },
    ],
    created_at: '2025-01-15T10:30:00Z',
    updated_at: '2025-01-20T15:45:00Z',
  },
  {
    id: 'rg-002',
    organization_id: 'org-001',
    name: 'Support Department',
    description: 'Customer support team',
    strategy: 'round_robin',
    timeout: 45,
    ring_turns: 3,
    fallback_action: 'extension',
    fallback_extension_id: 'ext-010',
    fallback_extension_number: '110',
    status: 'active',
    members: [
      { extension_id: 'ext-004', extension_number: '104', user_name: 'Alice Williams', priority: 1 },
      { extension_id: 'ext-005', extension_number: '105', user_name: 'Charlie Brown', priority: 2 },
      { extension_id: 'ext-006', extension_number: '106', user_name: 'Diana Prince', priority: 3 },
      { extension_id: 'ext-007', extension_number: '107', user_name: 'Edward Norton', priority: 4 },
    ],
    created_at: '2025-01-10T09:00:00Z',
    updated_at: '2025-01-18T11:20:00Z',
  },
  {
    id: 'rg-003',
    organization_id: 'org-001',
    name: 'Management Escalation',
    description: 'Escalation path for urgent matters',
    strategy: 'sequential',
    timeout: 20,
    ring_turns: 2,
    fallback_action: 'voicemail',
    status: 'active',
    members: [
      { extension_id: 'ext-001', extension_number: '101', user_name: 'John Doe', priority: 1 },
      { extension_id: 'ext-004', extension_number: '104', user_name: 'Alice Williams', priority: 2 },
      { extension_id: 'ext-010', extension_number: '110', user_name: 'George Martin', priority: 3 },
    ],
    created_at: '2025-01-05T14:15:00Z',
    updated_at: '2025-01-05T14:15:00Z',
  },
  {
    id: 'rg-004',
    organization_id: 'org-001',
    name: 'After Hours Team',
    description: 'Team available outside business hours',
    strategy: 'simultaneous',
    timeout: 60,
    ring_turns: 1,
    fallback_action: 'hangup',
    status: 'inactive',
    members: [
      { extension_id: 'ext-007', extension_number: '107', user_name: 'Edward Norton', priority: 1 },
      { extension_id: 'ext-008', extension_number: '108', user_name: 'Fiona Apple', priority: 2 },
    ],
    created_at: '2024-12-20T16:30:00Z',
    updated_at: '2025-01-12T10:00:00Z',
  },
  {
    id: 'rg-005',
    organization_id: 'org-001',
    name: 'VIP Customer Service',
    description: 'Dedicated team for VIP customers',
    strategy: 'sequential',
    timeout: 15,
    ring_turns: 5,
    fallback_action: 'repeat',
    status: 'active',
    members: [
      { extension_id: 'ext-002', extension_number: '102', user_name: 'Jane Smith', priority: 1 },
      { extension_id: 'ext-006', extension_number: '106', user_name: 'Diana Prince', priority: 2 },
    ],
    created_at: '2025-01-08T11:45:00Z',
    updated_at: '2025-01-19T09:30:00Z',
  },
  {
    id: 'rg-006',
    organization_id: 'org-001',
    name: 'Technical Support',
    description: 'Technical issues and troubleshooting',
    strategy: 'round_robin',
    timeout: 40,
    ring_turns: 2,
    fallback_action: 'voicemail',
    status: 'active',
    members: [
      { extension_id: 'ext-003', extension_number: '103', user_name: 'Bob Johnson', priority: 1 },
      { extension_id: 'ext-005', extension_number: '105', user_name: 'Charlie Brown', priority: 2 },
      { extension_id: 'ext-008', extension_number: '108', user_name: 'Fiona Apple', priority: 3 },
    ],
    created_at: '2025-01-12T13:20:00Z',
    updated_at: '2025-01-21T14:10:00Z',
  },
  {
    id: 'rg-007',
    organization_id: 'org-001',
    name: 'Billing Inquiries',
    description: 'Payment and billing questions',
    strategy: 'simultaneous',
    timeout: 25,
    ring_turns: 3,
    fallback_action: 'extension',
    fallback_extension_id: 'ext-004',
    fallback_extension_number: '104',
    status: 'active',
    members: [
      { extension_id: 'ext-004', extension_number: '104', user_name: 'Alice Williams', priority: 1 },
      { extension_id: 'ext-010', extension_number: '110', user_name: 'George Martin', priority: 2 },
    ],
    created_at: '2025-01-14T10:00:00Z',
    updated_at: '2025-01-14T10:00:00Z',
  },
  {
    id: 'rg-008',
    organization_id: 'org-001',
    name: 'New Customer Onboarding',
    description: 'Assist new customers with setup',
    strategy: 'sequential',
    timeout: 30,
    ring_turns: 1,
    fallback_action: 'voicemail',
    status: 'inactive',
    members: [
      { extension_id: 'ext-006', extension_number: '106', user_name: 'Diana Prince', priority: 1 },
      { extension_id: 'ext-007', extension_number: '107', user_name: 'Edward Norton', priority: 2 },
      { extension_id: 'ext-009', extension_number: '109', user_name: null, priority: 3 },
    ],
    created_at: '2024-12-28T09:15:00Z',
    updated_at: '2025-01-16T16:45:00Z',
  },
];

// Helper function to get next ring group ID
export function getNextRingGroupId(existingGroups: RingGroup[]): string {
  const maxId = existingGroups.reduce((max, group) => {
    const parts = group.id.split('-');
    const num = parts[1] ? parseInt(parts[1], 10) : 0;
    return num > max ? num : max;
  }, 0);
  return `rg-${String(maxId + 1).padStart(3, '0')}`;
}

// Helper function to get strategy display name
export function getStrategyDisplayName(strategy: RingGroupStrategy): string {
  const names: Record<RingGroupStrategy, string> = {
    simultaneous: 'Ring All',
    round_robin: 'Round Robin',
    sequential: 'Sequential',
  };
  return names[strategy];
}

// Helper function to get strategy description
export function getStrategyDescription(strategy: RingGroupStrategy): string {
  const descriptions: Record<RingGroupStrategy, string> = {
    simultaneous: 'All members ring at the same time. First to answer gets the call.',
    round_robin: 'Calls distributed evenly across members in rotation. Balances workload.',
    sequential: 'Ring members one at a time based on priority order. Higher priority rings first.',
  };
  return descriptions[strategy];
}

// Helper function to get fallback display text
export function getFallbackDisplayText(
  fallbackAction: FallbackAction,
  fallbackExtensionNumber?: string
): string {
  switch (fallbackAction) {
    case 'voicemail':
      return 'Voicemail';
    case 'extension':
      return fallbackExtensionNumber ? `→ Ext ${fallbackExtensionNumber}` : '→ Extension';
    case 'hangup':
      return 'Hangup';
    case 'repeat':
      return 'Repeat';
    default:
      return 'Unknown';
  }
}
