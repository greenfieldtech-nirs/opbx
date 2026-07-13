import { useQuery } from '@tanstack/react-query';
import { Loader2, AlertTriangle, PhoneOutgoing, Clock, RotateCw } from 'lucide-react';
import { getWebPhoneCallsLog } from '@/services/webPhone.service';
import type { WebPhoneCallLogEntry } from '@/types/webPhone.types';

interface CallsLogViewProps {
  // Called when a row is tapped: switch to the dialer and auto-dial this number.
  onRedial: (destination: string) => void;
  // Log is refetched whenever this becomes true (i.e. the tab is opened).
  active: boolean;
}

function formatWhen(iso: string): string {
  const d = new Date(iso);
  if (Number.isNaN(d.getTime())) return '';
  return d.toLocaleString(undefined, {
    month: 'short',
    day: 'numeric',
    hour: 'numeric',
    minute: '2-digit',
  });
}

export function CallsLogView({ onRedial, active }: CallsLogViewProps) {
  const { data, isLoading, error } = useQuery({
    queryKey: ['webphone-calls-log'],
    queryFn: getWebPhoneCallsLog,
    enabled: active,
    refetchOnMount: 'always',
    staleTime: 0,
  });

  if (isLoading) {
    return (
      <div className="flex flex-col items-center justify-center gap-3 py-12">
        <Loader2 className="h-7 w-7 animate-spin text-muted-foreground" />
        <p className="text-sm text-muted-foreground">Loading recent calls...</p>
      </div>
    );
  }

  if (error) {
    return (
      <div className="flex flex-col items-center justify-center gap-3 py-12 text-center">
        <AlertTriangle className="h-8 w-8 text-destructive" />
        <p className="text-sm text-muted-foreground">Could not load recent calls.</p>
      </div>
    );
  }

  const entries: WebPhoneCallLogEntry[] = data?.data ?? [];

  if (entries.length === 0) {
    return (
      <div className="flex flex-col items-center justify-center gap-3 py-12 text-center">
        <Clock className="h-8 w-8 text-muted-foreground/60" />
        <p className="text-sm text-muted-foreground">No recent calls yet.</p>
      </div>
    );
  }

  return (
    <ul className="flex flex-col divide-y">
      {entries.map((entry, i) => (
        <li key={`${entry.session_timestamp}-${i}`}>
          <button
            type="button"
            onClick={() => onRedial(entry.to)}
            className="group flex w-full items-center justify-between gap-3 px-1 py-3 text-left hover:bg-muted/50 active:bg-muted transition-colors rounded-md"
            aria-label={`Redial ${entry.to}`}
          >
            <div className="flex items-center gap-3 min-w-0">
              <PhoneOutgoing
                className={`h-4 w-4 shrink-0 ${
                  entry.disposition === 'ANSWER' ? 'text-green-500' : 'text-red-500'
                }`}
              />
              <div className="min-w-0">
                <p className="truncate font-medium text-foreground">{entry.to}</p>
                <p className="truncate text-xs text-muted-foreground">
                  {formatWhen(entry.session_timestamp)}
                  {entry.duration > 0 && ` · ${entry.duration_formatted}`}
                </p>
              </div>
            </div>
            <span
              className="shrink-0 flex h-9 w-9 items-center justify-center rounded-full bg-green-500/10 text-green-600 group-hover:bg-green-500 group-hover:text-white transition-colors"
              aria-hidden="true"
            >
              <RotateCw className="h-4 w-4" />
            </span>
          </button>
        </li>
      ))}
    </ul>
  );
}

export default CallsLogView;
