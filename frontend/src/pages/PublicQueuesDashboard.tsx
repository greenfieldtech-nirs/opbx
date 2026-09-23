/**
 * Public Queues Dashboard (wallboard)
 *
 * Unauthenticated deep link for call-center screens: /public/queues-dashboard/{token}
 * Mirrors the internal Queues Dashboard: timespan selector, queue selector,
 * 30-second cycling through all queues.
 */

import { useEffect, useMemo, useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { useParams } from 'react-router-dom';
import { Clock, PhoneCall, Timer, Users } from 'lucide-react';

import { publicQueuesService } from '@/services/publicQueues.service';
import { Badge } from '@/components/ui/badge';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { EmptyState } from '@/components/design-system';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';

const TIMESPANS = [
  { value: '15m', label: 'Last 15 Minutes', minutes: 15 },
  { value: '1h', label: 'Last Hour', minutes: 60 },
  { value: '6h', label: 'Last 6 Hours', minutes: 360 },
  { value: '12h', label: 'Last 12 Hours', minutes: 720 },
  { value: '24h', label: 'Last 24 Hours', minutes: 1440 },
] as const;

type Timespan = (typeof TIMESPANS)[number]['value'];

const ROTATE_SECONDS = 30;

const AGENT_CARD_STYLES: Record<string, string> = {
  AVAILABLE: 'bg-green-100 border-green-400',
  BUSY: 'bg-cyan-100 border-cyan-400',
  WRAP_UP: 'bg-gray-100 border-gray-400',
  LOGGED_OUT: 'bg-red-100 border-red-400',
};

function formatSeconds(seconds: number | null | undefined): string {
  if (seconds === null || seconds === undefined) return '—';
  if (seconds < 60) return `${seconds}s`;
  return `${Math.floor(seconds / 60)}m ${seconds % 60}s`;
}

export default function PublicQueuesDashboard() {
  const { token } = useParams<{ token: string }>();
  const [selectedQueueId, setSelectedQueueId] = useState<string>('all');
  const [timespan, setTimespan] = useState<Timespan>('1h');
  const [cycleIndex, setCycleIndex] = useState(0);
  const [cycleTick, setCycleTick] = useState(ROTATE_SECONDS);

  const { data: queuesData, isError } = useQuery({
    queryKey: ['public-queues', token, 'queues'],
    queryFn: () => publicQueuesService.queues(token!),
    enabled: Boolean(token),
    refetchInterval: 60000,
    retry: false,
  });

  const queues = queuesData?.data ?? [];
  const cycling = selectedQueueId === 'all';
  const activeQueue = cycling
    ? queues[cycleIndex % Math.max(queues.length, 1)]
    : queues.find((q) => String(q.id) === selectedQueueId);

  useEffect(() => {
    if (!cycling) {
      setCycleTick(ROTATE_SECONDS);
      return;
    }

    setCycleIndex((i) => (queues.length ? i % queues.length : 0));
    setCycleTick(ROTATE_SECONDS);

    const timer = setInterval(() => {
      setCycleTick((tick) => {
        if (tick > 1) {
          return tick - 1;
        }
        setCycleIndex((i) => (i + 1) % Math.max(queues.length, 1));
        return ROTATE_SECONDS;
      });
    }, 1000);

    return () => clearInterval(timer);
  }, [cycling, queues.length]);

  const minutes = TIMESPANS.find((t) => t.value === timespan)?.minutes ?? 60;
  const fromIso = useMemo(() => new Date(Date.now() - minutes * 60000).toISOString(), [minutes]);

  const queueId = activeQueue?.id;

  const { data: liveData } = useQuery({
    queryKey: ['public-queues', token, queueId, 'live'],
    queryFn: () => publicQueuesService.live(token!, queueId!),
    enabled: Boolean(token) && queueId !== undefined,
    refetchInterval: 5000,
    retry: false,
  });

  const { data: statsData } = useQuery({
    queryKey: ['public-queues', token, queueId, 'stats', timespan],
    queryFn: () => publicQueuesService.stats(token!, queueId!, fromIso),
    enabled: Boolean(token) && queueId !== undefined,
    refetchInterval: 30000,
    retry: false,
  });

  if (isError) {
    return (
      <div className="flex min-h-screen items-center justify-center bg-muted/30 p-6">
        <EmptyState
          icon={PhoneCall}
          title="Invalid dashboard link"
          description="This public queues dashboard link is invalid. Ask your administrator for the current link."
        />
      </div>
    );
  }

  if (!queues.length) {
    return (
      <div className="flex min-h-screen items-center justify-center bg-muted/30 p-6">
        <EmptyState
          icon={PhoneCall}
          title="No call queues"
          description="This organization has no call queues configured yet."
        />
      </div>
    );
  }

  const live = liveData?.data;
  const stats = statsData?.data;

  const totals = stats?.totals;
  const totalCalls = (totals?.handled ?? 0) + (totals?.abandoned ?? 0) + (totals?.overflowed ?? 0);
  const handledPct = totalCalls > 0 ? Math.round(((totals?.handled ?? 0) / totalCalls) * 100) : 0;
  const abandonedPct = totalCalls > 0 ? Math.round(((totals?.abandoned ?? 0) / totalCalls) * 100) : 0;

  return (
    <div className="min-h-screen bg-muted/30 p-6">
      <div className="queue-stage mx-auto max-w-6xl space-y-6">
        <div className="flex flex-wrap items-center justify-between gap-4">
          <div>
            <h1 className="text-4xl font-bold">Queues Dashboard</h1>
            <p className="text-lg text-muted-foreground">
              {cycling ? `Cycling all queues · next in ${cycleTick}s` : 'Showing selected queue'}
            </p>
          </div>
          <div className="flex items-end gap-4">
            <div className="space-y-2">
              <Label>Queue</Label>
              <Select value={selectedQueueId} onValueChange={setSelectedQueueId}>
                <SelectTrigger className="w-[240px] text-lg">
                  <SelectValue />
                </SelectTrigger>
                <SelectContent>
                  <SelectItem value="all">All Queues (cycle)</SelectItem>
                  {queues.map((q) => (
                    <SelectItem key={q.id} value={String(q.id)}>
                      {q.name}
                    </SelectItem>
                  ))}
                </SelectContent>
              </Select>
            </div>
            <div className="space-y-2">
              <Label>Timespan</Label>
              <Select value={timespan} onValueChange={(v) => setTimespan(v as Timespan)}>
                <SelectTrigger className="w-[200px] text-lg">
                  <SelectValue />
                </SelectTrigger>
                <SelectContent>
                  {TIMESPANS.map((t) => (
                    <SelectItem key={t.value} value={t.value}>
                      {t.label}
                    </SelectItem>
                  ))}
                </SelectContent>
              </Select>
            </div>
          </div>
        </div>

        {activeQueue && (
          <div key={activeQueue.id} className="queue-face space-y-4">
            <div className="flex items-center gap-3">
              <h2 className="text-4xl font-bold">{activeQueue.name}</h2>
              <Badge variant="outline" className="text-base px-3 py-1">{activeQueue.strategy.replace(/_/g, ' ')}</Badge>
              {cycling && (
                <span className="text-lg text-muted-foreground">
                  ({(cycleIndex % queues.length) + 1} of {queues.length})
                </span>
              )}
            </div>

            <div className="grid grid-cols-2 md:grid-cols-5 gap-4">
              <Card>
                <CardHeader className="flex flex-row items-center justify-between pb-2">
                  <CardTitle className="text-lg font-medium">Total Calls</CardTitle>
                  <PhoneCall className="h-6 w-6 text-muted-foreground" />
                </CardHeader>
                <CardContent className="text-6xl font-bold">{totalCalls}</CardContent>
              </Card>
              <Card>
                <CardHeader className="flex flex-row items-center justify-between pb-2">
                  <CardTitle className="text-lg font-medium">Waiting Callers</CardTitle>
                  <PhoneCall className="h-6 w-6 text-muted-foreground" />
                </CardHeader>
                <CardContent className="text-6xl font-bold">{live?.waiting.length ?? 0}</CardContent>
              </Card>
              <Card>
                <CardHeader className="flex flex-row items-center justify-between pb-2">
                  <CardTitle className="text-lg font-medium">Available Agents</CardTitle>
                  <Users className="h-6 w-6 text-muted-foreground" />
                </CardHeader>
                <CardContent className="text-6xl font-bold">
                  {live?.agents.filter((a) => a.state === 'AVAILABLE').length ?? 0}
                </CardContent>
              </Card>
              <Card>
                <CardHeader className="flex flex-row items-center justify-between pb-2">
                  <CardTitle className="text-lg font-medium">Handled</CardTitle>
                  <Timer className="h-6 w-6 text-muted-foreground" />
                </CardHeader>
                <CardContent>
                  <div className="text-6xl font-bold">{stats?.totals.handled ?? 0}</div>
                  <div className="text-lg text-muted-foreground">{handledPct}% of total</div>
                </CardContent>
              </Card>
              <Card>
                <CardHeader className="flex flex-row items-center justify-between pb-2">
                  <CardTitle className="text-lg font-medium">Abandoned</CardTitle>
                  <Clock className="h-6 w-6 text-muted-foreground" />
                </CardHeader>
                <CardContent>
                  <div className="text-6xl font-bold">{stats?.totals.abandoned ?? 0}</div>
                  <div className="text-lg text-muted-foreground">{abandonedPct}% of total</div>
                </CardContent>
              </Card>
            </div>

            <Card>
              <CardHeader>
                <CardTitle className="text-xl">
                  Waiting & Handling — {TIMESPANS.find((t) => t.value === timespan)?.label}
                </CardTitle>
              </CardHeader>
              <CardContent>
                <div className="grid grid-cols-2 md:grid-cols-4 gap-4">
                  <div className="text-center">
                    <div className="text-5xl font-bold">
                      {formatSeconds(stats?.waiting_time.avg ? Math.round(stats.waiting_time.avg) : null)}
                    </div>
                    <div className="text-lg text-muted-foreground">Avg Wait</div>
                  </div>
                  <div className="text-center">
                    <div className="text-5xl font-bold">{formatSeconds(stats?.waiting_time.max)}</div>
                    <div className="text-lg text-muted-foreground">Max Wait</div>
                  </div>
                  <div className="text-center">
                    <div className="text-5xl font-bold">
                      {formatSeconds(stats?.handling_time.avg ? Math.round(stats.handling_time.avg) : null)}
                    </div>
                    <div className="text-lg text-muted-foreground">Avg Handling</div>
                  </div>
                  <div className="text-center">
                    <div className="text-5xl font-bold">{stats?.totals.overflowed ?? 0}</div>
                    <div className="text-lg text-muted-foreground">Overflowed</div>
                  </div>
                </div>
              </CardContent>
            </Card>

            <Card>
              <CardHeader>
                <CardTitle className="text-xl">Agents</CardTitle>
              </CardHeader>
              <CardContent>
                {(live?.agents.length ?? 0) === 0 ? (
                  <p className="text-lg text-muted-foreground">No agents are members of this queue.</p>
                ) : (
                  <div className="grid grid-cols-2 md:grid-cols-4 lg:grid-cols-6 gap-3">
                    {live!.agents.map((agent) => (
                      <div
                        key={agent.userId}
                        className={`flex aspect-square flex-col items-center justify-center rounded-lg border-2 p-3 text-center ${AGENT_CARD_STYLES[agent.state] ?? AGENT_CARD_STYLES.LOGGED_OUT}`}
                      >
                        <div className="text-2xl font-bold text-gray-900">
                          {agent.extensionNumber ?? `User ${agent.userId}`}
                        </div>
                        <div className="text-base font-medium text-gray-800">
                          {agent.state === 'WRAP_UP' && agent.wrapUpRemainingSeconds
                            ? `Wrap-up · ${agent.wrapUpRemainingSeconds}s`
                            : agent.state.replace('_', ' ')}
                        </div>
                      </div>
                    ))}
                  </div>
                )}
              </CardContent>
            </Card>
          </div>
        )}
      </div>
    </div>
  );
}
