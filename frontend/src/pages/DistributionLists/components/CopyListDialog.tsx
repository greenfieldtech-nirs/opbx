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
import { useCopyList } from '@/hooks/useDistributionLists';
import { toast } from 'sonner';
import type { AutoDialerList } from '@/types';

interface CopyListDialogProps {
  list: AutoDialerList;
  open: boolean;
  onOpenChange: (open: boolean) => void;
}

export function CopyListDialog({ list, open, onOpenChange }: CopyListDialogProps) {
  const [newName, setNewName] = useState(`${list.name} (Copy)`);

  const copyMutation = useCopyList();

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();

    if (!newName.trim()) {
      toast.error('Name is required');
      return;
    }

    try {
      await copyMutation.mutateAsync({ listId: list.id, newName });
      toast.success('List copied successfully');
      onOpenChange(false);
    } catch {
      toast.error('Failed to copy list');
    }
  };

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="sm:max-w-[425px]">
        <form onSubmit={handleSubmit}>
          <DialogHeader>
            <DialogTitle>Copy List</DialogTitle>
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
              />
            </div>
          </div>

          <DialogFooter>
            <Button type="button" variant="outline" onClick={() => onOpenChange(false)}>
              Cancel
            </Button>
            <Button type="submit" disabled={copyMutation.isPending}>
              {copyMutation.isPending ? 'Copying...' : 'Copy List'}
            </Button>
          </DialogFooter>
        </form>
      </DialogContent>
    </Dialog>
  );
}
