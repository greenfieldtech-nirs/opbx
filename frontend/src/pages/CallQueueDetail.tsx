/**
 * Call Queue Detail Page
 * Live snapshot, agents, and history statistics for a single queue.
 */

import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useParams, useNavigate } from 'react-router-dom';
import { ArrowLeft, Clock, PhoneCall, Timer, Users } from 'lucide-react';

import { callQueuesService, queueAgentService, queueStatsService, type QueueAgentState } from '@/services/callQueues.service';
import { useAuth } from '@/hooks/useAuth';
import { toast } from 'sonner';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { EmptyState } from '@/components/design-system';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from '@/components/ui/table';

const AGENT_STATE_LABELS: Record<QueueAgentState, string> = {
  AVAILABLE: 'Available',
  BUSY: 'On Call',
  WRAP_UP: 'Wrap-up',
  LOGGED_OUT: 'Logged Out',
};

function formatSeconds(seconds: number | null | undefined): string {
  if (seconds === null || seconds === undefined) return '—';
  if (seconds < 60) return `${seconds}s`;
  return `${Math.floor(seconds / 60)}m ${seconds % 60}s`;
}

export default function CallQueueDetail() {
  const { id } = useParams<{ id: string }>();
  const navigate = useNavigate();
  const { user } = useAuth();
  const canManage = user?.role === 'owner' || user?.role === 'pbx_admin';
  const queryClient = useQueryClient();

  const agentStateMutation = useMutation({
    mutationFn: ({
      queueId,
      state,
      userId,
    }: {
      queueId: number;
      state: 'available' | 'logged_out';
      userId: number;
    }) => queueAgentService.setState(queueId, state, userId),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['call-queues', id, 'live'] });
    },
    onError: () => toast.error('Failed to update agent state'),
  });

  const { data: queueData } = useQuery({
    queryKey: ['call-queues', id],
    queryFn: () => callQueuesService.getById(id!),
    enabled: Boolean(id),
  });

  const { data: liveData } = useQuery({
    queryKey: ['call-queues', id, 'live'],
    queryFn: () => queueStatsService.live(id!),
    enabled: Boolean(id),
    refetchInterval: 5000,
  });

  const { data: statsData } = useQuery({
    queryKey: ['call-queues', id, 'stats'],
    queryFn: () => queueStatsService.stats(id!),
    enabled: Boolean(id),
    refetchInterval: 30000,
  });

  const queue = queueData?.data;
  const live = liveData?.data;
  const stats = statsData?.data;

  if (!queue) {
    return (
      <div className="space-y-4">
        <Button variant="ghost" onClick={() => navigate('/ui/call-queues')}>
          <ArrowLeft className="mr-2 h-4 w-4" /> Back to Call Queues
        </Button>
        <EmptyState icon={Users} title="Queue not found" description="This call queue does not exist." />
      </div>
    );
  }

  return (
    <div className="space-y-6">
      <div className="flex items-center justify-between">
        <div className="flex items-center gap-3">
          <Button variant="ghost" size="icon" onClick={() => navigate('/ui/call-queues')}>
            <ArrowLeft className="h-4 w-4" />
          </Button>
          <div>
            <h1 className="text-2xl font-semibold flex items-center gap-2">
              {queue.name}
              <Badge variant={queue.status === 'active' ? 'default' : 'secondary'}>
                {queue.status === 'active' ? 'Active' : 'Inactive'}
              </Badge>
            </h1>
            <p className="text-sm text-muted-foreground">
              {queue.strategy.replace(/_/g, ' ')} · max wait {queue.max_wait_seconds}s · wrap-up{' '}
              {queue.wrap_up_seconds}s
            </p>
          </div>
        </div>
      </div>

      <Tabs defaultValue="live">
        <TabsList>
          <TabsTrigger value="live">Live</TabsTrigger>
          <TabsTrigger value="agents">Agents ({queue.agents?.length ?? queue.agents_count ?? 0})</TabsTrigger>
          <TabsTrigger value="statistics">Statistics</TabsTrigger>
        </TabsList>

        <TabsContent value="live" className="space-y-4">
          <div className="grid grid-cols-2 md:grid-cols-4 gap-4">
            <Card>
              <CardHeader className="flex flex-row items-center justify-between pb-2">
                <CardTitle className="text-sm font-medium">Waiting Callers</CardTitle>
                <PhoneCall className="h-4 w-4 text-muted-foreground" />
              </CardHeader>
              <CardContent className="text-2xl font-bold">{live?.waiting.length ?? 0}</CardContent>
            </Card>
            <Card>
              <CardHeader className="flex flex-row items-center justify-between pb-2">
                <CardTitle className="text-sm font-medium">Available Agents</CardTitle>
                <Users className="h-4 w-4 text-muted-foreground" />
              </CardHeader>
              <CardContent className="text-2xl font-bold">
                {live?.agents.filter((a) => a.state === 'AVAILABLE').length ?? 0}
              </CardContent>
            </Card>
            <Card>
              <CardHeader className="flex flex-row items-center justify-between pb-2">
                <CardTitle className="text-sm font-medium">Handled (60m)</CardTitle>
                <Timer className="h-4 w-4 text-muted-foreground" />
              </CardHeader>
              <CardContent className="text-2xl font-bold">{live?.rolling['handled_60m'] ?? 0}</CardContent>
            </Card>
            <Card>
              <CardHeader className="flex flex-row items-center justify-between pb-2">
                <CardTitle className="text-sm font-medium">Abandoned (60m)</CardTitle>
                <Clock className="h-4 w-4 text-muted-foreground" />
              </CardHeader>
              <CardContent className="text-2xl font-bold">{live?.rolling['abandoned_60m'] ?? 0}</CardContent>
            </Card>
          </div>

          <Card>
            <CardHeader>
              <CardTitle>Waiting Callers</CardTitle>
            </CardHeader>
            <CardContent>
              {(live?.waiting.length ?? 0) === 0 ? (
                <EmptyState
                  icon={PhoneCall}
                  title="No one is waiting"
                  description="Callers entering the queue will appear here with their position and wait time."
                />
              ) : (
                <Table>
                  <TableHeader>
                    <TableRow>
                      <TableHead>Queue</TableHead>
                      <TableHead>Position</TableHead>
                      <TableHead>Waiting</TableHead>
                      <TableHead>Avg Wait (24h)</TableHead>
                      <TableHead>Caller ID</TableHead>
                      <TableHead>Destination</TableHead>
                      <TableHead>Call Start</TableHead>
                    </TableRow>
                  </TableHeader>
                  <TableBody>
                    {live!.waiting.map((w) => (
                      <TableRow key={w.callId}>
                        <TableCell>{live?.call_queue_name ?? queue.name}</TableCell>
                        <TableCell>{w.position}</TableCell>
                        <TableCell>{formatSeconds(w.waitedSeconds)}</TableCell>
                        <TableCell>{formatSeconds(live?.waiting_time_avg_seconds ?? null)}</TableCell>
                        <TableCell>{w.from_number ?? '—'}</TableCell>
                        <TableCell>{w.to_number ?? '—'}</TableCell>
                        <TableCell>{w.entered_at ? new Date(w.entered_at).toLocaleString() : '—'}</TableCell>
                      </TableRow>
                    ))}
                  </TableBody>
                </Table>
              )}
            </CardContent>
          </Card>
        </TabsContent>

        <TabsContent value="agents">
          <Card>
            <CardHeader>
              <CardTitle>Queue Agents</CardTitle>
            </CardHeader>
            <CardContent>
              {(queue.agents ?? []).length === 0 ? (
                <EmptyState
                  icon={Users}
                  title="No agents"
                  description="Add agents from the queue edit dialog."
                />
              ) : (
                <Table>
                  <TableHeader>
                    <TableRow>
                      <TableHead>Agent</TableHead>
                      <TableHead>Extension</TableHead>
                      <TableHead>Current State</TableHead>
                    </TableRow>
                  </TableHeader>
                  <TableBody>
                    {(queue.agents ?? []).map((agent) => {
                      const liveAgent = live?.agents.find((a) => a.userId === String(agent.id));
                      const state = liveAgent?.state ?? 'LOGGED_OUT';
                      return (
                        <TableRow key={agent.id}>
                          <TableCell>{agent.name}</TableCell>
                          <TableCell>{agent.extension_number ?? '—'}</TableCell>
                          <TableCell>
                            {canManage && state !== 'BUSY' ? (
                              <button
                                type="button"
                                title="Click to toggle login status"
                                onClick={() =>
                                  agentStateMutation.mutate({
                                    queueId: queue.id,
                                    state: state === 'LOGGED_OUT' ? 'available' : 'logged_out',
                                    userId: agent.id,
                                  })
                                }
                              >
                                <Badge
                                  variant={
                                    state === 'AVAILABLE'
                                      ? 'default'
                                      : state === 'LOGGED_OUT'
                                        ? 'secondary'
                                        : 'outline'
                                  }
                                  className="cursor-pointer"
                                >
                                  {AGENT_STATE_LABELS[state]}
                                </Badge>
                              </button>
                            ) : (
                              <Badge
                                variant={
                                  state === 'AVAILABLE'
                                    ? 'default'
                                    : state === 'LOGGED_OUT'
                                      ? 'secondary'
                                      : 'outline'
                                }
                              >
                                {AGENT_STATE_LABELS[state]}
                              </Badge>
                            )}
                          </TableCell>
                        </TableRow>
                      );
                    })}
                  </TableBody>
                </Table>
              )}
            </CardContent>
          </Card>
        </TabsContent>

        <TabsContent value="statistics" className="space-y-4">
          <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
            {(
              [
                { title: 'Waiting Time (answered calls)', stats: stats?.waiting_time },
                { title: 'Handling Time (answered calls)', stats: stats?.handling_time },
              ] as const
            ).map((section) => (
              <Card key={section.title}>
                <CardHeader>
                  <CardTitle className="text-base">{section.title}</CardTitle>
                </CardHeader>
                <CardContent>
                  <Table>
                    <TableBody>
                      <TableRow>
                        <TableCell className="text-muted-foreground">Min</TableCell>
                        <TableCell>{formatSeconds(section.stats?.min)}</TableCell>
                      </TableRow>
                      <TableRow>
                        <TableCell className="text-muted-foreground">Max</TableCell>
                        <TableCell>{formatSeconds(section.stats?.max)}</TableCell>
                      </TableRow>
                      <TableRow>
                        <TableCell className="text-muted-foreground">Average</TableCell>
                        <TableCell>{formatSeconds(section.stats?.avg ? Math.round(section.stats.avg) : null)}</TableCell>
                      </TableRow>
                      <TableRow>
                        <TableCell className="text-muted-foreground">Std Dev</TableCell>
                        <TableCell>
                          {formatSeconds(section.stats?.stddev ? Math.round(section.stats.stddev) : null)}
                        </TableCell>
                      </TableRow>
                    </TableBody>
                  </Table>
                </CardContent>
              </Card>
            ))}
          </div>

          <Card>
            <CardHeader>
              <CardTitle className="text-base">Totals (last 24 hours)</CardTitle>
            </CardHeader>
            <CardContent>
              <div className="grid grid-cols-3 gap-4 text-center">
                <div>
                  <div className="text-2xl font-bold">{stats?.totals.handled ?? 0}</div>
                  <div className="text-sm text-muted-foreground">Handled</div>
                </div>
                <div>
                  <div className="text-2xl font-bold">{stats?.totals.abandoned ?? 0}</div>
                  <div className="text-sm text-muted-foreground">Abandoned</div>
                </div>
                <div>
                  <div className="text-2xl font-bold">{stats?.totals.overflowed ?? 0}</div>
                  <div className="text-sm text-muted-foreground">Overflowed</div>
                </div>
              </div>
              <p className="text-xs text-muted-foreground mt-4">
                Full history reports with per-call rows and CSV export are on the{' '}
                <button className="underline" onClick={() => navigate('/ui/queue-reports')}>
                  Queue Reports
                </button>{' '}
                page.
              </p>
            </CardContent>
          </Card>
        </TabsContent>
      </Tabs>
    </div>
  );
}
