/**
 * Application Router
 *
 * Defines all routes with protected route logic
 */

import { createBrowserRouter, Navigate } from 'react-router-dom';
import { AppLayout } from '@/components/Layout/AppLayout';
import { ProtectedRoute } from '@/components/Auth/ProtectedRoute';
import { OwnerRoute } from '@/components/Auth/OwnerRoute';
import Login from '@/pages/Login';
import Dashboard from '@/pages/Dashboard';

// Lazy load pages for code splitting
import { lazy } from 'react';

const Users = lazy(() => import('@/pages/UsersComplete'));
const Extensions = lazy(() => import('@/pages/Extensions'));
const ConferenceRooms = lazy(() => import('@/pages/ConferenceRooms'));
const PhoneNumbers = lazy(() => import('@/pages/PhoneNumbers'));
const RingGroups = lazy(() => import('@/pages/RingGroups'));
const BusinessHours = lazy(() => import('@/pages/BusinessHours'));
const CallLogs = lazy(() => import('@/pages/CallLogs'));
const LiveCalls = lazy(() => import('@/pages/LiveCalls'));
const Profile = lazy(() => import('@/pages/Profile'));
const Settings = lazy(() => import('@/pages/Settings'));

export const router = createBrowserRouter([
  {
    path: '/login',
    element: <Login />,
  },
  {
    path: '/',
    element: (
      <ProtectedRoute>
        <AppLayout />
      </ProtectedRoute>
    ),
    children: [
      {
        index: true,
        element: <Navigate to="/dashboard" replace />,
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
        path: 'dids',
        element: <PhoneNumbers />,
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
        path: 'business-hours',
        element: <BusinessHours />,
      },
      {
        path: 'call-logs',
        element: <CallLogs />,
      },
      {
        path: 'live-calls',
        element: <LiveCalls />,
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
