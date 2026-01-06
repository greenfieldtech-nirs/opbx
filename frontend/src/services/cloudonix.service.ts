/**
 * Cloudonix API Service
 *
 * Handles external Cloudonix API calls
 */

import api from './api';

export interface VoiceOption {
  id: string;
  name: string;
  language: string;
  gender: 'male' | 'female';
  premium?: boolean;
}

export const cloudonixService = {
  /**
   * Get available voices for TTS
   */
  async getVoices(domainId?: string): Promise<VoiceOption[]> {
    try {
      // In a real implementation, this would call the Cloudonix API
      // For now, return mock data that matches the API structure

      // Mock API response structure
      const mockVoices = [
        { id: 'en-US-Neural2-A', name: 'English US - Female (Neural)', language: 'en-US', gender: 'female' as const, premium: false },
        { id: 'en-US-Neural2-D', name: 'English US - Male (Neural)', language: 'en-US', gender: 'male' as const, premium: false },
        { id: 'en-GB-Neural2-A', name: 'English UK - Female (Neural)', language: 'en-GB', gender: 'female' as const, premium: true },
        { id: 'en-GB-Neural2-D', name: 'English UK - Male (Neural)', language: 'en-GB', gender: 'male' as const, premium: true },
        { id: 'es-ES-Neural2-A', name: 'Spanish - Female (Neural)', language: 'es-ES', gender: 'female' as const, premium: true },
        { id: 'fr-FR-Neural2-A', name: 'French - Female (Neural)', language: 'fr-FR', gender: 'female' as const, premium: true },
        { id: 'de-DE-Neural2-A', name: 'German - Female (Neural)', language: 'de-DE', gender: 'female' as const, premium: true },
      ];

      // In production, uncomment this:
      // const response = await api.get(`/cloudonix/domains/${domainId}/resources/voices`);
      // return response.data.map((voice: any) => ({
      //   id: voice.voice_id || voice.id,
      //   name: voice.display_name || voice.name,
      //   language: voice.language_code || voice.language,
      //   gender: voice.gender?.toLowerCase() || 'female',
      //   premium: voice.premium || false,
      // }));

      return mockVoices;
    } catch (error) {
      console.error('Failed to fetch Cloudonix voices:', error);
      // Fallback to basic voices
      return [
        { id: 'en-US-Neural2-A', name: 'English US - Female', language: 'en-US', gender: 'female' as const, premium: false },
        { id: 'en-US-Neural2-D', name: 'English US - Male', language: 'en-US', gender: 'male' as const, premium: false },
      ];
    }
  },
};