import { useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { toast } from 'sonner';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { phoneNumbersService } from '@/services/createResourceService';
import { queryKeys } from '@/lib/queryKeys';
import { getApiErrorMessage } from '@/services/api';
import { useCreateCallTrackingNumber } from '@/hooks/useCallTrackingNumbers';

interface Props {
  campaignId: string | number;
  open: boolean;
  onOpenChange: (open: boolean) => void;
}

/** Assigns one of the organization's active phone numbers to a call-tracking campaign. */
export function AddCallTrackingNumberDialog({ campaignId, open, onOpenChange }: Props) {
  const [didId, setDidId] = useState('');
  const [friendlyName, setFriendlyName] = useState('');
  const createMutation = useCreateCallTrackingNumber();

  const { data } = useQuery({
    queryKey: queryKeys.phoneNumbers.list({ status: 'active', per_page: 100 }),
    queryFn: () => phoneNumbersService.getAll({ status: 'active', per_page: 100 }),
    enabled: open,
  });
  const numbers = data?.data ?? [];

  const close = (next: boolean) => {
    if (!next) {
      setDidId('');
      setFriendlyName('');
    }
    onOpenChange(next);
  };

  const submit = async () => {
    try {
      await createMutation.mutateAsync({
        campaignId,
        data: { did_number_id: Number(didId), friendly_name: friendlyName.trim() || null },
      });
      toast.success('Tracking number added');
      close(false);
    } catch (err) {
      toast.error(getApiErrorMessage(err) || 'Failed to add tracking number');
    }
  };

  return (
    <Dialog open={open} onOpenChange={close}>
      <DialogContent>
        <DialogHeader>
          <DialogTitle>Add Tracking Number</DialogTitle>
          <DialogDescription>Pick one of your active phone numbers to track for this campaign.</DialogDescription>
        </DialogHeader>
        <div className="space-y-4">
          <div className="space-y-2">
            <Label htmlFor="tracking-did">Phone number</Label>
            <Select value={didId} onValueChange={setDidId}>
              <SelectTrigger id="tracking-did">
                <SelectValue placeholder="Select a phone number" />
              </SelectTrigger>
              <SelectContent>
                {numbers.map((n: { id: string | number; phone_number: string; friendly_name?: string }) => (
                  <SelectItem key={n.id} value={String(n.id)}>
                    {n.phone_number}{n.friendly_name ? ` (${n.friendly_name})` : ''}
                  </SelectItem>
                ))}
              </SelectContent>
            </Select>
          </div>
          <div className="space-y-2">
            <Label htmlFor="tracking-name">Friendly name (optional)</Label>
            <Input id="tracking-name" value={friendlyName} onChange={(e) => setFriendlyName(e.target.value)} />
          </div>
        </div>
        <DialogFooter>
          <Button variant="outline" onClick={() => close(false)}>Cancel</Button>
          <Button onClick={submit} disabled={!didId || createMutation.isPending}>
            {createMutation.isPending ? 'Adding...' : 'Add Number'}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}
