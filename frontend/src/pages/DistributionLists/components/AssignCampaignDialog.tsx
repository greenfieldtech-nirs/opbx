import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog';
import { getApiErrorMessage } from '@/services/api';
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
  campaigns: Array<{ id: string; name: string; status: string; start_date?: string; timezone?: string | null }>;
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
      const message = getApiErrorMessage(err);
      toast.error('Assignment failed', { description: message });
    }
  };

  const handleClose = () => {
    setSelectedCampaignId('');
    onOpenChange(false);
  };

  // Filter campaigns with the server's rule (AutoDialerCampaign::listAssignmentRefusal):
  // - Draft or Paused, OR
  // - Active with a start_date (YYYY-MM-DD) after today in the campaign's timezone
  const availableCampaigns = campaigns.filter((campaign) => {
    // Draft campaigns are always available
    if (campaign.status === 'draft') return true;

    // Paused campaigns are available (not currently running)
    if (campaign.status === 'paused') return true;

    // Active campaigns: only available if schedule hasn't started yet
    if (campaign.status === 'active') {
      if (!campaign.start_date) return false;
      // en-CA formats as YYYY-MM-DD, so the dates compare as strings.
      const today = new Intl.DateTimeFormat('en-CA', {
        timeZone: campaign.timezone || 'UTC',
        year: 'numeric',
        month: '2-digit',
        day: '2-digit',
      }).format(new Date());
      return campaign.start_date.slice(0, 10) > today;
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
