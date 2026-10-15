/**
 * Platform Status Page
 *
 * Health of every platform component with a 24h status strip. Auto-refreshes every 30s
 * while the tab is visible.
 */

import { Activity, CheckCircle2, AlertTriangle, RefreshCw } from 'lucide-react';
import { cn } from '@/lib/utils';
import { Button } from '@/components/ui/button';
import { PlatformLayout } from '@/components/platform';
import { ComponentStatusCard } from '@/components/platform/ComponentStatusCard';
import { QueryErrorState } from '@/components/design-system/QueryErrorState';
import { usePlatformStatus, useRunPlatformStatusCheck } from '@/hooks/platform';
import { getApiErrorMessage } from '@/services/api';

export default function PlatformStatus() {
  const { data, isLoading, isError, error, refetch } = usePlatformStatus();
  const check = useRunPlatformStatusCheck();

  if (isError && !data) {
    return (
      <PlatformLayout>
        <QueryErrorState title="Failed to load platform status" error={error} onRetry={() => void refetch()} />
      </PlatformLayout>
    );
  }

  const problems = (data?.components ?? []).filter((c) => c.status === 'down' || c.status === 'degraded');
  const down = problems.filter((c) => c.status === 'down');

  return (
    <PlatformLayout>
      <div className="space-y-6">
        <div className="flex items-center justify-between gap-3">
          <div>
            <h1 className="text-3xl font-bold flex items-center gap-2">
              <Activity className="h-8 w-8" />
              Platform Status
            </h1>
            <p className="text-muted-foreground mt-1">
              {data?.checked_at ? `Last check ${new Date(data.checked_at).toLocaleString()}` : 'No checks recorded yet'}
            </p>
          </div>
          <Button onClick={() => check.mutate()} disabled={check.isPending}>
            <RefreshCw className={cn('h-4 w-4 mr-2', check.isPending && 'animate-spin')} />
            {check.isPending ? 'Checking...' : 'Check now'}
          </Button>
        </div>

        {check.isError && (
          <p role="alert" className="text-sm text-destructive">
            Check failed: {getApiErrorMessage(check.error)}. You may have hit the rate limit (6 per minute); try again shortly.
          </p>
        )}

        {isLoading || !data ? (
          <p className="text-muted-foreground">Loading...</p>
        ) : (
          <>
            <div
              role="status"
              className={cn(
                'flex items-start gap-3 rounded-lg border p-4',
                down.length > 0
                  ? 'border-2 border-red-600 bg-red-50 dark:bg-red-950/40'
                  : problems.length > 0
                    ? 'border-amber-400 bg-amber-50 dark:bg-amber-950/30'
                    : 'border-green-500 bg-green-50 dark:bg-green-950/30',
              )}
            >
              {problems.length === 0 ? (
                <CheckCircle2 className="h-6 w-6 text-green-600 shrink-0" aria-hidden="true" />
              ) : (
                <AlertTriangle className="h-6 w-6 text-red-600 shrink-0" aria-hidden="true" />
              )}
              <div>
                <p className="font-semibold">
                  {problems.length === 0
                    ? 'All systems operational'
                    : `${problems.length} component${problems.length === 1 ? '' : 's'} degraded or down`}
                </p>
                {problems.length > 0 && (
                  <p className="text-sm">
                    {problems.map((c) => `${c.label} (${c.status})`).join(', ')}
                  </p>
                )}
              </div>
            </div>

            <div className="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
              {data.components.map((c) => (
                <ComponentStatusCard key={c.component} component={c} />
              ))}
            </div>
          </>
        )}
      </div>
    </PlatformLayout>
  );
}
