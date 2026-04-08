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
  campaigns: Array<{ id: string; name: string; status: string }>;
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
    } catch {
      toast.error('Failed to assign list to campaign');
    }
  };

  const handleClose = () => {
    setSelectedCampaignId('');
    onOpenChange(false);
  };

  // Filter campaigns that can accept a list (draft or active status)
  const availableCampaigns = campaigns.filter(
    (campaign) => campaign.status === 'draft' || campaign.status === 'active'
  );

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
              No available campaigns found. Create a campaign first or use a campaign in Draft or Active status.
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
