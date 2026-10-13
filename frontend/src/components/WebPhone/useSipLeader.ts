import { useCallback, useEffect, useState } from 'react';

/**
 * Only one browser tab may hold the SIP registration for a user. The tab holding the Web Lock
 * is the leader; the others are followers, queued behind it so the next one takes over when the
 * leader tab closes or the user logs out. A follower can take over at once ("Use here").
 *
 * Without the Web Locks API (old browsers, tests) every tab is a leader, as before.
 */
export type SipRole = 'pending' | 'leader' | 'follower';

export const SIP_LOCK_NAME = 'opbx-webphone';

export function useSipLeader(enabled: boolean, lockName: string = SIP_LOCK_NAME): { role: SipRole; takeOver: () => void } {
  const [role, setRole] = useState<SipRole>('pending');
  const [attempt, setAttempt] = useState({ n: 0, steal: false });

  useEffect(() => {
    if (!enabled) {
      setRole('pending');
      return;
    }
    const locks = typeof navigator !== 'undefined' ? navigator.locks : undefined;
    if (!locks) {
      setRole('leader');
      return;
    }

    const abort = new AbortController();
    let release: (() => void) | null = null;
    let disposed = false;

    // Holding the lock = keeping this promise pending until release() runs.
    const hold = () =>
      new Promise<void>((resolve) => {
        release = resolve;
        if (!disposed) setRole('leader');
      });

    const run = async () => {
      try {
        if (attempt.steal) {
          await locks.request(lockName, { steal: true }, hold);
          return;
        }
        await locks.request(lockName, { ifAvailable: true }, async (lock) => {
          if (lock) {
            await hold();
            return;
          }
          if (!disposed) setRole('follower');
          await locks.request(lockName, { signal: abort.signal }, hold);
        });
      } catch {
        // AbortError: our queued request was cancelled (unmount), or the lock was stolen from us.
        if (!disposed) {
          setRole('follower');
          // Re-queue behind whoever took it, so we resume when they leave.
          setAttempt((a) => ({ n: a.n + 1, steal: false }));
        }
      }
    };
    void run();

    return () => {
      disposed = true;
      abort.abort();
      release?.();
    };
  }, [enabled, lockName, attempt]);

  const takeOver = useCallback(() => setAttempt((a) => ({ n: a.n + 1, steal: true })), []);

  return { role, takeOver };
}
