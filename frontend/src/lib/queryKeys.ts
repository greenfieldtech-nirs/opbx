/**
 * Central TanStack Query key factory.
 *
 * Every resource has an `all` root key; invalidating it invalidates every list,
 * detail and lookup under that resource. Lookup lists that take params embed
 * the params in the key so two pages asking for different rows (e.g. per_page
 * 100 vs 1000) never share - and poison - one cache entry.
 */
type Params = Record<string, unknown> | undefined;

export const queryKeys = {
  phoneNumbers: {
    all: ['phone-numbers'] as const,
    list: (params?: Params) => ['phone-numbers', 'list', params ?? {}] as const,
    // Dropdown lookup of active DIDs, shared by PhoneNumbers/Extensions/OutboundWhitelist.
    activeForCallerId: () => ['phone-numbers', 'active-for-caller-id', { per_page: 100 }] as const,
    defaultCallerId: () => ['default-caller-id'] as const,
  },
  ivrMenus: {
    all: ['ivr-menus'] as const,
    list: (params?: Params) => ['ivr-menus', 'list', params ?? {}] as const,
  },
  callQueues: {
    all: ['call-queues'] as const,
    list: (params?: Params) => ['call-queues', 'list', params ?? {}] as const,
    detail: (id: string | number | undefined) => ['call-queues', 'detail', id] as const,
  },
  queuesDashboard: {
    link: () => ['queues-dashboard-link'] as const,
    live: (queueId: string | number | undefined) => ['queues-dashboard', queueId, 'live'] as const,
    stats: (queueId: string | number | undefined, timespan: string) =>
      ['queues-dashboard', queueId, 'stats', timespan] as const,
  },
  dashboard: {
    supervisor: () => ['dashboard', 'supervisor'] as const,
    activeCalls: () => ['dashboard', 'active-calls'] as const,
    extensionsCount: () => ['dashboard', 'extensions-count'] as const,
    conferenceRoomsCount: () => ['dashboard', 'conference-rooms-count'] as const,
    phoneNumbersCount: () => ['dashboard', 'phone-numbers-count'] as const,
    lastCalls: () => ['dashboard', 'last-calls'] as const,
  },
  activeCalls: {
    all: ['active-calls'] as const,
    list: (scope: 'supervisor' | 'all') => ['active-calls', scope] as const,
  },
  cdrs: {
    all: ['cdrs'] as const,
    list: (...parts: unknown[]) => ['cdrs', ...parts] as const,
  },
  queueCalls: {
    list: (params: Params) => ['queue-calls', params ?? {}] as const,
  },
  recordings: {
    all: ['recordings'] as const,
    list: (params?: Params) => ['recordings', 'list', params ?? {}] as const,
  },
  cloudonix: {
    voices: () => ['cloudonix-voices'] as const,
    settings: () => ['cloudonix-settings'] as const,
  },
};
