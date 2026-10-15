/**
 * Page Loading Store
 *
 * Tracks in-flight route chunk downloads so the global page loading indicator
 * can stay up while a lazily imported page is still being fetched.
 *
 * Why this exists: the router is configured without route loaders, so
 * `useNavigation()` never reports a pending state. The delay on navigation
 * comes from the lazy() chunk request plus the new page's TanStack Query
 * fetches. React renders router navigations inside a transition, and a
 * transition deliberately keeps the previous page on screen rather than
 * showing an already-mounted Suspense fallback - which is why navigation
 * currently looks frozen with no indication that anything is happening.
 */

import { lazy } from 'react';
import type { ComponentType } from 'react';

type Listener = () => void;

let pendingChunks = 0;
const listeners = new Set<Listener>();

let emitScheduled = false;

/**
 * React.lazy() runs its factory during the render phase, so the counter is
 * mutated while React is mid-render of the suspended transition. Notifying
 * subscribers at that moment risks the update being folded into that same
 * suspended transition and never committing - which would keep the indicator
 * off screen for exactly as long as the chunk takes. Deferring to a microtask
 * lets the notification land as a fresh, sync-priority update instead, still
 * within the same frame as the click.
 */
function emit() {
  if (emitScheduled) {
    return;
  }
  emitScheduled = true;
  queueMicrotask(() => {
    emitScheduled = false;
    listeners.forEach((listener) => listener());
  });
}

export function subscribePendingChunks(listener: Listener) {
  listeners.add(listener);
  return () => {
    listeners.delete(listener);
  };
}

export function getPendingChunks() {
  return pendingChunks;
}

/**
 * Drop-in replacement for React.lazy() that reports chunk download progress
 * to the page loading indicator. The counter is decremented on rejection too,
 * so a failed chunk cannot leave the indicator armed.
 */
export function trackedLazy<T extends ComponentType<any>>(
  factory: () => Promise<{ default: T }>,
) {
  return lazy(() => {
    pendingChunks += 1;
    emit();

    return factory().finally(() => {
      pendingChunks = Math.max(0, pendingChunks - 1);
      emit();
    });
  });
}
