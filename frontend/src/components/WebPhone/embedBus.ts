// Shared contract between the embed widget entry (src/embed/main.tsx) and the
// WebPhone component. The entry sets window.__OPBX_EMBED_BUS__; WebPhone drives
// commands and emits events through it. The SPA never sets it, so WebPhone's
// embed effect is a no-op there.

export type EmbedCommandName = 'dial' | 'hangup' | 'open' | 'close';
export type EmbedEventName =
  | 'ready'
  | 'call.started'
  | 'call.ended'
  | 'call.failed'
  | 'close.rejected';

export interface EmbedBus {
  // WebPhone registers one handler for host->widget commands.
  onCommand: (handler: (name: EmbedCommandName, args: unknown[]) => void) => void;
  // WebPhone emits widget->host events.
  emit: (name: EmbedEventName, payload?: unknown) => void;
}

declare global {
  interface Window {
    __OPBX_EMBED_BUS__?: EmbedBus;
  }
}

export function getEmbedBus(): EmbedBus | undefined {
  return typeof window !== 'undefined' ? window.__OPBX_EMBED_BUS__ : undefined;
}

/**
 * Host `close` command: ignored during an active call (the panel cannot be
 * collapsed mid-call); the host is told why via `close.rejected`.
 */
export function handleEmbedClose(
  inActiveCall: boolean,
  bus: Pick<EmbedBus, 'emit'> | undefined,
  closePanel: () => void,
): void {
  if (inActiveCall) {
    bus?.emit('close.rejected', { reason: 'call_in_progress' });
    return;
  }
  closePanel();
}
