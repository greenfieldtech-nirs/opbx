/**
 * Queue Agent Widget
 *
 * Header dropdown for queue agents: shows the queues the current user is an
 * agent of and toggles their presence state (Available / Wrap-up / Log out).
 */

import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { toast } from 'sonner';
import { Headset } from 'lucide-react';

import {
  queueAgentService,
  type MyQueueMembership,
  type QueueAgentStateInput,
} from '@/services/callQueues.service';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuLabel,
  DropdownMenuSeparator,
  DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { getErrorMessage } from '@/types/api';

const STATE_ACTIONS: { state: QueueAgentStateInput; label: string }[] = [
  { state: 'available', label: 'Go Available' },
  { state: 'wrap_up', label: 'Wrap-up' },
  { state: 'logged_out', label: 'Log out' },
];

export function QueueAgentWidget() {
  const queryClient = useQueryClient();

  const { data } = useQuery({
    queryKey: ['my-queue-memberships'],
    queryFn: queueAgentService.myQueues,
    refetchInterval: 15000,
  });

  const memberships = data?.data ?? [];
  const hasMemberships = memberships.length > 0;

  const stateMutation = useMutation({
    mutationFn: ({ queueId, state }: { queueId: number; state: QueueAgentStateInput }) =>
      queueAgentService.setState(queueId, state),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['my-queue-memberships'] });
      queryClient.invalidateQueries({ queryKey: ['call-queues'] });
    },
    onError: (error) => toast.error(getErrorMessage(error)),
  });

  if (!hasMemberships) {
    return null;
  }

  const loggedInCount = memberships.filter((m) => m.state !== 'LOGGED_OUT').length;

  return (
    <DropdownMenu>
      <DropdownMenuTrigger asChild>
        <Button variant="ghost" size="sm" className="gap-2">
          <Headset className="h-4 w-4" />
          Queues
          <Badge variant={loggedInCount > 0 ? 'default' : 'secondary'}>{loggedInCount}</Badge>
        </Button>
      </DropdownMenuTrigger>
      <DropdownMenuContent align="end" className="w-64">
        <DropdownMenuLabel>My Queue Status</DropdownMenuLabel>
        <DropdownMenuSeparator />
        {memberships.map((membership: MyQueueMembership) => (
          <div key={membership.id} className="px-2 py-1.5">
            <div className="flex items-center justify-between">
              <span className="text-sm font-medium">{membership.name}</span>
              <Badge
                variant={
                  membership.state === 'AVAILABLE'
                    ? 'default'
                    : membership.state === 'LOGGED_OUT'
                      ? 'secondary'
                      : 'outline'
                }
              >
                {membership.state === 'LOGGED_OUT'
                  ? 'Logged out'
                  : membership.state === 'WRAP_UP'
                    ? 'Wrap-up'
                    : membership.state === 'BUSY'
                      ? 'On call'
                      : 'Available'}
              </Badge>
            </div>
            <div className="flex gap-1 mt-1">
              {STATE_ACTIONS.filter((a) => a.state !== currentInputState(membership.state)).map((action) => (
                <DropdownMenuItem
                  key={action.state}
                  className="text-xs"
                  onSelect={() =>
                    stateMutation.mutate({ queueId: membership.id, state: action.state })
                  }
                >
                  {action.label}
                </DropdownMenuItem>
              ))}
            </div>
          </div>
        ))}
      </DropdownMenuContent>
    </DropdownMenu>
  );
}

function currentInputState(state: string): QueueAgentStateInput | 'busy' {
  switch (state) {
    case 'AVAILABLE':
      return 'available';
    case 'WRAP_UP':
      return 'wrap_up';
    case 'LOGGED_OUT':
      return 'logged_out';
    default:
      return 'busy';
  }
}
