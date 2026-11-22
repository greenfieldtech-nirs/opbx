/**
 * AI Real Time Monitor
 *
 * Slide-show of widgets for admins: the AI Assistants view and the AI Load Balancers view
 * alternate every 30 seconds by default (10 seconds to 5 minutes). Figures cover the chosen time frame (last 60 minutes by
 * default, 1 minute to 12 hours) and refresh every 10 seconds.
 */

import { useEffect, useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { Bot, ChevronLeft, ChevronRight, Layers, Pause, Play } from 'lucide-react';

import { aiMonitorService } from '@/services/aiMonitor.service';
import { EmptyState } from '@/components/design-system';
import { Button } from '@/components/ui/button';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { AssistantCard, LoadBalancerCard } from './AiMonitor/widgets';

export const TIME_FRAMES = [
  { minutes: 1, label: 'Last minute', short: '1 min' },
  { minutes: 5, label: 'Last 5 minutes', short: '5 min' },
  { minutes: 15, label: 'Last 15 minutes', short: '15 min' },
  { minutes: 30, label: 'Last 30 minutes', short: '30 min' },
  { minutes: 60, label: 'Last 60 minutes', short: '60 min' },
  { minutes: 180, label: 'Last 3 hours', short: '3 h' },
  { minutes: 360, label: 'Last 6 hours', short: '6 h' },
  { minutes: 720, label: 'Last 12 hours', short: '12 h' },
] as const;

export const SLIDE_INTERVALS = [
  { seconds: 10, label: 'Every 10 seconds' },
  { seconds: 15, label: 'Every 15 seconds' },
  { seconds: 30, label: 'Every 30 seconds' },
  { seconds: 60, label: 'Every minute' },
  { seconds: 120, label: 'Every 2 minutes' },
  { seconds: 300, label: 'Every 5 minutes' },
] as const;

const DEFAULT_MINUTES = 60;
const DEFAULT_SLIDE_SECONDS = 30;
const REFRESH_MS = 10_000;
const TIME_FRAME_KEY = 'ai-monitor-minutes';
const SLIDE_KEY = 'ai-monitor-slide-seconds';

const VIEWS = [
  { id: 'assistants', label: 'Assistants' },
  { id: 'load-balancers', label: 'Load Balancers' },
] as const;

/** A remembered choice, or the default when nothing valid was saved (or storage is blocked). */
function saved(key: string, allowed: readonly number[], fallback: number): number {
  try {
    const value = Number(localStorage.getItem(key));
    return allowed.includes(value) ? value : fallback;
  } catch {
    return fallback;
  }
}

function remember(key: string, value: number) {
  try {
    localStorage.setItem(key, String(value));
  } catch {
    // private mode: the choice just isn't remembered
  }
}

export default function AiMonitor() {
  const [minutes, setMinutes] = useState(() => saved(TIME_FRAME_KEY, TIME_FRAMES.map((t) => t.minutes), DEFAULT_MINUTES));
  const [slideSeconds, setSlideSeconds] = useState(() =>
    saved(SLIDE_KEY, SLIDE_INTERVALS.map((i) => i.seconds), DEFAULT_SLIDE_SECONDS),
  );
  const [view, setView] = useState(0);
  const [paused, setPaused] = useState(false);
  const [secondsLeft, setSecondsLeft] = useState(slideSeconds);

  const { data, isError, dataUpdatedAt } = useQuery({
    queryKey: ['ai-monitor', minutes],
    queryFn: () => aiMonitorService.snapshot(minutes),
    refetchInterval: REFRESH_MS,
    refetchIntervalInBackground: false,
    placeholderData: (previous) => previous,
  });

  const go = (next: number) => {
    setView((next + VIEWS.length) % VIEWS.length);
    setSecondsLeft(slideSeconds);
  };

  useEffect(() => {
    if (paused) return;
    const timer = setInterval(() => {
      if (document.hidden) return; // no rotation while nobody is looking
      setSecondsLeft((s) => s - 1);
    }, 1000);
    return () => clearInterval(timer);
  }, [paused]);

  useEffect(() => {
    if (secondsLeft > 0) return;
    setView((v) => (v + 1) % VIEWS.length);
    setSecondsLeft(slideSeconds);
  }, [secondsLeft, slideSeconds]);

  const changeMinutes = (value: string) => {
    setMinutes(Number(value));
    remember(TIME_FRAME_KEY, Number(value));
  };

  const changeSlideSeconds = (value: string) => {
    setSlideSeconds(Number(value));
    setSecondsLeft(Number(value)); // the new interval starts now
    remember(SLIDE_KEY, Number(value));
  };

  const frame = TIME_FRAMES.find((t) => t.minutes === minutes) ?? TIME_FRAMES[4];
  const current = VIEWS[view];
  const nextView = VIEWS[(view + 1) % VIEWS.length];

  return (
    <div className="flex min-h-full flex-col gap-5">
      <header className="flex flex-wrap items-center justify-between gap-4">
        <div>
          <h1 className="text-3xl font-bold tracking-tight">AI Real Time Monitor</h1>
          <p className="mt-1 text-muted-foreground">
            {current.label} · {frame.label.toLowerCase()}
            {dataUpdatedAt > 0 && ` · updated ${new Date(dataUpdatedAt).toLocaleTimeString()}`}
          </p>
        </div>
        <div className="flex flex-wrap items-center gap-2">
          <Select value={String(minutes)} onValueChange={changeMinutes}>
            <SelectTrigger className="w-[180px]" aria-label="Time frame">
              <SelectValue />
            </SelectTrigger>
            <SelectContent>
              {TIME_FRAMES.map((t) => (
                <SelectItem key={t.minutes} value={String(t.minutes)}>
                  {t.label}
                </SelectItem>
              ))}
            </SelectContent>
          </Select>
          <Select value={String(slideSeconds)} onValueChange={changeSlideSeconds}>
            <SelectTrigger className="w-[180px]" aria-label="Refresh">
              <SelectValue />
            </SelectTrigger>
            <SelectContent>
              {SLIDE_INTERVALS.map((i) => (
                <SelectItem key={i.seconds} value={String(i.seconds)}>
                  {i.label}
                </SelectItem>
              ))}
            </SelectContent>
          </Select>
          <div className="flex overflow-hidden rounded-md border" role="tablist" aria-label="View">
            {VIEWS.map((v, i) => (
              <button
                key={v.id}
                type="button"
                role="tab"
                aria-selected={i === view}
                onClick={() => go(i)}
                className={`px-4 py-2 text-sm ${i === view ? 'bg-foreground text-background' : 'text-muted-foreground hover:text-foreground'}`}
              >
                {v.label}
              </button>
            ))}
          </div>
          <Button variant="outline" size="icon" onClick={() => go(view - 1)} aria-label="Previous view">
            <ChevronLeft className="h-4 w-4" />
          </Button>
          <Button variant="outline" onClick={() => setPaused((p) => !p)}>
            {paused ? <Play className="mr-1 h-4 w-4" /> : <Pause className="mr-1 h-4 w-4" />}
            {paused ? 'Resume' : 'Pause'}
          </Button>
          <Button variant="outline" size="icon" onClick={() => go(view + 1)} aria-label="Next view">
            <ChevronRight className="h-4 w-4" />
          </Button>
        </div>
      </header>

      {isError && !data && <p className="text-destructive">The monitor could not load its figures. It will retry shortly.</p>}

      {data && current.id === 'assistants' && (
        data.assistants.length === 0 ? (
          <EmptyState icon={Bot} title="No AI assistants yet" description="Add an AI assistant to see its live figures here." />
        ) : (
          <main className="grid flex-1 grid-cols-1 gap-5 lg:grid-cols-2 2xl:grid-cols-3">
            {data.assistants.map((a) => (
              <AssistantCard key={a.id} assistant={a} windowLabel={frame.short} />
            ))}
          </main>
        )
      )}

      {data && current.id === 'load-balancers' && (
        data.load_balancers.length === 0 ? (
          <EmptyState icon={Layers} title="No AI load balancers yet" description="Add an AI load balancer to see its live figures here." />
        ) : (
          <main className="grid flex-1 grid-cols-1 gap-5 lg:grid-cols-2 2xl:grid-cols-3">
            {data.load_balancers.map((lb) => (
              <LoadBalancerCard key={lb.id} loadBalancer={lb} windowLabel={frame.short} />
            ))}
          </main>
        )
      )}

      <footer className="flex items-center gap-3 text-sm text-muted-foreground">
        <span className="flex gap-1.5" aria-hidden="true">
          {VIEWS.map((v, i) => (
            <i key={v.id} className={`h-2 w-2 rounded-full ${i === view ? 'bg-foreground' : 'bg-muted-foreground/30'}`} />
          ))}
        </span>
        <span>{current.label}</span>
        <div className="h-1.5 flex-1 overflow-hidden rounded-full bg-muted">
          <div className="h-full bg-primary transition-[width] duration-1000 ease-linear" style={{ width: `${((slideSeconds - secondsLeft) / slideSeconds) * 100}%` }} />
        </div>
        <span>{paused ? 'Paused' : `${nextView.label} next in ${secondsLeft}s`}</span>
      </footer>
    </div>
  );
}
