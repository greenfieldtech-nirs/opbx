import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog';
import { Button } from '@/components/ui/button';
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select';
import { useState } from 'react';
import { useAssignListToCampaign } from '@/hooks/useDistributionLists';
import { toast } from 'sonner';
import type { AutoDialerList } from '@/types';

interface AssignCampaignDialogProps {
  list: AutoDialerList;
  open: boolean;
  onOpenChange: (open: boolean) => void;
  campaigns: Array<{ id: string; name: string; status: string; start_date?: string }>;
}

export function AssignCampaignDialog({
  list,
  open,
  onOpenChange,
  campaigns,
}: AssignCampaignDialogProps) {
  const [selectedCampaignId, setSelectedCampaignId] = useState<string>('');
  const assignMutation = useAssignListToCampaign();

  const handleAssign = async () => {
    if (!selectedCampaignId) return;

    try {
      await assignMutation.mutateAsync({
        listId: list.id,
        campaignId: parseInt(selectedCampaignId, 10),
      });
      toast.success('List assigned to campaign successfully');
      setSelectedCampaignId('');
      onOpenChange(false);
    } catch (err: any) {
      const message = err?.response?.data?.error || 'Failed to assign list to campaign';
      toast.error('Assignment failed', { description: message });
    }
  };

  const handleClose = () => {
    setSelectedCampaignId('');
    onOpenChange(false);
  };

  // Filter campaigns based on assignment rules:
  // - Campaign is not currently running (draft or paused), OR
  // - Campaign schedule hasn't been reached yet (start_date is in the future)
  const availableCampaigns = campaigns.filter((campaign) => {
    // Draft campaigns are always available
    if (campaign.status === 'draft') return true;

    // Paused campaigns are available (not currently running)
    if (campaign.status === 'paused') return true;

    // Active campaigns: only available if schedule hasn't started yet
    if (campaign.status === 'active') {
      const startDate = campaign.start_date ? new Date(campaign.start_date) : null;
      const now = new Date();
      // Allow if start_date is in the future
      if (startDate && startDate > now) return true;
      // If no start_date or start_date has passed, campaign is running - don't allow
      return false;
    }

    // Completed or archived campaigns cannot accept lists
    return false;
  });

  return (
    <Dialog open={open} onOpenChange={handleClose}>
      <DialogContent className="sm:max-w-[425px]">
        <DialogHeader>
          <DialogTitle>Assign to Campaign</DialogTitle>
          <DialogDescription>
            Assign "{list.name}" to a campaign to start using it.
          </DialogDescription>
        </DialogHeader>

        <div className="py-4">
          {availableCampaigns.length === 0 ? (
            <p className="text-sm text-muted-foreground">
              No available campaigns found. Campaigns must be in Draft, Paused, or scheduled (not yet started) status to accept a list.
            </p>
          ) : (
            <Select value={selectedCampaignId} onValueChange={setSelectedCampaignId}>
              <SelectTrigger className="w-full">
                <SelectValue placeholder="Select a campaign" />
              </SelectTrigger>
              <SelectContent>
                {availableCampaigns.map((campaign) => (
                  <SelectItem key={campaign.id} value={campaign.id.toString()}>
                    {campaign.name}
                  </SelectItem>
                ))}
              </SelectContent>
            </Select>
          )}
        </div>

        <DialogFooter>
          <Button type="button" variant="outline" onClick={handleClose}>
            Cancel
          </Button>
          <Button
            type="button"
            onClick={handleAssign}
            disabled={!selectedCampaignId || assignMutation.isPending}
          >
            {assignMutation.isPending ? 'Assigning...' : 'Assign'}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}
