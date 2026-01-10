/**
 * Mock Extension Data for UI Development
 */

import type { Extension, ExtensionType } from '@/types';

// Mock extensions data with various types
export const mockExtensions: Extension[] = [
  // PBX User Extensions
  {
    id: 'ext-001',
    organization_id: 'org-001',
    user_id: 'user-001',
    extension_number: '1001',
    type: 'user',
    status: 'active',
    voicemail_enabled: true,
    configuration: {
      voicemail_enabled: true,
    },
    created_at: '2024-01-15T10:00:00Z',
    updated_at: '2024-01-15T10:00:00Z',
  },
  {
    id: 'ext-002',
    organization_id: 'org-001',
    user_id: 'user-002',
    extension_number: '1002',
    type: 'user',
    status: 'active',
    voicemail_enabled: true,
    configuration: {
      voicemail_enabled: true,
    },
    created_at: '2024-01-20T10:00:00Z',
    updated_at: '2024-01-20T10:00:00Z',
  },
  {
    id: 'ext-003',
    organization_id: 'org-001',
    user_id: 'user-003',
    extension_number: '1003',
    type: 'user',
    status: 'active',
    voicemail_enabled: false,
    configuration: {
      voicemail_enabled: false,
    },
    created_at: '2024-02-01T10:00:00Z',
    updated_at: '2024-02-01T10:00:00Z',
  },
  {
    id: 'ext-004',
    organization_id: 'org-001',
    user_id: null,
    extension_number: '1004',
    type: 'user',
    status: 'active',
    voicemail_enabled: true,
    configuration: {
      voicemail_enabled: true,
    },
    created_at: '2024-02-10T10:00:00Z',
    updated_at: '2024-02-10T10:00:00Z',
  },

  // Conference Rooms
  {
    id: 'ext-101',
    organization_id: 'org-001',
    user_id: null,
    extension_number: '2001',
    type: 'conference',
    status: 'active',
    voicemail_enabled: false,
    configuration: {
      conference_pin: '1234',
      max_participants: 10,
      recording_enabled: true,
    },
    created_at: '2024-03-01T10:00:00Z',
    updated_at: '2024-03-01T10:00:00Z',
  },
  {
    id: 'ext-102',
    organization_id: 'org-001',
    user_id: null,
    extension_number: '2002',
    type: 'conference',
    status: 'active',
    voicemail_enabled: false,
    configuration: {
      max_participants: 25,
      recording_enabled: false,
    },
    created_at: '2024-03-05T10:00:00Z',
    updated_at: '2024-03-05T10:00:00Z',
  },

  // Ring Groups
  {
    id: 'ext-201',
    organization_id: 'org-001',
    user_id: null,
    extension_number: '3001',
    type: 'ring_group',
    status: 'active',
    voicemail_enabled: false,
    configuration: {
      strategy: 'simultaneous',
      members: ['1001', '1002', '1003'],
      timeout: 30,
    },
    created_at: '2024-03-10T10:00:00Z',
    updated_at: '2024-03-10T10:00:00Z',
  },
  {
    id: 'ext-202',
    organization_id: 'org-001',
    user_id: null,
    extension_number: '3002',
    type: 'ring_group',
    status: 'active',
    voicemail_enabled: false,
    configuration: {
      strategy: 'round_robin',
      members: ['1001', '1002'],
      timeout: 45,
    },
    created_at: '2024-03-15T10:00:00Z',
    updated_at: '2024-03-15T10:00:00Z',
  },

  // IVR Extension (references IVR Menu by ID)
  {
    id: 'ext-301',
    organization_id: 'org-001',
    user_id: null,
    extension_number: '4001',
    type: 'ivr',
    status: 'active',
    voicemail_enabled: false,
    configuration: {
      ivr_menu_id: 1, // References IVR menu with ID 1
    },
    created_at: '2024-03-20T10:00:00Z',
    updated_at: '2024-03-20T10:00:00Z',
  },

  // AI Assistants
  {
    id: 'ext-401',
    organization_id: 'org-001',
    user_id: null,
    extension_number: '5001',
    type: 'ai_assistant',
    status: 'active',
    voicemail_enabled: false,
    configuration: {
      provider: 'VAPI',
      phone_number: '+12025551234',
    },
    created_at: '2024-04-01T10:00:00Z',
    updated_at: '2024-04-01T10:00:00Z',
  },
  {
    id: 'ext-402',
    organization_id: 'org-001',
    user_id: null,
    extension_number: '5002',
    type: 'ai_assistant',
    status: 'active',
    voicemail_enabled: false,
    configuration: {
      provider: 'Retell',
      phone_number: '+12025555678',
    },
    created_at: '2024-04-05T10:00:00Z',
    updated_at: '2024-04-05T10:00:00Z',
  },

  // Custom Logic
  {
    id: 'ext-501',
    organization_id: 'org-001',
    user_id: null,
    extension_number: '6001',
    type: 'custom_logic',
    status: 'active',
    voicemail_enabled: false,
    configuration: {
      script: `function handleCall(call) {
  // Check time of day
  const hour = new Date().getHours();

  if (hour >= 9 && hour < 17) {
    return call.forward("1001");
  } else {
    return call.voicemail();
  }
}`,
      runtime: 'javascript',
    },
    created_at: '2024-04-10T10:00:00Z',
    updated_at: '2024-04-10T10:00:00Z',
  },

  // Forward Extensions
  {
    id: 'ext-601',
    organization_id: 'org-001',
    user_id: null,
    extension_number: '7001',
    type: 'forward',
    status: 'active',
    voicemail_enabled: false,
    configuration: {
      forward_to: '+12025559999',
    },
    created_at: '2024-04-15T10:00:00Z',
    updated_at: '2024-04-15T10:00:00Z',
  },
  {
    id: 'ext-602',
    organization_id: 'org-001',
    user_id: 'user-004',
    extension_number: '7002',
    type: 'forward',
    status: 'active',
    voicemail_enabled: false,
    configuration: {
      forward_to: '1001', // Forwarding to another extension
    },
    created_at: '2024-04-20T10:00:00Z',
    updated_at: '2024-04-20T10:00:00Z',
  },

  // Some inactive extensions
  {
    id: 'ext-701',
    organization_id: 'org-001',
    user_id: null,
    extension_number: '1010',
    type: 'user',
    status: 'inactive',
    voicemail_enabled: true,
    configuration: {
      voicemail_enabled: true,
    },
    created_at: '2024-05-01T10:00:00Z',
    updated_at: '2024-05-01T10:00:00Z',
  },
];

