/**
 * Supervisor Guard
 *
 * Supervisors may only reach a fixed set of read-only pages. If a supervisor
 * navigates (or deep-links) anywhere else under /ui, redirect them to their
 * dashboard with a toast. Non-supervisors are unaffected.
 *
 * This is UX enforcement only — the backend policies/abilities are the real
 * security boundary.
 */

import { Navigate, useLocation } from 'react-router-dom';
import { useAuth } from '@/hooks/useAuth';
import { toast } from 'sonner';
import { useEffect, useRef } from 'react';

// Route prefixes a supervisor is allowed to access (matches the nav items).
const SUPERVISOR_ALLOWED_PREFIXES = [
  '/ui/dashboard',
  '/ui/live-calls',
  '/ui/call-logs',
  '/ui/users',
  '/ui/ring-groups',
];

interface SupervisorGuardProps {
  children: React.ReactNode;
}

export function SupervisorGuard({ children }: SupervisorGuardProps) {
  const { user } = useAuth();
  const location = useLocation();
  const hasShownToast = useRef(false);

  const isSupervisor = user?.role === 'supervisor';
  const isAllowed = SUPERVISOR_ALLOWED_PREFIXES.some(
    (prefix) => location.pathname === prefix || location.pathname.startsWith(prefix + '/')
  );
  const blocked = isSupervisor && !isAllowed;

  useEffect(() => {
    if (blocked && !hasShownToast.current) {
      toast.error('Access denied', {
        description: 'Supervisors can only access their assigned dashboards and reports.',
      });
      hasShownToast.current = true;
    }
    if (!blocked) {
      hasShownToast.current = false;
    }
  }, [blocked]);

  if (blocked) {
    return <Navigate to="/ui/dashboard" replace />;
  }

  return <>{children}</>;
}
