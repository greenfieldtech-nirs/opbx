import { formatDistanceToNowStrict } from 'date-fns';
import type { AiMonitorAssistant, AiMonitorLoadBalancer } from '@/services/aiMonitor.service';

export type Health = 'ok' | 'warn' | 'bad' | 'idle';

/** Failure share: 20 % or more is red, 5 % or more amber; nothing in the window is grey. */
export function healthOf(goodRate: number | null, calls: number): Health {
  if (calls === 0 || goodRate === null) return 'idle';
  const bad = 100 - goodRate;
  if (bad >= 20) return 'bad';
  if (bad >= 5) return 'warn';
  return 'ok';
}

const EDGE: Record<Health, string> = {
  ok: 'border-l-green-600',
  warn: 'border-l-amber-500',
  bad: 'border-l-red-600',
  idle: 'border-l-muted-foreground/40',
};

const RING: Record<Health, string> = {
  ok: '#16a34a',
  warn: '#e09a00',
  bad: '#dc2626',
  idle: '#9aa3b0',
};

const BADGE: Record<Health, string> = {
  ok: '',
  warn: 'bg-amber-100 text-amber-800 dark:bg-amber-950 dark:text-amber-300',
  bad: 'bg-red-100 text-red-700 dark:bg-red-950 dark:text-red-300',
  idle: 'bg-muted text-muted-foreground',
};

export function formatDuration(seconds: number | null): string {
  if (seconds === null) return '–';
  return `${Math.floor(seconds / 60)}:${String(seconds % 60).padStart(2, '0')}`;
}

/** Number and label stacked in the ring; sizes follow the ring, the % is set smaller. */
function Ring({ value, health, label }: { value: number | null; health: Health; label: string }) {
  const pct = value ?? 0;
  return (
    <div
      className="grid shrink-0 place-items-center rounded-full [--r:clamp(120px,8.5vw,170px)] h-[var(--r)] w-[var(--r)]"
      style={{ background: `conic-gradient(${RING[health]} ${pct}%, hsl(var(--muted)) 0)` }}
      role="img"
      aria-label={value === null ? `${label}: no calls` : `${label}: ${value}%`}
    >
      <div className="flex h-[76%] w-[76%] flex-col items-center justify-center rounded-full bg-card">
        <b className="whitespace-nowrap font-bold leading-[0.95] tabular-nums text-[calc(var(--r)*0.27)]">
          {value === null ? '–' : Math.round(value)}
          {value !== null && <i className="ml-[0.04em] not-italic text-[0.55em]">%</i>}
        </b>
        <small className="mt-[0.2em] leading-none text-muted-foreground text-[calc(var(--r)*0.085)]">{label}</small>
      </div>
    </div>
  );
}

function Stat({ value, label, highlight }: { value: string | number; label: string; highlight?: boolean }) {
  return (
    <div>
      <b className={`block font-bold leading-[1.05] tracking-tight text-[clamp(40px,3.3vw,66px)] ${highlight ? 'text-primary' : ''}`}>
        {value}
      </b>
      <span className="text-[clamp(13px,0.9vw,17px)] text-muted-foreground">{label}</span>
    </div>
  );
}

function Sparkline({ series, health, windowLabel }: { series: number[]; health: Health; windowLabel: string }) {
  const max = Math.max(1, ...series);
  const points = series.map((n, i) => `${(i / Math.max(1, series.length - 1)) * 120},${30 - (n / max) * 26}`).join(' ');
  const stroke = health === 'bad' ? '#dc2626' : health === 'idle' ? '#c3c9d2' : 'hsl(var(--primary))';
  return (
    <div className="mt-auto">
      <svg viewBox="0 0 120 30" preserveAspectRatio="none" className="block h-[38px] w-full" aria-hidden="true">
        <polygon points={`0,30 ${points} 120,30`} fill={stroke} fillOpacity={0.15} />
        <polyline points={points} fill="none" stroke={stroke} strokeWidth={1.6} strokeDasharray={health === 'idle' ? '3 3' : undefined} />
      </svg>
      <div className="mt-0.5 flex justify-between text-[11px] text-muted-foreground">
        <span>{windowLabel} ago</span>
        <span>calls over time</span>
        <span>now</span>
      </div>
    </div>
  );
}

function Card({ health, children, testId }: { health: Health; children: React.ReactNode; testId: string }) {
  return (
    <section
      data-testid={testId}
      data-health={health}
      className={`flex flex-col gap-3.5 rounded-2xl border border-l-[10px] bg-card px-6 pb-4 pt-5 shadow-sm ${EDGE[health]} ${
        health === 'bad' ? 'bg-gradient-to-t from-card from-70% to-red-50 dark:to-red-950/40' : ''
      }`}
    >
      {children}
    </section>
  );
}

