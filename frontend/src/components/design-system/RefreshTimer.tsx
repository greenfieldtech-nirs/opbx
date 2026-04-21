/**
 * RefreshTimer Component
 *
 * Simple self-contained progress bar for auto-refreshing pages.
 * Renders a thin line that fills between refreshes.
 *
 * Usage:
 *   <RefreshTimer interval={5000} isRefreshing={isFetching} onRefresh={refetch} />
 */

import { useEffect, useRef, useState } from 'react';
import { cn } from '@/lib/utils';

interface RefreshTimerProps {
  /** Refresh interval in milliseconds (0 = disabled) */
  interval: number;
  /** Whether data is currently being refreshed */
  isRefreshing: boolean;
  /** Callback when manual refresh is triggered */
  onRefresh: () => void;
  /** Optional className */
  className?: string;
}

export function RefreshTimer({ interval, isRefreshing, onRefresh, className }: RefreshTimerProps) {
  const [progress, setProgress] = useState(0);
  const startTimeRef = useRef(Date.now());
  const timerRef = useRef<ReturnType<typeof setInterval> | null>(null);
  const prevRefreshingRef = useRef(isRefreshing);

  useEffect(() => {
    // Clear existing timer
    if (timerRef.current) {
      clearInterval(timerRef.current);
      timerRef.current = null;
    }

    // Disabled or no interval
    if (interval <= 0) {
      setProgress(0);
      prevRefreshingRef.current = isRefreshing;
      return;
    }

    // Just finished refreshing → reset timer to 0 and restart
    if (prevRefreshingRef.current && !isRefreshing) {
      startTimeRef.current = Date.now();
      setProgress(0);
    }

    // Currently refreshing → show full bar
    if (isRefreshing) {
      setProgress(100);
      prevRefreshingRef.current = true;
      return;
    }

    // Normal state: tick progress every 50ms
    timerRef.current = setInterval(() => {
      const elapsed = Date.now() - startTimeRef.current;
      const pct = Math.min((elapsed / interval) * 100, 100);
      setProgress(pct);

      // When we hit 100%, trigger refresh
      if (pct >= 100) {
        onRefresh();
        // onRefresh will flip isRefreshing to true, which triggers
        // this effect again and resets everything
      }
    }, 50);

    prevRefreshingRef.current = isRefreshing;

    return () => {
      if (timerRef.current) {
        clearInterval(timerRef.current);
      }
    };
  }, [interval, isRefreshing, onRefresh]);

  // Don't render if disabled
  if (interval <= 0) {
    return null;
  }

  return (
    <div className={cn('h-[2px] w-full bg-gray-100', className)}>
      <div
        className={cn(
          'h-full transition-all ease-linear',
          isRefreshing ? 'bg-blue-500 animate-pulse w-full' : 'bg-primary'
        )}
        style={!isRefreshing ? { width: `${progress}%`, transitionDuration: '50ms' } : undefined}
      />
    </div>
  );
}
