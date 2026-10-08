/**
 * Application Router
 *
 * Unified router handling both public and protected routes
 */

import { createBrowserRouter, Navigate } from 'react-router-dom';
import { AppLayout } from '@/components/Layout/AppLayout';
import { ProtectedRoute } from '@/components/Auth/ProtectedRoute';
import { OwnerRoute } from '@/components/Auth/OwnerRoute';
import { SupervisorGuard } from '@/components/Auth/SupervisorGuard';
import { PlatformManagerRoute } from '@/components/platform/PlatformManagerRoute';
import Login from '@/pages/Login';
import Register from '@/pages/Register';
import Auth0Callback from '@/pages/Auth0Callback';
import Auth0Onboarding from '@/pages/Auth0Onboarding';
import Dashboard from '@/pages/Dashboard';
import Home from '@/pages/Home';

// Lazy load pages for code splitting. trackedLazy() is React.lazy() plus a
// signal to the global page loading indicator while the chunk downloads.
import { trackedLazy } from '@/lib/pageLoadingStore';

const Users = trackedLazy(() => import('@/pages/UsersComplete'));
const Supervisors = trackedLazy(() => import('@/pages/Supervisors'));
const Extensions = trackedLazy(() => import('@/pages/Extensions'));
const ConferenceRooms = trackedLazy(() => import('@/pages/ConferenceRooms'));
const AiAssistants = trackedLazy(() => import('@/pages/AiAssistants'));
const AiAssistantLoadBalancers = trackedLazy(() => import('@/pages/AiAssistantLoadBalancers'));
const PhoneNumbers = trackedLazy(() => import('@/pages/PhoneNumbers'));
const RingGroups = trackedLazy(() => import('@/pages/RingGroups'));
const CallQueues = trackedLazy(() => import('@/pages/CallQueues'));
const CallQueueDetail = trackedLazy(() => import('@/pages/CallQueueDetail'));
const QueueReports = trackedLazy(() => import('@/pages/QueueReports'));
const QueuesDashboard = trackedLazy(() => import('@/pages/QueuesDashboard'));
const PublicQueuesDashboard = trackedLazy(() => import('@/pages/PublicQueuesDashboard'));
const IVRMenus = trackedLazy(() => import('@/pages/IVRMenus'));
const BusinessHours = trackedLazy(() => import('@/pages/BusinessHours'));
const CallLogs = trackedLazy(() => import('@/pages/CallLogs'));
const LiveCalls = trackedLazy(() => import('@/pages/LiveCalls'));
const Announcements = trackedLazy(() => import('@/pages/Announcements'));
const Profile = trackedLazy(() => import('@/pages/Profile'));
const Settings = trackedLazy(() => import('@/pages/Settings'));
const OutboundWhitelistPage = trackedLazy(() => import('@/pages/OutboundWhitelist'));
const InboundBlacklistPage = trackedLazy(() => import('@/pages/InboundBlacklist'));
const TrunksPage = trackedLazy(() => import('@/pages/Trunks'));
const CallNotificationsSettings = trackedLazy(() => import('@/pages/CallNotificationsSettings'));
const ApiKeysSettings = trackedLazy(() => import('@/pages/ApiKeysSettings'));
const AutoDialerCampaigns = trackedLazy(() => import('@/pages/AutoDialerCampaigns'));
const AutoDialerCampaignDetail = trackedLazy(() => import('@/pages/AutoDialerCampaignDetail'));
const AutoDialerCampaignForm = trackedLazy(() => import('@/pages/AutoDialerCampaignForm'));
const AutoDialerUploadList = trackedLazy(() => import('@/pages/AutoDialerUploadList'));
const AutoDialerMonitor = trackedLazy(() => import('@/pages/AutoDialerMonitor'));
const DistributionLists = trackedLazy(() => import('@/pages/DistributionLists'));
const AcceptInvitation = trackedLazy(() => import('@/pages/AcceptInvitation'));
const DistributionListDetail = trackedLazy(() => import('@/pages/DistributionListDetail'));
const CallTrackingDashboard = trackedLazy(() => import('@/pages/CallTrackingDashboard'));
const CallTrackingCampaigns = trackedLazy(() => import('@/pages/CallTrackingCampaigns'));
const CallTrackingCampaignForm = trackedLazy(() => import('@/pages/CallTrackingCampaignForm'));
const CallTrackingCampaignDetail = trackedLazy(() => import('@/pages/CallTrackingCampaignDetail'));
const CallTrackingSessions = trackedLazy(() => import('@/pages/CallTrackingSessions'));
const CallTrackingDniSnippet = trackedLazy(() => import('@/pages/CallTrackingDniSnippet'));
const CallTrackingIntegrations = trackedLazy(() => import('@/pages/CallTrackingIntegrations'));

