/**
 * Operate-As Context (Platform Owner Impersonation)
 *
 * Manages the "Operate as Organization" session in which a platform owner
 * acts as an organization admin (owner) of a target org.
 *
 * The actual per-request context switch is driven by the
 * `X-Operate-As-Organization` header (see services/api.ts). This context
 * coordinates the enter/exit lifecycle: calling the platform validate/audit
 * endpoints, persisting the target org to storage, clearing the query cache
 * and sidebar state, and refreshing the effective user from `/me`.
 *
 * NOTE ON NAVIGATION: this provider lives OUTSIDE the router (it wraps
 * RouterProvider), so `useNavigate` is not available here. `enter`/`exit`
 * therefore perform only API + storage + cache + refresh work and return.
 * The calling UI component (which is inside the router) is responsible for
 * navigation after awaiting these methods.
 */

import React, { createContext, useContext, useState } from 'react';
import { useQueryClient } from '@tanstack/react-query';
import { useAuth } from '@/hooks/useAuth';
import { platformApi } from '@/services/platformApi';
import { getApiErrorMessage } from '@/services/api';
import { storage, type OperateAsOrg } from '@/utils/storage';
import { clearSidebarState } from '@/components/Layout/Sidebar';
import { toast } from 'sonner';

interface OperateAsContextType {
  /** The org currently being operated-as, or null when not operating-as. */
  operateAsOrg: OperateAsOrg | null;
  /** Convenience boolean; true when an operate-as session is active. */
  isOperatingAs: boolean;
  /**
   * Begin operating as the given organization. On success the target org is
   * persisted, caches are cleared, and the effective user is refreshed from
   * `/me`. Throws on failure (nothing is persisted). Navigation is the
   * caller's responsibility (e.g. navigate('/ui/dashboard')).
   */
  enter: (org: OperateAsOrg) => Promise<void>;
  /**
   * Stop operating-as and restore the real platform-owner identity. Errors
   * from the stop endpoint are ignored (local cleanup always runs). Navigation
   * is the caller's responsibility (e.g. navigate('/ui/platform/organizations')).
   */
  exit: () => Promise<void>;
}

const OperateAsContext = createContext<OperateAsContextType | undefined>(undefined);

export function OperateAsProvider({ children }: { children: React.ReactNode }) {
  const queryClient = useQueryClient();
  const { refreshUser } = useAuth();
  const [operateAsOrg, setOperateAsOrg] = useState<OperateAsOrg | null>(
    storage.getOperateAsOrg(),
  );

  const enter = async (org: OperateAsOrg): Promise<void> => {
    try {
      // Validate the target org + write the audit entry BEFORE enabling the header.
      await platformApi.operateAs.start(org.id);
    } catch (error) {
      toast.error(getApiErrorMessage(error));
      throw error;
    }

    // Persist first so the interceptor attaches the header on refreshUser().
    storage.setOperateAsOrg(org);
    setOperateAsOrg(org);

    // Drop all cached data + sidebar state so the org-admin experience is fresh.
    queryClient.clear();
    clearSidebarState();

    // Refresh the effective user from /me (now resolves as the org owner).
    await refreshUser();

    toast.success(`Now operating as ${org.name}`);
  };

  const exit = async (): Promise<void> => {
    const org = operateAsOrg;

    // Best-effort stop (audit) — ignore errors so local cleanup always runs.
    try {
      await platformApi.operateAs.stop(org?.id);
    } catch {
      // Intentionally ignored.
    }

    // Clear storage BEFORE refreshUser() so the header is dropped and /me
    // returns the real platform-owner identity.
    storage.clearOperateAsOrg();
    setOperateAsOrg(null);

    queryClient.clear();
    clearSidebarState();

    await refreshUser();

    toast.success('Exited organization');
  };

  const value: OperateAsContextType = {
    operateAsOrg,
    isOperatingAs: operateAsOrg !== null,
    enter,
    exit,
  };

  return <OperateAsContext.Provider value={value}>{children}</OperateAsContext.Provider>;
}

/**
 * Hook to access the operate-as context.
 */
export function useOperateAs(): OperateAsContextType {
  const context = useContext(OperateAsContext);
  if (context === undefined) {
    throw new Error('useOperateAs must be used within an OperateAsProvider');
  }
  return context;
}
