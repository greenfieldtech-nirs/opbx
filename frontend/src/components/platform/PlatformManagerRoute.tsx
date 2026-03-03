/**
 * Platform Manager Route Guard
 *
 * Protects platform routes by checking if the user is a platform manager.
 * Shows PlatformErrorPage if not authorized.
 */

import { Navigate } from 'react-router-dom';
import { useAuth } from '@/hooks/useAuth';
import { PlatformErrorPage } from './PlatformErrorPage';

interface PlatformManagerRouteProps {
  children: React.ReactNode;
}

export function PlatformManagerRoute({ children }: PlatformManagerRouteProps) {
  const { user, isAuthenticated, isLoading } = useAuth();

  if (isLoading) {
    return (
      <div className="flex items-center justify-center min-h-screen">
        <div className="animate-spin rounded-full h-8 w-8 border-b-2 border-primary"></div>
      </div>
    );
  }

  if (!isAuthenticated) {
    return <Navigate to="/login" replace />;
  }

  if (!user?.is_platform_manager) {
    return <PlatformErrorPage />;
  }

  return <>{children}</>;
}
