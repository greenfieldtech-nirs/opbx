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
    all: ['queues-dashboard'] as const,
    link: () => ['queues-dashboard-link'] as const,
    live: (queueId: string | number | undefined) => ['queues-dashboard', queueId, 'live'] as const,
    stats: (queueId: string | number | undefined, timespan: string) =>
      ['queues-dashboard', queueId, 'stats', timespan] as const,
  },
  dashboard: {
    all: ['dashboard'] as const,
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
  users: {
    all: ['users'] as const,
    queueAgents: () => ['users', 'queue-agents'] as const,
  },
  extensions: { all: ['extensions'] as const },
  ringGroups: {
    all: ['ring-groups'] as const,
    // Unpaginated lookup and the call-queue picker inside the ring group form.
    lookup: () => ['ring-groups-all'] as const,
    formCallQueues: () => ['ring-groups-form', 'call-queues'] as const,
  },
  conferenceRooms: { all: ['conference-rooms'] as const },
  businessHours: { all: ['business-hours'] as const },
  aiAssistants: { all: ['ai-assistants'] as const },
  aiLoadBalancers: { all: ['ai-assistant-load-balancers'] as const },
  // Destination pickers (useDestinations) cache every routable resource under this root.
  destinations: { all: ['destinations'] as const },
  trunks: { all: ['trunks'] as const, outbound: () => ['outbound-trunks'] as const },
  outboundWhitelist: { all: ['outbound-whitelist'] as const },
  inboundBlacklist: { all: ['inbound-blacklist'] as const },
  announcements: { all: ['announcements'] as const },
  supervisorAssignments: {
    all: ['supervisor-assignments'] as const,
    forUser: (userId: string | number) => ['supervisor-assignments', userId] as const,
  },
  embedToken: { forUser: (userId: string | number | null | undefined) => ['embed-token', userId] as const },
  myQueueMemberships: { all: ['my-queue-memberships'] as const },
  callNotifications: {
    settings: () => ['call-notifications-settings'] as const,
  },
  platform: { all: ['platform'] as const, status: () => ['platform', 'status'] as const },
  publicStatus: { all: ['public-status'] as const },
  cloudonix: {
    voices: () => ['cloudonix-voices'] as const,
    settings: () => ['cloudonix-settings'] as const,
  },
};
