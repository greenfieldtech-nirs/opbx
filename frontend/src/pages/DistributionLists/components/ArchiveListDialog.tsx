import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog';
import { Button } from '@/components/ui/button';
import type { AutoDialerList } from '@/types';

interface ArchiveListDialogProps {
  list: AutoDialerList;
  open: boolean;
  onOpenChange: (open: boolean) => void;
  onConfirm: () => void;
  isArchiving: boolean;
}

export function ArchiveListDialog({
  list,
  open,
  onOpenChange,
  onConfirm,
  isArchiving,
}: ArchiveListDialogProps) {
  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="sm:max-w-[425px]">
        <DialogHeader>
          <DialogTitle>Archive List</DialogTitle>
          <DialogDescription>
            Are you sure you want to archive "{list.name}"? This action cannot be undone.
            Archived lists cannot be used in campaigns.
          </DialogDescription>
        </DialogHeader>

        <DialogFooter>
          <Button type="button" variant="outline" onClick={() => onOpenChange(false)}>
            Cancel
          </Button>
          <Button type="button" variant="destructive" onClick={onConfirm} disabled={isArchiving}>
            {isArchiving ? 'Archiving...' : 'Archive List'}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}
