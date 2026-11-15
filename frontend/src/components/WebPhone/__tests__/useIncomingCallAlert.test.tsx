import { renderHook } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { requestNotificationPermission, useIncomingCallAlert } from '../useIncomingCallAlert';

// Records every notification created; `permission` is switchable per test.
class FakeNotification {
  static permission: NotificationPermission = 'granted';
  static instances: FakeNotification[] = [];
  static requestPermission = vi.fn(() => Promise.resolve('granted' as NotificationPermission));
  onclick: (() => void) | null = null;
  close = vi.fn();
  constructor(public title: string, public options: NotificationOptions) {
    FakeNotification.instances.push(this);
  }
}

let focused = false;

beforeEach(() => {
  vi.useFakeTimers();
  FakeNotification.permission = 'granted';
  FakeNotification.instances = [];
  FakeNotification.requestPermission.mockClear();
  vi.stubGlobal('Notification', FakeNotification);
  focused = false;
  vi.spyOn(document, 'hasFocus').mockImplementation(() => focused);
  document.title = 'OPBX';
});

afterEach(() => {
  vi.useRealTimers();
  vi.unstubAllGlobals();
  vi.restoreAllMocks();
});

describe('useIncomingCallAlert', () => {
  it('flashes the tab title while ringing and restores it afterwards', () => {
    const { rerender } = renderHook(({ ringing }) => useIncomingCallAlert(ringing, 'Alice (1001)', () => {}), {
      initialProps: { ringing: true },
    });
    expect(document.title).toBe('Incoming call: Alice (1001)');
    vi.advanceTimersByTime(1000);
    expect(document.title).toBe('OPBX');
    vi.advanceTimersByTime(1000);
    expect(document.title).toBe('Incoming call: Alice (1001)');

    rerender({ ringing: false });
    expect(document.title).toBe('OPBX');
    vi.advanceTimersByTime(3000);
    expect(document.title).toBe('OPBX');
  });

  it('notifies when the window is not in front; a click opens the phone, focuses the tab and closes it', () => {
    const onActivate = vi.fn();
    const focus = vi.spyOn(window, 'focus').mockImplementation(() => {});
    renderHook(() => useIncomingCallAlert(true, '+15551234567', onActivate));

    expect(FakeNotification.instances).toHaveLength(1);
    const n = FakeNotification.instances[0];
    expect(n.title).toBe('Incoming call');
    expect(n.options.body).toContain('+15551234567');
    expect(n.options.requireInteraction).toBe(true);

    n.onclick?.();
    expect(focus).toHaveBeenCalled();
    expect(onActivate).toHaveBeenCalled();
    expect(n.close).toHaveBeenCalled();
  });

  it('closes the notification when the ringing stops (answered elsewhere, caller hung up)', () => {
    const { rerender } = renderHook(({ ringing }) => useIncomingCallAlert(ringing, '1001', () => {}), {
      initialProps: { ringing: true },
    });
    rerender({ ringing: false });
    expect(FakeNotification.instances[0].close).toHaveBeenCalled();
  });

  it('does not notify while the user is looking at the page, but does once they switch away', () => {
    focused = true;
    renderHook(() => useIncomingCallAlert(true, '1001', () => {}));
    expect(FakeNotification.instances).toHaveLength(0);

    focused = false;
    window.dispatchEvent(new Event('blur'));
    expect(FakeNotification.instances).toHaveLength(1);
    window.dispatchEvent(new Event('blur'));
    expect(FakeNotification.instances).toHaveLength(1); // one notification per call
  });

  it('shows no notification without permission, and nothing at all when disabled (embed)', () => {
    FakeNotification.permission = 'default';
    renderHook(() => useIncomingCallAlert(true, '1001', () => {}));
    expect(FakeNotification.instances).toHaveLength(0);
    expect(document.title).toBe('Incoming call: 1001');

    FakeNotification.permission = 'granted';
    document.title = 'Host page';
    renderHook(() => useIncomingCallAlert(true, '1001', () => {}, false));
    expect(FakeNotification.instances).toHaveLength(0);
  });
});

describe('requestNotificationPermission', () => {
  it('prompts only while the user has not decided yet', () => {
    FakeNotification.permission = 'default';
    requestNotificationPermission();
    expect(FakeNotification.requestPermission).toHaveBeenCalledTimes(1);

    FakeNotification.permission = 'denied';
    requestNotificationPermission();
    FakeNotification.permission = 'granted';
    requestNotificationPermission();
    expect(FakeNotification.requestPermission).toHaveBeenCalledTimes(1);
  });
});
