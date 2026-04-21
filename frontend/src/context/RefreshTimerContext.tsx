/**
 * RefreshTimerContext
 *
 * Minimal context for sharing refresh timer state from pages to the AppLayout.
 * Pages set { interval, isRefreshing }, AppLayout reads it to render the bar.
 */

import { createContext, useContext, useState, useCallback, useEffect } from 'react';

interface RefreshTimerState {
  interval: number;
  isRefreshing: boolean;
}

interface RefreshTimerContextValue {
  state: RefreshTimerState | null;
  setRefreshState: (state: RefreshTimerState | null) => void;
}

const RefreshTimerContext = createContext<RefreshTimerContextValue | undefined>(undefined);

export function RefreshTimerProvider({ children }: { children: React.ReactNode }) {
  const [state, setState] = useState<RefreshTimerState | null>(null);

  const setRefreshState = useCallback((newState: RefreshTimerState | null) => {
    setState(newState);
  }, []);

  return (
    <RefreshTimerContext.Provider value={{ state, setRefreshState }}>
      {children}
    </RefreshTimerContext.Provider>
  );
}

export function useRefreshTimerState() {
  const context = useContext(RefreshTimerContext);
  if (!context) {
    throw new Error('useRefreshTimerState must be used within RefreshTimerProvider');
  }
  return context;
}

/**
 * Hook for pages to declare their refresh timer state.
 * Call this in your page component. The timer bar will appear under the header.
 * The bar is hidden when the component unmounts or when interval <= 0.
 */
export function useRefreshTimer(interval: number, isRefreshing: boolean) {
  const { setRefreshState } = useRefreshTimerState();

  useEffect(() => {
    setRefreshState({ interval, isRefreshing });
  }, [interval, isRefreshing, setRefreshState]);

  // Hide bar on unmount
  useEffect(() => {
    return () => {
      setRefreshState(null);
    };
  }, [setRefreshState]);
}
