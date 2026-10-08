/**
 * usePageLoading Hook
 *
 * Returns whether the global page loading indicator should be on screen.
 *
 * A "navigation window" opens the moment a page starts loading and closes once
 * both signals of page work have been quiet for a moment:
 *
 *  - route chunk downloads  (see @/lib/pageLoadingStore)
 *  - TanStack Query fetches (useIsFetching)
 *
 * The window is opened by whichever signal comes first, because the two
 * navigation paths differ:
 *
 *  - First visit to a page: the lazy chunk suspends inside React Router's
 *    transition, and React withholds the commit until it resolves, so
 *    useLocation() keeps reporting the OLD pathname for the entire download.
 *    The chunk counter is the only signal available at click time.
 *  - Revisit: the chunk is cached, nothing suspends, the transition commits
 *    immediately and the pathname change is the earliest signal.
 *
 * Scoping to a navigation window matters: the query client runs with
 * staleTime/gcTime of 0 and several pages poll on a refetchInterval, so a
 * bare useIsFetching() would flash the overlay on every background poll.
 * Outside a navigation window, ongoing fetches are ignored - pages render
 * their own skeletons and table spinners for that.
 */

import { useEffect, useRef, useState, useSyncExternalStore } from 'react';
import { useLocation } from 'react-router-dom';
import { useIsFetching } from '@tanstack/react-query';
import { getPendingChunks, subscribePendingChunks } from '@/lib/pageLoadingStore';

/** Brief hold so a genuinely instant navigation doesn't flash the overlay. */
const SHOW_DELAY_MS = 100;
/** Once shown, stay up at least this long so it doesn't blink in and out. */
const MIN_VISIBLE_MS = 300;
/** Activity must stay quiet this long before the navigation counts as done. */
const SETTLE_MS = 150;
/** Safety net: a hung request must never pin the indicator on screen. */
const MAX_PENDING_MS = 15000;

export function usePageLoading(): boolean {
  const { pathname } = useLocation();
  const isFetching = useIsFetching();
  const pendingChunks = useSyncExternalStore(
    subscribePendingChunks,
    getPendingChunks,
    getPendingChunks,
  );

  // The first mount is itself a page load, so start armed.
  const [windowOpen, setWindowOpen] = useState(true);
  const [visible, setVisible] = useState(false);
  const shownAtRef = useRef(0);

  const busy = pendingChunks > 0 || isFetching > 0;

  // Derived, not stored: an in-flight chunk counts as pending on the very
  // render that observes it. Routing it through setState in an effect would
  // add a render hop, and effects are exactly what a suspended transition can
  // delay - which is how the indicator ended up appearing only at the end.
  const pending = windowOpen || pendingChunks > 0;

  // Hold the window open past the chunk download so it also spans the new
  // page's data fetches, which start only once the route finally commits.
  useEffect(() => {
    if (pendingChunks > 0) {
      setWindowOpen(true);
    }
  }, [pendingChunks]);

  // A revisited page has its chunk cached, so nothing suspends, the transition
  // commits straight away and this becomes the earliest available signal.
  useEffect(() => {
    setWindowOpen(true);
  }, [pathname]);

  // Hard cap so a hung fetch can never pin the indicator on screen. A chunk
  // that never arrives self-heals instead: React.lazy rejects and the store
  // decrements in its finally block.
  useEffect(() => {
    if (!pending) {
      return undefined;
    }
    const cap = window.setTimeout(() => setWindowOpen(false), MAX_PENDING_MS);
    return () => window.clearTimeout(cap);
  }, [pending]);

  // Close the window once chunk and query activity have both settled.
  // SETTLE_MS bridges the gap between the chunk resolving and the page's
  // queries starting, which would otherwise read as "already finished".
  useEffect(() => {
    if (!pending || busy) {
      return undefined;
    }
    const settle = window.setTimeout(() => setWindowOpen(false), SETTLE_MS);
    return () => window.clearTimeout(settle);
  }, [pending, busy]);

  // Delay showing, and enforce a minimum on-screen time once shown.
  useEffect(() => {
    if (pending) {
      if (visible) {
        return undefined;
      }
      const show = window.setTimeout(() => {
        shownAtRef.current = Date.now();
        setVisible(true);
      }, SHOW_DELAY_MS);
      return () => window.clearTimeout(show);
    }

    if (!visible) {
      return undefined;
    }
    const remaining = Math.max(0, MIN_VISIBLE_MS - (Date.now() - shownAtRef.current));
    const hide = window.setTimeout(() => setVisible(false), remaining);
    return () => window.clearTimeout(hide);
  }, [pending, visible]);

  return visible;
}
