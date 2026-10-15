/**
 * Page Loading Indicator
 *
 * Global loading overlay shown above the app while a new page is being
 * fetched and its data loaded, and removed once the page is ready.
 *
 * Deliberately `pointer-events-none`: this is an operations console for live
 * telephony, so the overlay must never be able to trap a click, even if a
 * request hangs. It signals progress without taking the UI hostage.
 *
 * The TanStack Query subscription lives in this component rather than in
 * AppLayout so background polling re-renders this small overlay only, not
 * the sidebar, header and page content.
 */

import { Loader2 } from 'lucide-react';
import { usePageLoading } from '@/hooks/usePageLoading';

export function PageLoadingIndicator() {
  const loading = usePageLoading();

  if (!loading) {
    return null;
  }

  return (
    <div
      className="page-loader pointer-events-none fixed inset-0 z-[70]"
      role="status"
      aria-live="polite"
      aria-busy="true"
    >
      {/* Softens the stale page underneath without hiding it. */}
      <div className="absolute inset-0 bg-background/50 backdrop-blur-[1px]" />

      {/* Indeterminate top bar - the familiar "page is loading" affordance. */}
      <div className="absolute inset-x-0 top-0 h-0.5 overflow-hidden bg-primary/20">
        <div className="page-loader-bar h-full w-1/3 bg-primary" />
      </div>

      {/* Centred badge, so the cue is visible wherever the user is looking. */}
      <div className="absolute inset-0 flex items-center justify-center">
        <div className="flex items-center gap-3 rounded-full border bg-card px-4 py-2 shadow-lg">
          <Loader2 className="page-loader-spinner h-4 w-4 animate-spin text-primary" />
          <span className="text-sm font-medium text-card-foreground">Loading…</span>
        </div>
      </div>
    </div>
  );
}
