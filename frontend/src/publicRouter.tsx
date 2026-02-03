/**
 * Public Router
 * 
 * Handles public routes (homepage) that don't require authentication
 */

import { createBrowserRouter } from 'react-router-dom';
import Home from '@/pages/Home';

export const publicRouter = createBrowserRouter([
  {
    path: '/',
    element: <Home />,
  },
  {
    path: '*',
    // Redirect any unknown routes to /ui/login
    element: (() => {
      window.location.href = '/ui/login';
      return null;
    })(),
  },
]);
