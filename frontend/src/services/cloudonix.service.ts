/**
 * Cloudonix API Service
 *
 * Handles external Cloudonix API calls
 */

import api from './api';

export interface VoiceOption {
  id: string;
  name: string;
  provider?: string;
  language: string;
  gender: 'male' | 'female' | 'neutral';
  premium: boolean;
  pricing: 'standard' | 'premium';
}

export interface VoiceFilters {
  languages: Array<{ code: string; name: string }>;
  genders: string[];
  providers: string[];
  pricing: string[];
}

export interface VoicesResponse {
  data: VoiceOption[];
  filters: VoiceFilters;
}

export interface VoiceTrunk {
  id: string;
  name: string;
  provider: string;
  type: 'sip' | 'pstn' | 'ip';
  status: 'active' | 'inactive';
  capabilities: string[];
  region?: string;
  priority: number;
  created_at: string;
  updated_at: string;
}

export interface VoiceTrunksResponse {
  data: VoiceTrunk[];
  meta?: {
    total: number;
  };
}

export const cloudonixService = {
  /**
   * Get available voices for TTS
   */
  async getVoices(): Promise<VoicesResponse> {
    try {
      const response = await api.get('/ivr-menus/voices');
      return response.data;
    } catch (error) {
      console.error('Failed to fetch TTS voices:', error);
      // Fallback to basic voices
      return {
        data: [
          { id: 'en-US-Neural2-A', name: 'English US - Female', language: 'en-US', gender: 'female' as const, premium: false, pricing: 'standard' as const },
          { id: 'en-US-Neural2-D', name: 'English US - Male', language: 'en-US', gender: 'male' as const, premium: false, pricing: 'standard' as const },
        ],
        filters: {
          languages: [
            { code: 'en-US', name: 'English (United States)' }
          ],
          genders: ['female', 'male'],
          providers: ['Cloudonix-Neural'],
          pricing: ['standard']
        }
      };
    }
  },

  /**
   * Get available voice trunks for outbound calls
   */
  async getVoiceTrunks(): Promise<VoiceTrunksResponse> {
    try {
      const response = await api.get('/cloudonix/voice-trunks');
      return response.data;
    } catch (error) {
      console.error('Failed to fetch voice trunks:', error);
      // Fallback to mock data
      return {
        data: [
          {
            id: 'trunk-001',
            name: 'Primary SIP Trunk',
            provider: 'Cloudonix',
            type: 'sip' as const,
            status: 'active' as const,
            capabilities: ['outbound', 'inbound'],
            region: 'us-east',
            priority: 1,
            created_at: new Date().toISOString(),
            updated_at: new Date().toISOString(),
          },
          {
            id: 'trunk-002',
            name: 'Backup PSTN Trunk',
            provider: 'Cloudonix',
            type: 'pstn' as const,
            status: 'active' as const,
            capabilities: ['outbound'],
            region: 'us-west',
            priority: 2,
            created_at: new Date().toISOString(),
            updated_at: new Date().toISOString(),
          },
        ],
        meta: { total: 2 },
      };
    }
  },
};