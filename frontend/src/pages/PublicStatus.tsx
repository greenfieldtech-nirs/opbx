import { useQuery } from '@tanstack/react-query';
import { AlertTriangle, CheckCircle2, XOctagon } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { cn } from '@/lib/utils';
import { queryKeys } from '@/lib/queryKeys';
import { publicStatusService } from '@/services/publicStatus.service';
import { StatusStrip, statusStyles } from '@/components/platform/ComponentStatusCard';
import { HomeHeader } from './Home/HomeHeader';
import { Footer } from './Home/Footer';
import type { PlatformHealthStatus } from '@/types/platform';
import type { PublicStatusGroup } from '@/types/publicStatus';

const SLOT_MS = 5 * 60 * 1000;
const LABELS: Record<PlatformHealthStatus, string> = {
  ok: 'Operational',
  degraded: 'Degraded',
  down: 'Outage',
  unknown: 'Unknown',
};

export type Banner = 'operational' | 'partial' | 'major' | 'unknown';

/** Any group down => major if at least half the groups are down, else partial; any degraded => partial. */
export function bannerFor(groups: Pick<PublicStatusGroup, 'status'>[]): Banner {
  const down = groups.filter((g) => g.status === 'down').length;
  if (down > 0) return down * 2 >= groups.length ? 'major' : 'partial';
  if (groups.some((g) => g.status === 'degraded')) return 'partial';
  if (groups.length > 0 && groups.every((g) => g.status === 'unknown')) return 'unknown';
  return 'operational';
}

const BANNERS: Record<Banner, { text: string; box: string; Icon: typeof CheckCircle2 }> = {
  operational: { text: 'All systems operational', box: 'border-green-500 bg-green-500/10', Icon: CheckCircle2 },
  partial: { text: 'Partial outage', box: 'border-amber-400 bg-amber-500/10', Icon: AlertTriangle },
  major: { text: 'Major outage', box: 'border-red-600 bg-red-600/10', Icon: XOctagon },
  unknown: { text: 'Status currently unavailable', box: 'border-border bg-card', Icon: AlertTriangle },
};

export default function PublicStatus() {
  const { data, isLoading, isError, refetch } = useQuery({
    queryKey: queryKeys.publicStatus.all,
    queryFn: () => publicStatusService.get(),
    // Default polling pauses in hidden tabs.
    refetchInterval: 60 * 1000,
  });

  const banner = data ? BANNERS[bannerFor(data.groups)] : null;

  return (
    <div className="min-h-screen bg-background text-foreground home-dark oranienbaum-regular flex flex-col">
      <HomeHeader />
      <main className="container mx-auto px-4 py-12 flex-1 max-w-4xl">
        <h1 className="text-4xl font-bold mb-2">Platform Status</h1>
        <p className="text-muted-foreground mb-8">
          {data?.updated_at ? `Last updated ${new Date(data.updated_at).toLocaleString()}` : 'Current status of OPBX services'}
        </p>

        {isError && !data ? (
          <div role="alert" className="rounded-lg border border-border bg-card p-6 text-center">
            <p className="font-medium mb-1">We could not load the status right now.</p>
            <p className="text-muted-foreground mb-4">Please try again in a moment.</p>
            <Button onClick={() => void refetch()}>Retry</Button>
          </div>
        ) : isLoading || !data || !banner ? (
          <p className="text-muted-foreground">Loading...</p>
        ) : (
          <div className="space-y-6">
            <div role="status" className={cn('flex items-center gap-3 rounded-lg border-2 p-4', banner.box)}>
              <banner.Icon className="h-6 w-6 shrink-0" aria-hidden="true" />
              <p className="text-xl font-semibold">{banner.text}</p>
            </div>

            <ul className="space-y-4">
              {data.groups.map((g) => (
                <li key={g.key} data-testid={`group-${g.key}`} className="rounded-lg border border-border bg-card p-4 space-y-3">
                  <div className="flex items-center justify-between gap-3">
                    <h2 className="font-semibold">{g.name}</h2>
                    <span className={cn('rounded px-2 py-0.5 text-sm font-medium whitespace-nowrap', statusStyles[g.status].badge)}>
                      {LABELS[g.status]}
                    </span>
                  </div>
                  <StatusStrip
                    history={g.history.map((h) => ({ checked_at: h.at, status: h.status, check_at: h.check_at }))}
                    slotCount={72}
                    slotMs={SLOT_MS}
                    startLabel="6h ago"
                    periodLabel="Last 6 hours"
                    slotName="five-minute"
                  />
                </li>
              ))}
            </ul>
          </div>
        )}
      </main>
      <Footer />
    </div>
  );
}
