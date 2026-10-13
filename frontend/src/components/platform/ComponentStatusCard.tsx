/**
 * Platform Status building blocks: a status card and the 24h status strip.
 * Status is always conveyed by a text label as well as colour.
 */

import { Card, CardContent } from '@/components/ui/card';
import { cn } from '@/lib/utils';
import type { PlatformComponentStatus, PlatformHealthStatus } from '@/types/platform';

// 48 half-hour bars: 288 five-minute bars cannot fit a card (each needs >= 1px plus a gap) and overflowed it.
const STRIP_SLOTS = 48;
const SLOT_MS = 30 * 60 * 1000;
const SEVERITY: Record<PlatformHealthStatus, number> = { ok: 0, unknown: 1, degraded: 2, down: 3 };
const hhmm = (ms: number) => new Date(ms).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });

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
  // Bars sit on clock half-hours, so every card's strip lines up; the last bar is the current half hour.
  const end = Math.floor(now / SLOT_MS) * SLOT_MS + SLOT_MS;
  const start = end - STRIP_SLOTS * SLOT_MS;
  const slots: (PlatformHealthStatus | null)[] = Array(STRIP_SLOTS).fill(null);
  for (const h of history) {
    const i = Math.floor((new Date(h.checked_at).getTime() - start) / SLOT_MS);
    if (i < 0 || i >= STRIP_SLOTS) continue;
    const cur = slots[i];
    if (cur === null || SEVERITY[h.status] > SEVERITY[cur]) slots[i] = h.status; // worst status in the half hour
  }
  const bad = slots.filter((s) => s === 'down').length;
  const degraded = slots.filter((s) => s === 'degraded').length;

  return (
    <div className="space-y-1">
      <div
        role="img"
        aria-label={`Last 24 hours: ${bad} down and ${degraded} degraded half-hour periods`}
        className="grid h-6 w-full gap-[2px]"
        style={{ gridTemplateColumns: `repeat(${STRIP_SLOTS}, minmax(0, 1fr))` }}
      >
        {slots.map((s, i) => {
          const from = start + i * SLOT_MS;
          return (
            <div
              key={i}
              title={`${hhmm(from)}–${hhmm(from + SLOT_MS)}: ${s ?? 'no data'}`}
              className={cn('rounded-[1px]', s ? styles[s].segment : 'bg-muted')}
            />
          );
        })}
      </div>
      <div className="flex justify-between text-[10px] text-muted-foreground" aria-hidden>
        <span>24h ago</span>
        <span>now</span>
      </div>
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
