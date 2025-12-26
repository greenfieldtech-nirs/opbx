/**
 * Mock Conference Room Data for UI Development
 */

export interface ConferenceRoom {
  id: string;
  organization_id: string;
  name: string;
  description?: string;
  max_participants: number;
  status: 'active' | 'inactive';

  // Advanced settings
  pin?: string;
  pin_required: boolean;
  host_pin?: string;
  recording_enabled: boolean;
  recording_auto_start: boolean;
  recording_webhook_url?: string;
  wait_for_host: boolean;
  mute_on_entry: boolean;
  announce_join_leave: boolean;
  music_on_hold: boolean;
  talk_detection_enabled: boolean;
  talk_detection_webhook_url?: string;

  created_at: string;
  updated_at: string;
}

// Mock conference rooms data
export const mockConferenceRooms: ConferenceRoom[] = [
  {
    id: 'room-001',
    organization_id: 'org-001',
    name: 'Executive Board Room',
    description: 'Used for executive team meetings and board discussions. Recording is mandatory for compliance.',
    max_participants: 25,
    status: 'active',
    pin: '1234',
    pin_required: true,
    host_pin: '5678',
    recording_enabled: true,
    recording_auto_start: true,
    recording_webhook_url: 'https://example.com/webhooks/recording-complete',
    wait_for_host: true,
    mute_on_entry: true,
    announce_join_leave: true,
    music_on_hold: false,
    talk_detection_enabled: true,
    talk_detection_webhook_url: 'https://example.com/webhooks/talk-events',
    created_at: '2024-01-15T10:30:00Z',
    updated_at: '2024-12-25T14:45:00Z',
  },
  {
    id: 'room-002',
    organization_id: 'org-001',
    name: 'Sales Team Daily',
    description: 'Quick daily standup for the sales team',
    max_participants: 10,
    status: 'active',
    pin_required: false,
    recording_enabled: false,
    recording_auto_start: false,
    wait_for_host: false,
    mute_on_entry: false,
    announce_join_leave: true,
    music_on_hold: true,
    talk_detection_enabled: false,
    created_at: '2024-02-10T09:00:00Z',
    updated_at: '2024-12-20T08:15:00Z',
  },
  {
    id: 'room-003',
    organization_id: 'org-001',
    name: 'All Hands Meeting',
    description: 'Monthly company-wide meeting for announcements and updates',
    max_participants: 100,
    status: 'inactive',
    pin: '9999',
    pin_required: true,
    recording_enabled: true,
    recording_auto_start: true,
    recording_webhook_url: 'https://example.com/webhooks/all-hands-recordings',
    wait_for_host: true,
    mute_on_entry: true,
    announce_join_leave: false,
    music_on_hold: false,
    talk_detection_enabled: false,
    created_at: '2024-01-05T15:00:00Z',
    updated_at: '2024-11-30T16:20:00Z',
  },
  {
    id: 'room-004',
    organization_id: 'org-001',
    name: 'Customer Support Team',
    description: 'Support team collaboration room',
    max_participants: 15,
    status: 'active',
    pin_required: false,
    recording_enabled: false,
    recording_auto_start: false,
    wait_for_host: false,
    mute_on_entry: false,
    announce_join_leave: true,
    music_on_hold: false,
    talk_detection_enabled: false,
    created_at: '2024-03-01T11:00:00Z',
    updated_at: '2024-12-15T10:30:00Z',
  },
  {
    id: 'room-005',
    organization_id: 'org-001',
    name: 'Training Room',
    description: 'Used for employee training sessions and onboarding',
    max_participants: 50,
    status: 'active',
    pin: '4321',
    pin_required: true,
    host_pin: '8765',
    recording_enabled: true,
    recording_auto_start: false,
    wait_for_host: true,
    mute_on_entry: true,
    announce_join_leave: false,
    music_on_hold: true,
    talk_detection_enabled: false,
    created_at: '2024-02-20T13:00:00Z',
    updated_at: '2024-12-18T09:00:00Z',
  },
  {
    id: 'room-006',
    organization_id: 'org-001',
    name: 'Product Demo',
    max_participants: 20,
    status: 'active',
    pin_required: false,
    recording_enabled: true,
    recording_auto_start: true,
    recording_webhook_url: 'https://example.com/webhooks/product-demos',
    wait_for_host: false,
    mute_on_entry: false,
    announce_join_leave: true,
    music_on_hold: false,
    talk_detection_enabled: true,
    talk_detection_webhook_url: 'https://example.com/webhooks/demo-talk-analytics',
    created_at: '2024-04-10T10:00:00Z',
    updated_at: '2024-12-22T14:00:00Z',
  },
  {
    id: 'room-007',
    organization_id: 'org-001',
    name: 'Engineering Sync',
    description: 'Weekly engineering team synchronization meeting',
    max_participants: 30,
    status: 'active',
    pin_required: false,
    recording_enabled: false,
    recording_auto_start: false,
    wait_for_host: false,
    mute_on_entry: false,
    announce_join_leave: false,
    music_on_hold: false,
    talk_detection_enabled: false,
    created_at: '2024-01-25T14:00:00Z',
    updated_at: '2024-12-19T11:00:00Z',
  },
  {
    id: 'room-008',
    organization_id: 'org-001',
    name: 'Interview Room',
    description: 'For conducting candidate interviews',
    max_participants: 5,
    status: 'active',
    pin: '7890',
    pin_required: true,
    recording_enabled: false,
    recording_auto_start: false,
    wait_for_host: true,
    mute_on_entry: false,
    announce_join_leave: false,
    music_on_hold: true,
    talk_detection_enabled: false,
    created_at: '2024-03-15T08:00:00Z',
    updated_at: '2024-12-10T15:30:00Z',
  },
];

// Helper to get next available room ID
export function getNextRoomId(rooms: ConferenceRoom[]): string {
  const usedIds = rooms
    .map(room => parseInt(room.id.replace('room-', ''), 10))
    .filter(num => !isNaN(num));

  if (usedIds.length === 0) return 'room-001';

  const maxId = Math.max(...usedIds);
  return `room-${String(maxId + 1).padStart(3, '0')}`;
}

// Mock extensions assigned to rooms (for display purposes)
export const mockRoomExtensions: Record<string, string[]> = {
  'room-001': ['Extension 3001 - Executive Conference', 'Extension 3002 - Board Room'],
  'room-002': ['Extension 3010 - Sales Daily Standup'],
  'room-003': ['Extension 3100 - Company All Hands'],
  'room-004': ['Extension 3020 - Support Team'],
  'room-005': ['Extension 3050 - Training Sessions'],
  'room-006': ['Extension 3030 - Product Demos'],
  'room-007': ['Extension 3040 - Engineering Sync'],
  'room-008': [],
};

// Mock current user for testing different role views
export const mockCurrentUserForRooms = {
  id: 'user-001',
  name: 'John Smith',
  email: 'john.smith@acmecorp.com',
  role: 'owner', // Change this to test different roles: 'owner', 'pbx_admin', 'pbx_user', 'reporter'
};
