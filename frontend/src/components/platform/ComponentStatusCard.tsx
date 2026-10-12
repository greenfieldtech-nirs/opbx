/**
 * Platform Status building blocks: a status card and the 24h status strip.
 * Status is always conveyed by a text label as well as colour.
 */

import { Card, CardContent } from '@/components/ui/card';
import { cn } from '@/lib/utils';
import type { PlatformComponentStatus, PlatformHealthStatus } from '@/types/platform';

const STRIP_SLOTS = 288; // 24h / 5 min
const SLOT_MS = 5 * 60 * 1000;

const styles: Record<PlatformHealthStatus, { badge: string; segment: string; card: string }> = {
  ok: {
    badge: 'bg-green-100 text-green-800 dark:bg-green-950 dark:text-green-300',
    segment: 'bg-green-500',
    card: '',
  },
  degraded: {
    badge: 'bg-amber-100 text-amber-900 dark:bg-amber-950 dark:text-amber-300',
    segment: 'bg-amber-500',
    card: 'border-amber-400',
  },
  down: {
    badge: 'bg-red-600 text-white',
    segment: 'bg-red-600',
    card: 'border-2 border-red-600 bg-red-50 dark:bg-red-950/40',
  },
  unknown: {
    badge: 'bg-muted text-muted-foreground',
    segment: 'bg-gray-400',
    card: 'opacity-70',
  },
};

export function statusLabel(c: Pick<PlatformComponentStatus, 'status' | 'optional'>): string {
  if (c.status === 'unknown' && c.optional) return 'Not running (optional)';
  return { ok: 'Operational', degraded: 'Degraded', down: 'Down', unknown: 'Unknown' }[c.status];
}

export function StatusStrip({
  history,
  now = Date.now(),
}: {
  history: PlatformComponentStatus['history'];
  now?: number;
}) {
  const start = now - STRIP_SLOTS * SLOT_MS;
  const slots: ({ at: string; status: PlatformHealthStatus } | null)[] = Array(STRIP_SLOTS).fill(null);
  for (const h of history) {
    const i = Math.floor((new Date(h.checked_at).getTime() - start) / SLOT_MS);
    if (i >= 0 && i < STRIP_SLOTS) slots[i] = { at: h.checked_at, status: h.status };
  }
  const bad = history.filter((h) => h.status === 'down').length;
  const degraded = history.filter((h) => h.status === 'degraded').length;

  return (
    <div
      role="img"
      aria-label={`Last 24 hours: ${bad} down and ${degraded} degraded 5-minute periods`}
      className="flex h-6 gap-px"
    >
      {slots.map((s, i) => (
        <div
          key={i}
          title={s ? `${new Date(s.at).toLocaleString()}: ${s.status}` : 'No data'}
          className={cn('flex-1 min-w-px rounded-[1px]', s ? styles[s.status].segment : 'bg-muted')}
        />
      ))}
    </div>
  );
}

export function ComponentStatusCard({ component: c }: { component: PlatformComponentStatus }) {
  return (
    <Card className={cn(styles[c.status].card)} data-testid={`component-${c.component}`} data-status={c.status}>
      <CardContent className="p-4 space-y-3">
        <div className="flex items-start justify-between gap-2">
          <h2 className="font-semibold">{c.label}</h2>
          <span className={cn('rounded px-2 py-0.5 text-xs font-medium whitespace-nowrap', styles[c.status].badge)}>
            {statusLabel(c)}
          </span>
        </div>
        <p className="text-sm text-muted-foreground break-words">{c.detail}</p>
        <div className="flex justify-between text-xs text-muted-foreground">
          <span>{c.latency_ms !== null ? `${c.latency_ms} ms` : 'No latency'}</span>
          <span>{c.checked_at ? `Checked ${new Date(c.checked_at).toLocaleTimeString()}` : 'Never checked'}</span>
        </div>
        <StatusStrip history={c.history} />
      </CardContent>
    </Card>
  );
}
