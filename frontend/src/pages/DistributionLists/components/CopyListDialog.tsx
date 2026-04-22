import { useState } from 'react';
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Progress } from '@/components/ui/progress';
import { useCopyList } from '@/hooks/useDistributionLists';
import { toast } from 'sonner';
import { Copy, Loader2, CheckCircle2 } from 'lucide-react';
import { cn } from '@/lib/utils';
import type { AutoDialerList } from '@/types';

interface CopyListDialogProps {
  list: AutoDialerList;
  open: boolean;
  onOpenChange: (open: boolean) => void;
}

export function CopyListDialog({ list, open, onOpenChange }: CopyListDialogProps) {
  const [newName, setNewName] = useState(`${list.name} (Copy)`);
  const [copyStage, setCopyStage] = useState<'idle' | 'copying' | 'success'>('idle');

  const copyMutation = useCopyList();

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();

    if (!newName.trim()) {
      toast.error('Name is required');
      return;
    }

    setCopyStage('copying');

    try {
      const result = await copyMutation.mutateAsync({ listId: list.id, newName });
      setCopyStage('success');
      toast.success('List copied successfully', {
        description: `"${list.name}" has been copied to "${result.data.name}" with ${result.data.statistics?.valid_rows?.toLocaleString() || 'all'} destinations.`,
      });

      // Auto-close after a brief delay so the user sees the success state
      setTimeout(() => {
        setCopyStage('idle');
        onOpenChange(false);
      }, 1500);
    } catch (err: any) {
      setCopyStage('idle');
      const message = err?.response?.data?.error || err?.message || 'Failed to copy list';
      toast.error('Copy failed', { description: message });
    }
  };

  const handleCancel = () => {
    if (copyStage === 'copying') return; // Prevent closing during copy
    setCopyStage('idle');
    onOpenChange(false);
  };

  // Prevent closing by clicking outside or pressing Escape while copying
  const handleOpenChange = (value: boolean) => {
    if (copyStage === 'copying') return;
    onOpenChange(value);
  };

  return (
    <Dialog open={open} onOpenChange={handleOpenChange}>
      <DialogContent
        className="sm:max-w-[425px]"
        onPointerDownOutside={(e) => {
          if (copyStage === 'copying') e.preventDefault();
        }}
        onEscapeKeyDown={(e) => {
          if (copyStage === 'copying') e.preventDefault();
        }}
      >
        {/* Full-screen overlay during copy */}
        {copyStage === 'copying' && (
          <div className="absolute inset-0 z-50 flex flex-col items-center justify-center bg-background/95 backdrop-blur-sm rounded-lg">
            <div className="flex flex-col items-center gap-4 p-6">
              <div className="relative">
                <div className="h-16 w-16 rounded-full border-4 border-muted flex items-center justify-center">
                  <Loader2 className="h-8 w-8 animate-spin text-primary" />
                </div>
              </div>
              <div className="text-center space-y-2">
                <h3 className="text-lg font-semibold">Copying List</h3>
                <p className="text-sm text-muted-foreground max-w-[280px]">
                  Please wait while we copy "{list.name}" to "{newName}".
                  All destinations are being duplicated with reset statuses.
                </p>
              </div>
              <div className="w-full max-w-[300px] space-y-2">
                <Progress value={undefined} className="h-2" />
                <p className="text-xs text-center text-muted-foreground">
                  Do not close this window or navigate away
                </p>
              </div>
            </div>
          </div>
        )}

        {/* Success overlay */}
        {copyStage === 'success' && (
          <div className="absolute inset-0 z-50 flex flex-col items-center justify-center bg-background/95 backdrop-blur-sm rounded-lg">
            <div className="flex flex-col items-center gap-4 p-6">
              <div className="h-16 w-16 rounded-full bg-green-100 flex items-center justify-center">
                <CheckCircle2 className="h-8 w-8 text-green-600" />
              </div>
              <div className="text-center space-y-2">
                <h3 className="text-lg font-semibold text-green-700">Copy Complete</h3>
                <p className="text-sm text-muted-foreground">
                  "{newName}" has been created successfully.
                </p>
              </div>
            </div>
          </div>
        )}

        <form onSubmit={handleSubmit}>
          <DialogHeader>
            <DialogTitle className="flex items-center gap-2">
              <Copy className="h-5 w-5" />
              Copy List
            </DialogTitle>
            <DialogDescription>
              Create a copy of "{list.name}". All destinations will be copied with reset statuses.
            </DialogDescription>
          </DialogHeader>

          <div className="grid gap-4 py-4">
            <div className="grid gap-2">
              <Label htmlFor="newName">New List Name *</Label>
              <Input
                id="newName"
                value={newName}
                onChange={(e) => setNewName(e.target.value)}
                placeholder="Enter new list name"
                disabled={copyStage !== 'idle'}
                autoFocus
              />
            </div>

            {/* Source list info */}
            <div className="rounded-md bg-muted p-3 space-y-1">
              <p className="text-xs font-medium text-muted-foreground uppercase">Source List</p>
              <p className="text-sm font-medium">{list.name}</p>
              <p className="text-xs text-muted-foreground">
                {list.statistics.valid_rows.toLocaleString()} destinations
                {list.statistics.invalid_rows > 0 && (
                  <span className="text-red-500"> · {list.statistics.invalid_rows} invalid</span>
                )}
              </p>
            </div>
          </div>

          <DialogFooter>
            <Button
              type="button"
              variant="outline"
              onClick={handleCancel}
              disabled={copyStage !== 'idle'}
            >
              Cancel
            </Button>
            <Button
              type="submit"
              disabled={copyStage !== 'idle' || !newName.trim()}
            >
              {copyStage === 'idle' && (
                <>
                  <Copy className="h-4 w-4 mr-2" />
                  Copy List
                </>
              )}
              {copyStage === 'copying' && (
                <>
                  <Loader2 className="h-4 w-4 mr-2 animate-spin" />
                  Copying...
                </>
              )}
              {copyStage === 'success' && (
                <>
                  <CheckCircle2 className="h-4 w-4 mr-2" />
                  Copied!
                </>
              )}
            </Button>
          </DialogFooter>
        </form>
      </DialogContent>
    </Dialog>
  );
}
