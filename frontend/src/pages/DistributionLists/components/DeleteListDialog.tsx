import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog';
import { Button } from '@/components/ui/button';
import { AlertTriangle } from 'lucide-react';
import type { AutoDialerList } from '@/types';

interface DeleteListDialogProps {
  list: AutoDialerList;
  open: boolean;
  onOpenChange: (open: boolean) => void;
  onConfirm: () => void;
  isDeleting: boolean;
}

export function DeleteListDialog({
  list,
  open,
  onOpenChange,
  onConfirm,
  isDeleting,
}: DeleteListDialogProps) {
  const isFailed = list.status === 'failed';

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="sm:max-w-[425px]">
        <DialogHeader>
          <DialogTitle className="flex items-center gap-2">
            <AlertTriangle className="h-5 w-5 text-red-500" />
            Delete List
          </DialogTitle>
          <DialogDescription>
            Are you sure you want to permanently delete &quot;{list.name}&quot;?
            {isFailed ? (
              <>
                <br />
                <br />
                This list is in <strong>Failed</strong> status. Deleting it will remove all
                associated data permanently. This action cannot be undone.
              </>
            ) : (
              <>
                <br />
                <br />
                <strong>Warning:</strong> This list is not in Failed status. Only Owners can delete
                lists that are not failed. This action cannot be undone.
              </>
            )}
          </DialogDescription>
        </DialogHeader>

        <DialogFooter>
          <Button type="button" variant="outline" onClick={() => onOpenChange(false)}>
            Cancel
          </Button>
          <Button type="button" variant="destructive" onClick={onConfirm} disabled={isDeleting}>
            {isDeleting ? 'Deleting...' : 'Permanently Delete'}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}
