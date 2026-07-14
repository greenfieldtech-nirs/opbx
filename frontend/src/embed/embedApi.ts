// Widget-side postMessage bridge. Runs inside the OPBX-served iframe.
//
// Contract (mirrors resources/embed/loader.js):
//   host  -> widget: { source: 'opbx-dialer',        type: 'command', name, args }
//   widget -> host : { source: 'opbx-dialer-widget', type: 'event',   name, payload }
//
// The host origin is learned from the first inbound command's event.origin
// (the server already enforced framing via frame-ancestors, so we only need a
// concrete target origin — never '*'). Outbound events before the first
// command are buffered and flushed once the host origin is known.

import type {
  EmbedBus,
  EmbedCommandName,
  EmbedEventName,
} from '@/components/WebPhone/embedBus';

const HOST_SOURCE = 'opbx-dialer';
const WIDGET_SOURCE = 'opbx-dialer-widget';
const COMMANDS: EmbedCommandName[] = ['dial', 'hangup', 'open', 'close'];

interface OutboundEvent {
  name: EmbedEventName;
  payload?: unknown;
}

/**
 * Wire up the postMessage bridge and return the EmbedBus the WebPhone drives.
 * Also installs window.__OPBX_EMBED_BUS__ so WebPhone's embed effect finds it.
 */
export function createEmbedBus(): EmbedBus {
  let commandHandler: ((name: EmbedCommandName, args: unknown[]) => void) | null = null;
  let hostOrigin: string | null = null;
  const pending: OutboundEvent[] = [];

  function flush(): void {
    if (hostOrigin === null || window.parent === window) return;
    while (pending.length > 0) {
      const evt = pending.shift() as OutboundEvent;
      window.parent.postMessage(
        { source: WIDGET_SOURCE, type: 'event', name: evt.name, payload: evt.payload },
        hostOrigin,
      );
    }
  }

  window.addEventListener('message', (event: MessageEvent) => {
    // Only accept commands from our parent frame.
    if (event.source !== window.parent) return;

    const data = event.data;
    if (!data || data.source !== HOST_SOURCE || data.type !== 'command') return;
    if (!COMMANDS.includes(data.name)) return;

    // Lock onto the host origin the first time we hear from it.
    if (hostOrigin === null) {
      hostOrigin = event.origin;
      flush();
    }

    commandHandler?.(data.name, Array.isArray(data.args) ? data.args : []);
  });

  const bus: EmbedBus = {
    onCommand(handler) {
      commandHandler = handler;
    },
    emit(name, payload) {
      pending.push({ name, payload });
      flush();
    },
  };

  window.__OPBX_EMBED_BUS__ = bus;
  return bus;
}
