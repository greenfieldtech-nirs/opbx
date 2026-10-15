/**
 * Shared "resource is in use" dialog for rejected deletes.
 *
 * The API answers a delete of a resource that other config still points at with 409 and
 * `references: [{type, id, name, detail}]`. `useResourceInUse()` detects that shape in a mutation's
 * onError and opens a dialog listing where the resource is used; any other error is left to the caller.
 *
 *   const { showIfInUse, inUseDialog } = useResourceInUse();
 *   onError: (error) => { if (!showIfInUse(error)) toast.error(...); }
 *   ...render {inUseDialog} once in the page.
 */

import { useState, type ReactNode } from 'react';
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog';
import { Button } from '@/components/ui/button';

export interface ResourceReference {
  type: string;
  id: number;
  name: string;
  detail: string;
}

interface InUseState {
  message: string;
  references: ResourceReference[];
}

/** Returns the in-use payload when the error is the 409 in-use response, otherwise null. */
export function parseResourceInUse(error: unknown): InUseState | null {
  const response = (error as { response?: { status?: number; data?: { message?: string; references?: unknown } } })?.response;
  if (response?.status !== 409 || !Array.isArray(response.data?.references)) {
    return null;
  }
  return {
    message: response.data?.message || 'This resource is still in use.',
    references: response.data.references as ResourceReference[],
  };
}

const typeLabel = (type: string): string =>
  type.replace(/ivr_menu/g, 'IVR menu').replace(/ai_/g, 'AI ').replace(/_/g, ' ');

export function useResourceInUse(): { showIfInUse: (error: unknown) => boolean; inUseDialog: ReactNode } {
  const [state, setState] = useState<InUseState | null>(null);

  const showIfInUse = (error: unknown): boolean => {
    const parsed = parseResourceInUse(error);
    if (parsed) {
      setState(parsed);
    }
    return parsed !== null;
  };

  const inUseDialog = (
    <Dialog open={state !== null} onOpenChange={(open) => !open && setState(null)}>
      <DialogContent className="max-w-lg">
        <DialogHeader>
          <DialogTitle>Cannot delete: still in use</DialogTitle>
          <DialogDescription>Remove or change these references first, then try again.</DialogDescription>
        </DialogHeader>
        <ul className="max-h-80 space-y-2 overflow-y-auto text-sm">
          {state?.references.map((ref) => (
            <li key={`${ref.type}-${ref.id}-${ref.detail}`} className="rounded-md border p-2">
              <span className="font-medium">{ref.name}</span>
              <span className="text-muted-foreground"> · {typeLabel(ref.type)} · {ref.detail}</span>
            </li>
          ))}
        </ul>
        <DialogFooter>
          <Button onClick={() => setState(null)}>Close</Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );

  return { showIfInUse, inUseDialog };
}
