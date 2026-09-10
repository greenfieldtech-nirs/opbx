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
  /** Allowed roles — defaults to owner only */
  roles?: string[];
}

export function OwnerRoute({ children, roles = ['owner'] }: OwnerRouteProps) {
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

  // Check if user has an allowed role
  const isAllowed = !!user?.role && roles.includes(user.role);

  // Show error toast only once when user lacks access
  useEffect(() => {
    if (!isLoading && !isAllowed && !hasShownToast.current) {
      toast.error('Access denied', {
        description: 'You do not have permission to access this page.',
      });
      hasShownToast.current = true;
    }
  }, [isLoading, isAllowed]);

  // Redirect to dashboard if not allowed
  if (!isAllowed) {
    return <Navigate to="/ui/dashboard" replace />;
  }

  // User is owner, render children
  return <>{children}</>;
}