// Mock users for the assigned to column
export const mockUsersForExtensions = [
  { id: 'user-001', name: 'John Smith', email: 'john.smith@acmecorp.com', role: 'owner' },
  { id: 'user-002', name: 'Sarah Johnson', email: 'sarah.johnson@acmecorp.com', role: 'pbx_admin' },
  { id: 'user-003', name: 'Michael Brown', email: 'michael.brown@acmecorp.com', role: 'pbx_user' },
  { id: 'user-004', name: 'Emily Davis', email: 'emily.davis@acmecorp.com', role: 'pbx_user' },
];

// Helper to get next available extension number
export function getNextExtensionNumber(extensions: Extension[]): string {
  const usedNumbers = extensions
    .map(ext => parseInt(ext.extension_number, 10))
    .filter(num => !isNaN(num));

  if (usedNumbers.length === 0) return '1001';

  const maxNumber = Math.max(...usedNumbers);
  return (maxNumber + 1).toString();
}

// Mock current user for testing different role views
export const mockCurrentUserForExtensions = {
  id: 'user-001',
  name: 'John Smith',
  email: 'john.smith@acmecorp.com',
  role: 'owner', // Change this to test different roles: 'owner', 'pbx_admin', 'pbx_user', 'reporter'
  extension_id: 'ext-001', // For PBX User testing
};
