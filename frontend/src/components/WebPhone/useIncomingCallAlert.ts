import { useEffect, useRef } from 'react';

const NOTIFICATION_TAG = 'opbx-incoming-call';
const TITLE_FLASH_MS = 1000;

const notificationsSupported = () => typeof window !== 'undefined' && 'Notification' in window;

/**
 * Ask for permission to show incoming-call notifications. Browsers only show the prompt in
 * response to a user action, so call this from a click (opening the Web Phone). No-op once the
 * user has answered the prompt either way.
 */
export function requestNotificationPermission(): void {
  if (notificationsSupported() && Notification.permission === 'default') {
    void Notification.requestPermission().catch(() => undefined);
  }
}

/**
 * While an inbound call rings: flash the tab title, and when this window is not in front (another
 * tab, another app), show a browser notification. Clicking it brings this tab forward and calls
 * `onActivate` (open the Web Phone) so the user can answer. Everything is undone when ringing stops.
 */
export function useIncomingCallAlert(ringing: boolean, caller: string, onActivate: () => void, enabled = true): void {
  const activateRef = useRef(onActivate);
  useEffect(() => {
    activateRef.current = onActivate;
  }, [onActivate]);

  useEffect(() => {
    if (!ringing || !enabled) return;

    const original = document.title;
    const alertTitle = `Incoming call: ${caller}`;
    let flipped = true;
    document.title = alertTitle;
    const flash = window.setInterval(() => {
      flipped = !flipped;
      document.title = flipped ? alertTitle : original;
    }, TITLE_FLASH_MS);

    let notification: Notification | null = null;
    const notify = () => {
      if (notification || !notificationsSupported() || Notification.permission !== 'granted') return;
      if (!document.hidden && document.hasFocus()) return; // the user is looking at the ringing phone
      try {
        notification = new Notification('Incoming call', {
          body: `${caller}\nClick to open the Web Phone and answer.`,
          tag: NOTIFICATION_TAG,
          requireInteraction: true,
        });
        notification.onclick = () => {
          window.focus();
          activateRef.current();
          notification?.close();
        };
      } catch {
        // Some browsers only allow notifications from a service worker; the title still flashes.
      }
    };
    notify();
    // The user may switch away after the call started ringing.
    document.addEventListener('visibilitychange', notify);
    window.addEventListener('blur', notify);

    return () => {
      window.clearInterval(flash);
      document.title = original;
      document.removeEventListener('visibilitychange', notify);
      window.removeEventListener('blur', notify);
      notification?.close();
    };
  }, [ringing, caller, enabled]);
}
