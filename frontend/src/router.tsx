/**
 * Application Router
 *
 * Unified router handling both public and protected routes
 */

import { createBrowserRouter, Navigate } from 'react-router-dom';
import { AppLayout } from '@/components/Layout/AppLayout';
import { ProtectedRoute } from '@/components/Auth/ProtectedRoute';
import { OwnerRoute } from '@/components/Auth/OwnerRoute';
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

     ],
  },
]);
