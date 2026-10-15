/**
 * Shared "delete user" warning dialog (organization Users page and Platform Users page).
 *
 * Loads the server-side preview of what the delete changes (extensions kept, memberships dropped,
 * routes switched to "Destination unavailable") and only lets the user confirm once it is shown.
 */

import { useQuery } from '@tanstack/react-query';
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog';
import { Button } from '@/components/ui/button';
import type { UserDeleteImpact } from '@/services/createResourceService';

interface UserDeleteDialogProps {
  open: boolean;
  onOpenChange: (open: boolean) => void;
  userId: string | number | undefined;
  userName: string | undefined;
  /** Fetches the preview for the user; must hit the endpoint that matches the delete being confirmed. */
  loadImpact: (userId: string | number) => Promise<UserDeleteImpact>;
  /** Distinguishes the org and platform previews in the query cache. */
  scope: 'org' | 'platform';
  onConfirm: () => void;
  isPending: boolean;
}

export function UserDeleteDialog({
  open,
  onOpenChange,
  userId,
  userName,
  loadImpact,
  scope,
  onConfirm,
  isPending,
}: UserDeleteDialogProps) {
  const impactQuery = useQuery({
    queryKey: [scope === 'platform' ? 'platform' : 'users', 'delete-impact', userId],
    queryFn: () => loadImpact(userId!),
    enabled: open && userId !== undefined,
    staleTime: 0,
    gcTime: 0,
  });
  const impact = impactQuery.data;

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent>
        <DialogHeader>
          <DialogTitle>Delete User</DialogTitle>
          <DialogDescription>
            Are you sure you want to delete <strong>{userName}</strong>? This action cannot be undone.
          </DialogDescription>
        </DialogHeader>
        {impactQuery.isLoading && <p className="text-sm text-muted-foreground">Checking what this will change...</p>}
        {impactQuery.isError && (
          <p className="text-sm text-destructive">Could not load the list of changes. Close this dialog and try again.</p>
        )}
        {impact && (
          <div className="max-h-80 space-y-3 overflow-y-auto text-sm">
            {impact.extensions.length > 0 && (
              <p>
                Extension {impact.extensions.map((e) => e.extension_number).join(', ')} will be kept and left
                unassigned, so it can be given to a new user.
              </p>
            )}
            {impact.memberships.length > 0 && (
              <div>
                <p className="font-medium">Removed as a member of:</p>
                <ul className="mt-1 space-y-1">
                  {impact.memberships.map((m) => (
                    <li key={`${m.type}-${m.id}`} className="rounded-md border p-2">
                      {m.name} <span className="text-muted-foreground">· {m.type === 'ring_group' ? 'ring group' : 'call queue'}</span>
                      {m.becomes_empty && <span className="ml-2 font-medium text-destructive">Will become EMPTY (no members left)</span>}
                    </li>
                  ))}
                </ul>
              </div>
            )}
            {impact.routes.length > 0 && (
              <div>
                <p className="font-medium">These routes will be switched to "Destination unavailable" (callers hear "Destination doesn't exist" and the call ends):</p>
                <ul className="mt-1 space-y-1">
                  {impact.routes.map((r) => (
                    <li key={`${r.type}-${r.id}-${r.detail}`} className="rounded-md border p-2">
                      <span className="font-medium">{r.name}</span>
                      <span className="text-muted-foreground"> · {r.type.replace(/ivr_menu/g, 'IVR menu').replace(/ai_/g, 'AI ').replace(/_/g, ' ')} · {r.detail}</span>
                    </li>
                  ))}
                </ul>
              </div>
            )}
            {!impact.has_changes && impact.extensions.length === 0 && (
              <p className="text-muted-foreground">No routing is affected.</p>
            )}
          </div>
        )}
        <DialogFooter>
          <Button variant="outline" onClick={() => onOpenChange(false)} disabled={isPending}>
            Cancel
          </Button>
          <Button variant="destructive" onClick={onConfirm} disabled={isPending || !impact}>
            {isPending ? 'Deleting...' : 'Delete User'}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}
