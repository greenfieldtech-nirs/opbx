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
import { useCreateList } from '@/hooks/useDistributionLists';
import { toast } from 'sonner';

interface CreateListDialogProps {
  open: boolean;
  onOpenChange: (open: boolean) => void;
}

export function CreateListDialog({ open, onOpenChange }: CreateListDialogProps) {
  const [name, setName] = useState('');
  const [description, setDescription] = useState('');

  const createMutation = useCreateList();

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();

    if (!name.trim()) {
      toast.error('Name is required');
      return;
    }

    try {
      await createMutation.mutateAsync({ name, description });
      toast.success('List created successfully');
      setName('');
      setDescription('');
      onOpenChange(false);
    } catch {
      toast.error('Failed to create list');
    }
  };

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="sm:max-w-[425px]">
        <form onSubmit={handleSubmit}>
          <DialogHeader>
            <DialogTitle>Create Distribution List</DialogTitle>
            <DialogDescription>
              Create a new list to store phone numbers for auto-dialer campaigns.
            </DialogDescription>
          </DialogHeader>

          <div className="grid gap-4 py-4">
            <div className="grid gap-2">
              <Label htmlFor="name">Name *</Label>
              <Input
                id="name"
                value={name}
                onChange={(e) => setName(e.target.value)}
                placeholder="e.g., Sales Leads Q1"
              />
            </div>

            <div className="grid gap-2">
              <Label htmlFor="description">Description</Label>
              <Input
                id="description"
                value={description}
                onChange={(e) => setDescription(e.target.value)}
                placeholder="Optional description"
              />
            </div>
          </div>

          <DialogFooter>
            <Button type="button" variant="outline" onClick={() => onOpenChange(false)}>
              Cancel
            </Button>
            <Button type="submit" disabled={createMutation.isPending}>
              {createMutation.isPending ? 'Creating...' : 'Create List'}
            </Button>
          </DialogFooter>
        </form>
      </DialogContent>
    </Dialog>
  );
}
