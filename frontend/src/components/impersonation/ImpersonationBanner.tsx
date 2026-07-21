/**
 * Impersonation Banner
 *
 * Persistent banner shown while a platform owner is managing an organization
 * ("open as admin"). Provides an Exit action that revokes the impersonation
 * token and ends the per-tab session.
 */

import { useState } from 'react';
import { ShieldAlert, LogOut } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { storage } from '@/utils/storage';
import { useStopImpersonation } from '@/hooks/platform';

export function ImpersonationBanner() {
  const [exiting, setExiting] = useState(false);
  const stopImpersonation = useStopImpersonation();

  if (!storage.isImpersonating()) {
    return null;
  }

  const organization = storage.getImpersonationOrganization();

  const handleExit = async () => {
    setExiting(true);
    try {
      // Best-effort revoke of the impersonation token on the server.
      await stopImpersonation.mutateAsync().catch(() => undefined);
    } finally {
      // Always clear the per-tab session locally.
      storage.clearImpersonation();

      // Try to close the tab (it was opened via window.open). If the browser
      // refuses, fall back to a clear "session ended" state.
      window.close();
      // If still open, send the tab somewhere neutral.
      window.location.replace('/ui/login');
    }
  };

  return (
    <div className="w-full bg-amber-500 text-amber-950 px-4 py-2 flex items-center justify-between gap-4 shadow-sm">
      <div className="flex items-center gap-2 text-sm font-medium">
        <ShieldAlert className="h-4 w-4 flex-shrink-0" />
        <span>
          You are managing{' '}
          <strong>{organization?.name ?? 'an organization'}</strong> as a platform owner.
          Actions are audited.
        </span>
      </div>
      <Button
        variant="outline"
        size="sm"
        className="bg-white/80 hover:bg-white border-amber-700 text-amber-950"
        onClick={handleExit}
        disabled={exiting}
      >
        <LogOut className="h-4 w-4 mr-2" />
        {exiting ? 'Exiting...' : 'Exit organization'}
      </Button>
    </div>
  );
}

export default ImpersonationBanner;