function Title({ name, meta, badge, health }: { name: string; meta: string; badge?: string; health: Health }) {
  return (
    <div className="min-w-0">
      <div className="font-bold leading-tight text-[clamp(24px,1.7vw,34px)]">
        {name}
        {badge && (
          <span className={`ml-2 inline-block rounded-full px-2 py-0.5 align-middle font-semibold text-[clamp(12px,0.8vw,15px)] ${BADGE[health]}`}>
            {badge}
          </span>
        )}
      </div>
      <div className="mt-1 truncate text-muted-foreground text-[clamp(14px,0.95vw,18px)]">{meta}</div>
    </div>
  );
}

export function AssistantCard({ assistant, windowLabel }: { assistant: AiMonitorAssistant; windowLabel: string }) {
  const health = healthOf(assistant.success_rate, assistant.calls);
  const lastCall = assistant.last_call_at
    ? `last call ${formatDistanceToNowStrict(new Date(assistant.last_call_at), { addSuffix: true })}`
    : 'no calls yet';
  const badge =
    health === 'idle' ? 'no calls' : health === 'ok' ? undefined : `${Math.round((100 - (assistant.success_rate ?? 0)) * 10) / 10}% failed`;

  return (
    <Card health={health} testId={`assistant-${assistant.id}`}>
      <div className="flex items-start justify-between gap-3">
        <Title name={assistant.name} meta={`${assistant.provider ?? 'Unknown provider'} · ${lastCall}`} badge={badge} health={health} />
        <Ring value={assistant.success_rate} health={health} label="success" />
      </div>
      <div className="grid grid-cols-4 gap-2">
        <Stat value={assistant.active} label="active now" highlight={assistant.active > 0} />
        <Stat value={assistant.calls} label="calls" />
        <Stat value={formatDuration(assistant.avg_duration_seconds)} label="avg duration" />
        <Stat value={assistant.ai_minutes} label="AI minutes" />
      </div>
      <Sparkline series={assistant.series} health={health} windowLabel={windowLabel} />
    </Card>
  );
}

const STRATEGY: Record<string, string> = { round_robin: 'Round robin', priority: 'Priority', percentage: 'Percentage' };

export function LoadBalancerCard({ loadBalancer, windowLabel }: { loadBalancer: AiMonitorLoadBalancer; windowLabel: string }) {
  const lb = loadBalancer;
  const health = healthOf(lb.handled_rate, lb.calls);
  const badge = health === 'idle' ? 'no calls' : lb.fell_back > 0 ? `${lb.fell_back} fell back` : undefined;

  return (
    <Card health={health} testId={`load-balancer-${lb.id}`}>
      <div className="flex items-start justify-between gap-3">
        <Title name={lb.name} meta={`${STRATEGY[lb.strategy] ?? lb.strategy} · ${lb.members.length} members`} badge={badge} health={health} />
        <Ring value={lb.handled_rate} health={health} label="handled" />
      </div>
      <div className="grid grid-cols-4 gap-2">
        <Stat value={lb.active} label="active now" highlight={lb.active > 0} />
        <Stat value={lb.calls} label="calls" />
        <Stat value={lb.failovers} label="failovers" />
        <Stat value={lb.fell_back} label="fell back" />
      </div>
      <div className="space-y-3" aria-label="Split of calls between members">
        {lb.members.map((m) => (
          <div key={m.ai_assistant_id} className={m.active ? '' : 'opacity-50'}>
            <div className="mb-1 flex justify-between gap-3 font-medium text-[clamp(17px,1.25vw,24px)]">
              <span className="truncate">{m.name ?? 'Deleted assistant'}</span>
              <span className="shrink-0 tabular-nums text-muted-foreground">
                {m.actual_share ?? 0}%{m.configured_share !== null ? ` / ${m.configured_share}% set` : lb.strategy === 'priority' ? ` · priority ${m.priority}` : ''}
              </span>
            </div>
            <div className="relative rounded-full bg-muted [--bar:clamp(14px,1vw,20px)] h-[var(--bar)]">
              <div className="h-full rounded-full bg-primary" style={{ width: `${m.actual_share ?? 0}%` }} />
              {m.configured_share !== null && (
                <div
                  className="absolute -top-1 h-[calc(var(--bar)+8px)] w-1 -translate-x-1/2 rounded-full bg-foreground"
                  style={{ left: `${m.configured_share}%` }}
                  title="configured share"
                />
              )}
            </div>
          </div>
        ))}
      </div>
      <Sparkline series={lb.series} health={health} windowLabel={windowLabel} />
    </Card>
  );
}
