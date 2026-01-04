import api from './api';
import type {
    RoutingSentrySettings,
    SentryBlacklist,
    SentryBlacklistItem,
    StoreSentryBlacklistRequest,
    UpdateSentryBlacklistRequest,
    StoreSentryBlacklistItemRequest,
    UpdateSentrySettingsRequest,
} from '@/types/api.types';

export const sentryService = {
    /**
     * Get organization-level sentry settings
     */
    getSettings: async (): Promise<RoutingSentrySettings> => {
        const response = await api.get<{ data: RoutingSentrySettings }>('/sentry/settings');
        return response.data.data;
    },

    /**
     * Update organization-level sentry settings
     */
    updateSettings: async (data: UpdateSentrySettingsRequest): Promise<RoutingSentrySettings> => {
        const response = await api.put<{ data: RoutingSentrySettings }>('/sentry/settings', data);
        return response.data.data;
    },

    /**
     * List all blacklists
     */
    listBlacklists: async (): Promise<SentryBlacklist[]> => {
        const response = await api.get<{ data: SentryBlacklist[] }>('/sentry/blacklists');
        return response.data.data;
    },

    /**
     * Get blacklist details with items
     */
    getBlacklist: async (id: string): Promise<SentryBlacklist> => {
        const response = await api.get<{ data: SentryBlacklist }>(`/sentry/blacklists/${id}`);
        return response.data.data;
    },

    /**
     * Create new blacklist
     */
    createBlacklist: async (data: StoreSentryBlacklistRequest): Promise<SentryBlacklist> => {
        const response = await api.post<{ data: SentryBlacklist }>('/sentry/blacklists', data);
        return response.data.data;
    },

    /**
     * Update blacklist
     */
    updateBlacklist: async (id: string, data: UpdateSentryBlacklistRequest): Promise<SentryBlacklist> => {
        const response = await api.put<{ data: SentryBlacklist }>(`/sentry/blacklists/${id}`, data);
        return response.data.data;
    },

    /**
     * Delete blacklist
     */
    deleteBlacklist: async (id: string): Promise<void> => {
        await api.delete(`/sentry/blacklists/${id}`);
    },

    /**
     * Add item to blacklist
     */
    addItem: async (blacklistId: string, data: StoreSentryBlacklistItemRequest): Promise<SentryBlacklistItem> => {
        const response = await api.post<{ data: SentryBlacklistItem }>(`/sentry/blacklists/${blacklistId}/items`, data);
        return response.data.data;
    },

    /**
     * Remove item from blacklist
     */
    removeItem: async (blacklistId: string, itemId: string): Promise<void> => {
        await api.delete(`/sentry/blacklists/${blacklistId}/items/${itemId}`);
    },
};
