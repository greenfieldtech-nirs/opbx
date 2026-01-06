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
  async getVoices(): Promise<VoiceOption[]> {
    try {
      const response = await api.get('/ivr-menus/voices');
      return response.data.data;
    } catch (error) {
      console.error('Failed to fetch TTS voices:', error);
      // Fallback to basic voices
      return [
        { id: 'en-US-Neural2-A', name: 'English US - Female', language: 'en-US', gender: 'female' as const, premium: false },
        { id: 'en-US-Neural2-D', name: 'English US - Male', language: 'en-US', gender: 'male' as const, premium: false },
      ];
    }
  },
};