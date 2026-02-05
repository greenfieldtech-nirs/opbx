import apiClient from './api';
import type { ProvidersResponse, ProviderResponse, ProviderProtocol } from '../types/aiAssistant';

/**
 * Service for fetching AI Assistant provider metadata.
 */
class AiAssistantProvidersService {
  /**
   * Get all AI Assistant providers.
   */
  async getAll(): Promise<ProvidersResponse> {
    const response = await apiClient.get<ProvidersResponse>('/ai-assistant/providers');
    return response.data;
  }

  /**
   * Get a specific provider by key.
   */
  async getProvider(providerKey: string): Promise<ProviderResponse> {
    const response = await apiClient.get<ProviderResponse>(`/ai-assistant/providers/${providerKey}`);
    return response.data;
  }

  /**
   * Get providers filtered by protocol.
   */
  async getByProtocol(protocol: ProviderProtocol): Promise<ProvidersResponse> {
    const response = await apiClient.get<ProvidersResponse>(`/ai-assistant/providers/protocol/${protocol}`);
    return response.data;
  }
}

export default new AiAssistantProvidersService();
