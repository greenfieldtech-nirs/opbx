/**
 * Application Router
 *
 * Unified router handling both public and protected routes
 */

import { createBrowserRouter, Navigate } from 'react-router-dom';
import { AppLayout } from '@/components/Layout/AppLayout';
import { ProtectedRoute } from '@/components/Auth/ProtectedRoute';
import { OwnerRoute } from '@/components/Auth/OwnerRoute';
import { PlatformManagerRoute } from '@/components/platform/PlatformManagerRoute';
import Login from '@/pages/Login';
import Register from '@/pages/Register';
import Dashboard from '@/pages/Dashboard';
import Home from '@/pages/Home';

// Lazy load pages for code splitting
import { lazy } from 'react';

const Users = lazy(() => import('@/pages/UsersComplete'));
const Extensions = lazy(() => import('@/pages/Extensions'));
const ConferenceRooms = lazy(() => import('@/pages/ConferenceRooms'));
const AiAssistants = lazy(() => import('@/pages/AiAssistants'));
const AiAssistantLoadBalancers = lazy(() => import('@/pages/AiAssistantLoadBalancers'));
const PhoneNumbers = lazy(() => import('@/pages/PhoneNumbers'));
const RingGroups = lazy(() => import('@/pages/RingGroups'));
const IVRMenus = lazy(() => import('@/pages/IVRMenus'));
const BusinessHours = lazy(() => import('@/pages/BusinessHours'));
const CallLogs = lazy(() => import('@/pages/CallLogs'));
const LiveCalls = lazy(() => import('@/pages/LiveCalls'));
const Recordings = lazy(() => import('@/pages/Recordings'));
const Profile = lazy(() => import('@/pages/Profile'));
const Settings = lazy(() => import('@/pages/Settings'));
const OutboundWhitelistPage = lazy(() => import('@/pages/OutboundWhitelist'));
const InboundBlacklistPage = lazy(() => import('@/pages/InboundBlacklist'));
const CallNotificationsSettings = lazy(() => import('@/pages/CallNotificationsSettings'));
const AutoDialerCampaigns = lazy(() => import('@/pages/AutoDialerCampaigns'));
const AutoDialerCampaignDetail = lazy(() => import('@/pages/AutoDialerCampaignDetail'));

// Platform Management (lazy loaded)
const PlatformDashboard = lazy(() => import('@/pages/platform/PlatformDashboard'));
const PlatformOrganizations = lazy(() => import('@/pages/platform/PlatformOrganizations'));
const PlatformOrganizationDetail = lazy(() => import('@/pages/platform/PlatformOrganizationDetail'));
const PlatformUsers = lazy(() => import('@/pages/platform/PlatformUsers'));
const PlatformAuditLog = lazy(() => import('@/pages/platform/PlatformAuditLog'));


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
  // Protected app routes (under /ui)
  {
    path: '/ui',
    element: (
      <ProtectedRoute>
        <AppLayout />
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
        path: 'recordings',
        element: <Recordings />,
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
        path: 'auto-dialer',
        element: <AutoDialerCampaigns />,
      },
      {
        path: 'auto-dialer/:id',
        element: <AutoDialerCampaignDetail />,
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
