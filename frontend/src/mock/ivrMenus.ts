/**
 * Mock IVR Menus Data
 *
 * Used for development and testing when backend API is not available
 */

import type { IvrMenu } from '@/types/api.types';

export const mockIvrMenus: IvrMenu[] = [
  {
    id: '1',
    organization_id: 'org-001',
    name: 'Main Menu',
    description: 'Primary IVR menu for incoming calls',
    audio_file_path: null,
    tts_text: 'Thank you for calling. Press 1 for Sales, 2 for Support, 3 for Billing.',
    tts_voice: 'en-US-Neural2-D',
    max_turns: 3,
    failover_destination_type: 'extension',
    failover_destination_id: '1001',
    status: 'active',
    options: [
      {
        input_digits: '1',
        description: 'Sales Department',
        destination_type: 'extension',
        destination_id: '1001',
        priority: 1,
      },
      {
        input_digits: '2',
        description: 'Technical Support',
        destination_type: 'extension',
        destination_id: '1002',
        priority: 2,
      },
      {
        input_digits: '3',
        description: 'Billing',
        destination_type: 'extension',
        destination_id: '1003',
        priority: 3,
      },
      {
        input_digits: '0',
        description: 'Operator',
        destination_type: 'extension',
        destination_id: '1001',
        priority: 4,
      },
    ],
    created_at: '2024-01-01T00:00:00Z',
    updated_at: '2024-01-01T00:00:00Z',
  },
  {
    id: '2',
    organization_id: 'org-001',
    name: 'After Hours Menu',
    description: 'IVR menu for calls outside business hours',
    audio_file_path: null,
    tts_text: 'Thank you for calling outside our business hours. Press 1 to leave a voicemail.',
    tts_voice: 'en-US-Neural2-D',
    max_turns: 2,
    failover_destination_type: 'hangup',
    status: 'active',
    options: [
      {
        input_digits: '1',
        description: 'Leave Voicemail',
        destination_type: 'hangup',
        destination_id: '',
        priority: 1,
      },
    ],
    created_at: '2024-01-02T00:00:00Z',
    updated_at: '2024-01-02T00:00:00Z',
  },
  {
    id: '3',
    organization_id: 'org-001',
    name: 'Sales Department Menu',
    description: 'Specialized menu for sales inquiries',
    audio_file_path: null,
    tts_text: 'Sales department. Press 1 for new customers, 2 for existing customers.',
    tts_voice: 'en-US-Neural2-D',
    max_turns: 3,
    failover_destination_type: 'extension',
    failover_destination_id: '1001',
    status: 'active',
    options: [
      {
        input_digits: '1',
        description: 'New Customers',
        destination_type: 'ring_group',
        destination_id: '1',
        priority: 1,
      },
      {
        input_digits: '2',
        description: 'Existing Customers',
        destination_type: 'extension',
        destination_id: '1004',
        priority: 2,
      },
    ],
    created_at: '2024-01-03T00:00:00Z',
    updated_at: '2024-01-03T00:00:00Z',
  },
];