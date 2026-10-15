import { act, renderHook, waitFor } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it } from 'vitest';
import { useSipLeader } from '../useSipLeader';

// Minimal single-name Web Locks fake: exclusive lock, FIFO queue, ifAvailable, steal, abort signals.
function installFakeLocks() {
  type Holder = { release: () => void; abort: (e: Error) => void };
  let holder: Holder | null = null;
  const queue: Array<() => void> = [];

  const grant = (cb: (lock: unknown) => Promise<void>): Promise<void> =>
    new Promise<void>((resolve, reject) => {
      let settled = false;
      const finish = () => {
        if (settled) return;
        settled = true;
        holder = null;
        queue.shift()?.();
      };
      holder = {
        release: finish,
        abort: (e) => {
          if (settled) return;
          settled = true;
          holder = null;
          reject(e);
        },
      };
      cb({}).then(() => { finish(); resolve(); }, reject);
    });

  const locks = {
    request(_name: string, opts: any, cb: (lock: unknown) => Promise<void>): Promise<void> {
      if (opts.steal) {
        holder?.abort(new DOMException('stolen', 'AbortError'));
        return grant(cb);
      }
      if (!holder) return grant(cb);
      if (opts.ifAvailable) return Promise.resolve(cb(null));
      return new Promise<void>((resolve, reject) => {
        const start = () => grant(cb).then(resolve, reject);
        queue.push(start);
        opts.signal?.addEventListener('abort', () => {
          const i = queue.indexOf(start);
          if (i >= 0) queue.splice(i, 1);
          reject(new DOMException('aborted', 'AbortError'));
        });
      });
    },
  };
  Object.defineProperty(navigator, 'locks', { value: locks, configurable: true });
}

describe('useSipLeader', () => {
  beforeEach(installFakeLocks);
  afterEach(() => {
    Object.defineProperty(navigator, 'locks', { value: undefined, configurable: true });
  });

  it('is leader without Web Locks support', async () => {
    Object.defineProperty(navigator, 'locks', { value: undefined, configurable: true });
    const { result } = renderHook(() => useSipLeader(true));
    await waitFor(() => expect(result.current.role).toBe('leader'));
  });

  it('first tab leads, second follows, and takes over when the leader unmounts', async () => {
    const a = renderHook(() => useSipLeader(true));
    await waitFor(() => expect(a.result.current.role).toBe('leader'));
    const b = renderHook(() => useSipLeader(true));
    await waitFor(() => expect(b.result.current.role).toBe('follower'));

    a.unmount();
    await waitFor(() => expect(b.result.current.role).toBe('leader'));
  });

  it('a follower can take over and the previous leader steps down', async () => {
    const a = renderHook(() => useSipLeader(true));
    await waitFor(() => expect(a.result.current.role).toBe('leader'));
    const b = renderHook(() => useSipLeader(true));
    await waitFor(() => expect(b.result.current.role).toBe('follower'));

    act(() => b.result.current.takeOver());
    await waitFor(() => expect(b.result.current.role).toBe('leader'));
    await waitFor(() => expect(a.result.current.role).toBe('follower'));
  });

  it('releases the registration when disabled (logout)', async () => {
    const a = renderHook(({ on }) => useSipLeader(on), { initialProps: { on: true } });
    await waitFor(() => expect(a.result.current.role).toBe('leader'));
    const b = renderHook(() => useSipLeader(true));
    await waitFor(() => expect(b.result.current.role).toBe('follower'));

    a.rerender({ on: false });
    await waitFor(() => expect(b.result.current.role).toBe('leader'));
  });
});
