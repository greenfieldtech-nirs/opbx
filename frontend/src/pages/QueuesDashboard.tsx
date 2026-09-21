/**
 * Queues Dashboard
 *
 * Full-screen dashboard for a single queue at a time: live snapshot plus
 * timespan aggregates. In "All Queues" mode the dashboard cycles through the
 * configured queues, 30 seconds each.
 */

import { useEffect, useMemo, useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { ArrowLeft, ArrowRight, Clock, PhoneCall, Timer, Users } from 'lucide-react';

import {
  callQueuesService,
  queueStatsService,
  type CallQueue,
} from '@/services/callQueues.service';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
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

/** Agent card backgrounds by presence state. */
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

function StatCell({ label, value }: { label: string; value: string }) {
  return (
    <div className="text-center">
      <div className="text-3xl font-bold">{value}</div>
      <div className="text-sm text-muted-foreground">{label}</div>
    </div>
  );
}

export default function QueuesDashboard() {
  const [selectedQueueId, setSelectedQueueId] = useState<string>('all');
  const [timespan, setTimespan] = useState<Timespan>('1h');
  const [cycleIndex, setCycleIndex] = useState(0);
  const [cycleTick, setCycleTick] = useState(ROTATE_SECONDS);

  const { data: queuesData } = useQuery({
    queryKey: ['call-queues'],
    queryFn: () => callQueuesService.getAll({ per_page: 100 }),
    refetchInterval: 60000,
  });

  const queues = queuesData?.data ?? [];
  const cycling = selectedQueueId === 'all';
  const activeQueue: CallQueue | undefined = cycling
    ? queues[cycleIndex % Math.max(queues.length, 1)]
    : queues.find((q) => String(q.id) === selectedQueueId);

  // Rotation: advance queue every 30s in "All Queues" mode.
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
    queryKey: ['queues-dashboard', queueId, 'live'],
    queryFn: () => queueStatsService.live(queueId!),
    enabled: queueId !== undefined,
    refetchInterval: 5000,
  });

  // Agent names come from the queue detail (live only carries user/extension).
  const { data: queueDetailData } = useQuery({
    queryKey: ['call-queues', queueId],
    queryFn: () => callQueuesService.getById(queueId!),
    enabled: queueId !== undefined,
    staleTime: 60000,
  });

  const agentNameById = new Map(
    (queueDetailData?.data.agents ?? []).map((a) => [a.id, a.name]),
  );

  const { data: statsData } = useQuery({
    queryKey: ['queues-dashboard', queueId, 'stats', timespan],
    queryFn: () => queueStatsService.stats(queueId!, fromIso),
    enabled: queueId !== undefined,
    refetchInterval: 30000,
  });

  if (!queues.length) {
    return (
      <div className="space-y-6">
        <h1 className="text-2xl font-semibold">Queues Dashboard</h1>
        <EmptyState
          icon={PhoneCall}
          title="No call queues"
          description="Create a call queue to see its live dashboard here."
        />
      </div>
    );
  }

  const live = liveData?.data;
  const stats = statsData?.data;

  return (
    <div className="space-y-6">
      <div className="flex flex-wrap items-center justify-between gap-4">
        <div>
          <h1 className="text-2xl font-semibold">Queues Dashboard</h1>
          <p className="text-sm text-muted-foreground">
            {cycling
              ? `Cycling all queues · next in ${cycleTick}s`
              : 'Showing selected queue'}
          </p>
        </div>
        <div className="flex items-end gap-4">
          <div className="space-y-2">
            <Label>Queue</Label>
            <Select value={selectedQueueId} onValueChange={setSelectedQueueId}>
              <SelectTrigger className="w-[220px]">
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
              <SelectTrigger className="w-[180px]">
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
          {cycling && (
            <Button
              variant="outline"
              size="icon"
              title="Next queue"
              onClick={() => {
                setCycleIndex((i) => (i + 1) % queues.length);
                setCycleTick(ROTATE_SECONDS);
              }}
            >
              <ArrowRight className="h-4 w-4" />
            </Button>
          )}
        </div>
      </div>

      {activeQueue ? (
        <div className="space-y-4">
          <div className="flex items-center gap-3">
            <h2 className="text-xl font-semibold">{activeQueue.name}</h2>
            <Badge variant="outline">{activeQueue.strategy.replace(/_/g, ' ')}</Badge>
            {cycling && (
              <span className="text-sm text-muted-foreground">
                ({(cycleIndex % queues.length) + 1} of {queues.length})
              </span>
            )}
          </div>

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
                <CardTitle className="text-sm font-medium">Handled</CardTitle>
                <Timer className="h-4 w-4 text-muted-foreground" />
              </CardHeader>
              <CardContent className="text-2xl font-bold">{stats?.totals.handled ?? 0}</CardContent>
            </Card>
            <Card>
              <CardHeader className="flex flex-row items-center justify-between pb-2">
                <CardTitle className="text-sm font-medium">Abandoned</CardTitle>
                <Clock className="h-4 w-4 text-muted-foreground" />
              </CardHeader>
              <CardContent className="text-2xl font-bold">{stats?.totals.abandoned ?? 0}</CardContent>
            </Card>
          </div>

          <Card>
            <CardHeader>
              <CardTitle className="text-base">
                Waiting & Handling — {TIMESPANS.find((t) => t.value === timespan)?.label}
              </CardTitle>
            </CardHeader>
            <CardContent>
              <div className="grid grid-cols-2 md:grid-cols-4 gap-4">
                <StatCell label="Avg Wait" value={formatSeconds(stats?.waiting_time.avg ? Math.round(stats.waiting_time.avg) : null)} />
                <StatCell label="Max Wait" value={formatSeconds(stats?.waiting_time.max)} />
                <StatCell label="Avg Handling" value={formatSeconds(stats?.handling_time.avg ? Math.round(stats.handling_time.avg) : null)} />
                <StatCell label="Overflowed" value={String(stats?.totals.overflowed ?? 0)} />
              </div>
            </CardContent>
          </Card>

          <Card>
            <CardHeader>
              <CardTitle className="text-base">Agents</CardTitle>
            </CardHeader>
            <CardContent>
              {(live?.agents.length ?? 0) === 0 ? (
                <p className="text-sm text-muted-foreground">
                  No agents have logged in to this queue yet. Agents dial *45{activeQueue.id} to log in.
                </p>
              ) : (
                <div className="grid grid-cols-2 md:grid-cols-4 gap-3">
                  {live!.agents.map((agent) => {
                    const statusText =
                      agent.state === 'WRAP_UP' && agent.wrapUpRemainingSeconds
                        ? `Wrap-up · ${agent.wrapUpRemainingSeconds}s left`
                        : agent.state.replace('_', ' ');
                    return (
                      <div
                        key={agent.userId}
                        className={`rounded-lg border p-3 ${AGENT_CARD_STYLES[agent.state] ?? AGENT_CARD_STYLES.LOGGED_OUT}`}
                      >
                        <div className="text-sm font-semibold text-gray-900">
                          {agentNameById.get(Number(agent.userId)) ?? `User ${agent.userId}`}
                        </div>
                        <div className="text-xs text-gray-800">Ext {agent.extensionNumber ?? '—'}</div>
                        <div className="text-xs text-gray-700">{statusText}</div>
                      </div>
                    );
                  })}
                </div>
              )}
            </CardContent>
          </Card>

          {cycling && queues.length > 1 && (
            <div className="flex items-center gap-2">
              {queues.map((q, i) => (
                <button
                  key={q.id}
                  type="button"
                  title={q.name}
                  onClick={() => {
                    setCycleIndex(i);
                    setCycleTick(ROTATE_SECONDS);
                  }}
                  className={`h-2 rounded-full transition-all ${
                    i === cycleIndex % queues.length ? 'w-6 bg-primary' : 'w-2 bg-muted'
                  }`}
                />
              ))}
            </div>
          )}
        </div>
      ) : (
        <EmptyState
          icon={ArrowLeft}
          title="Queue not found"
          description="The selected queue no longer exists."
        />
      )}
    </div>
  );
}
