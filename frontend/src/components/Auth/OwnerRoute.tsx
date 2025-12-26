/**
 * Owner Route Component
 *
 * Requires owner role to access the route
 * Redirects non-owners to dashboard with error message
 */

import { Navigate } from 'react-router-dom';
import { useAuth } from '@/hooks/useAuth';
import { toast } from 'sonner';
import { useEffect, useRef } from 'react';

interface OwnerRouteProps {
  children: React.ReactNode;
}

export function OwnerRoute({ children }: OwnerRouteProps) {
  const { user, isLoading } = useAuth();
  const hasShownToast = useRef(false);

  // Show loading state while checking authentication
  if (isLoading) {
    return (
      <div className="flex h-screen items-center justify-center">
        <div className="text-center">
          <div className="h-12 w-12 animate-spin rounded-full border-4 border-primary border-t-transparent mx-auto" />
          <p className="mt-4 text-muted-foreground">Loading...</p>
        </div>
      </div>
    );
  }

  // Check if user is owner
  const isOwner = user?.role === 'owner';

  // Show error toast only once when user is not owner
  useEffect(() => {
    if (!isLoading && !isOwner && !hasShownToast.current) {
      toast.error('Access denied', {
        description: 'This page is only accessible to organization owners.',
      });
      hasShownToast.current = true;
    }
  }, [isLoading, isOwner]);

  // Redirect to dashboard if not owner
  if (!isOwner) {
    return <Navigate to="/dashboard" replace />;
  }

  // User is owner, render children
  return <>{children}</>;
}