// Platform Management (lazy loaded)
const PlatformDashboard = trackedLazy(() => import('@/pages/platform/PlatformDashboard'));
const PlatformOrganizations = trackedLazy(() => import('@/pages/platform/PlatformOrganizations'));
const PlatformOrganizationDetail = trackedLazy(() => import('@/pages/platform/PlatformOrganizationDetail'));
const PlatformUsers = trackedLazy(() => import('@/pages/platform/PlatformUsers'));
const PlatformAuditLog = trackedLazy(() => import('@/pages/platform/PlatformAuditLog'));


// Unified router - NO basename, handles all routes
export const router = createBrowserRouter([
  // Public homepage
  {
    path: '/',
    element: <Home />,
  },
  // Public auth routes (under /ui for consistency with existing setup)
  {
    path: '/ui/login',
    element: <Login />,
  },
  {
    path: '/ui/register',
    element: <Register />,
  },
  {
    path: '/ui/auth/callback',
    element: <Auth0Callback />,
  },
  {
    path: '/ui/auth/onboarding',
    element: <Auth0Onboarding />,
  },
  {
    path: '/ui/invite',
    element: <AcceptInvitation />,
  },
  {
    path: '/public/queues-dashboard/:token',
    element: <PublicQueuesDashboard />,
  },
  // Protected app routes (under /ui)
  {
    path: '/ui',
    element: (
      <ProtectedRoute>
        <SupervisorGuard>
          <AppLayout />
        </SupervisorGuard>
      </ProtectedRoute>
    ),
    children: [
      {
        index: true,
        element: <Navigate to="/ui/dashboard" replace />,
      },
      {
        path: 'dashboard',
        element: <Dashboard />,
      },
      {
        path: 'users',
        element: <Users />,
      },
      {
        path: 'supervisors',
        element: <Supervisors />,
      },
      {
        path: 'extensions',
        element: <Extensions />,
      },
      {
        path: 'conference-rooms',
        element: <ConferenceRooms />,
      },
      {
        path: 'ai-assistants',
        element: <AiAssistants />,
      },
      {
        path: 'ai-assistant-load-balancers',
        element: <AiAssistantLoadBalancers />,
      },
      {
        path: 'phone-numbers',
        element: <PhoneNumbers />,
      },
      {
        path: 'ring-groups',
        element: <RingGroups />,
      },
      {
        path: 'call-queues',
        element: <CallQueues />,
      },
      {
        path: 'call-queues/:id',
        element: <CallQueueDetail />,
      },
      {
        path: 'queue-reports',
        element: <QueueReports />,
      },
      {
        path: 'queues-dashboard',
        element: <QueuesDashboard />,
      },
      {
        path: 'ivr-menus',
        element: <IVRMenus />,
      },
      {
        path: 'business-hours',
        element: <BusinessHours />,
      },
      {
        path: 'call-logs',
        element: <CallLogs />,
      },
      {
        path: 'announcements',
        element: <Announcements />,
      },
      {
        path: 'recordings',
        element: <Navigate to="/ui/announcements" replace />,
      },
      {
        path: 'live-calls',
        element: <LiveCalls />,
      },
      {
        path: 'outbound-whitelist',
        element: (
          <OwnerRoute>
            <OutboundWhitelistPage />
          </OwnerRoute>
        ),
      },
      {
        path: 'inbound-blacklist',
        element: <InboundBlacklistPage />,
      },
      {
        path: 'trunks',
        element: (
          <OwnerRoute roles={['owner', 'pbx_admin']}>
            <TrunksPage />
          </OwnerRoute>
        ),
      },
      {
        path: 'profile',
        element: <Profile />,
      },
      {
        path: 'settings',
        element: (
          <OwnerRoute>
            <Settings />
          </OwnerRoute>
        ),
      },
      {
        path: 'call-notifications',
        element: <CallNotificationsSettings />,
      },
      {
        path: 'api-keys',
        element: (
          <OwnerRoute>
            <ApiKeysSettings />
          </OwnerRoute>
        ),
      },
      {
        path: 'auto-dialer',
        element: <Navigate to="/ui/auto-dialer/campaigns" replace />,
      },
      {
        path: 'auto-dialer/campaigns',
        element: <AutoDialerCampaigns />,
      },
      {
        path: 'auto-dialer/campaigns/new',
        element: <AutoDialerCampaignForm />,
      },
      {
        path: 'auto-dialer/campaigns/:id',
        element: <AutoDialerCampaignDetail />,
      },
      {
        path: 'auto-dialer/campaigns/:id/edit',
        element: <AutoDialerCampaignForm />,
      },
      {
        path: 'auto-dialer/campaigns/:id/upload',
        element: <AutoDialerUploadList />,
      },
      {
        path: 'auto-dialer/distribution-lists',
        element: <DistributionLists />,
      },
      {
        path: 'auto-dialer/distribution-lists/:id',
        element: <DistributionListDetail />,
      },
      {
        path: 'auto-dialer/monitor',
        element: <AutoDialerMonitor />,
      },
      {
        path: 'call-tracking',
        element: <Navigate to="/ui/call-tracking/dashboard" replace />,
      },
      {
        path: 'call-tracking/dashboard',
        element: <CallTrackingDashboard />,
      },
      {
        path: 'call-tracking/campaigns',
        element: <CallTrackingCampaigns />,
      },
      {
        path: 'call-tracking/campaigns/new',
        element: <CallTrackingCampaignForm />,
      },
      {
        path: 'call-tracking/campaigns/:id',
        element: <CallTrackingCampaignDetail />,
      },
      {
        path: 'call-tracking/campaigns/:id/edit',
        element: <CallTrackingCampaignForm />,
      },
      {
        path: 'call-tracking/sessions',
        element: <CallTrackingSessions />,
      },
      {
        path: 'call-tracking/dni-snippet',
        element: <CallTrackingDniSnippet />,
      },
      {
        path: 'call-tracking/integrations',
        element: (
          <OwnerRoute>
            <CallTrackingIntegrations />
          </OwnerRoute>
        ),
      },
      // Platform Management routes (platform manager only)
      {
        path: 'platform',
        element: (
          <PlatformManagerRoute>
            <Navigate to="/ui/platform/dashboard" replace />
          </PlatformManagerRoute>
        ),
      },
      {
        path: 'platform/dashboard',
        element: (
          <PlatformManagerRoute>
            <PlatformDashboard />
          </PlatformManagerRoute>
        ),
      },
      {
        path: 'platform/organizations',
        element: (
          <PlatformManagerRoute>
            <PlatformOrganizations />
          </PlatformManagerRoute>
        ),
      },
      {
        path: 'platform/organizations/:id',
        element: (
          <PlatformManagerRoute>
            <PlatformOrganizationDetail />
          </PlatformManagerRoute>
        ),
      },
      {
        path: 'platform/users',
        element: (
          <PlatformManagerRoute>
            <PlatformUsers />
          </PlatformManagerRoute>
        ),
      },
      {
        path: 'platform/audit-log',
        element: (
          <PlatformManagerRoute>
            <PlatformAuditLog />
          </PlatformManagerRoute>
        ),
      },
    ],
  },
]);
