import { act, fireEvent, render, screen, waitFor } from '@testing-library/react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import type { WebPhoneConfig } from '@/types/webPhone.types';

// Tone player stand-in: records play/stop so the ringer can be checked.
const tones = vi.hoisted(() => ({ play: vi.fn(), stop: vi.fn(), playDtmf: vi.fn() }));
vi.mock('@/lib/TonePlayer', () => ({
  TonePlayer: class {
    play = tones.play;
    stop = tones.stop;
    playDtmf = tones.playDtmf;
  },
}));

import WebPhone from '../WebPhone';

type Handler = (event?: any) => void;
const emitter = () => {
  const handlers: Record<string, Handler[]> = {};
  const on = (name: string, fn: Handler) => { (handlers[name] ??= []).push(fn); };
  return {
    on,
    once: (name: string, fn: Handler) => {
      const wrapped: Handler = (event) => {
        handlers[name] = handlers[name].filter((h) => h !== wrapped);
        fn(event);
      };
      on(name, wrapped);
    },
    emit: (name: string, event?: any) => [...(handlers[name] ?? [])].forEach((fn) => fn(event)),
  };
};

// Minimal JsSIP: a UA we can drive, and sessions that record answer/terminate.
type FakeUA = ReturnType<typeof emitter> & {
  start: () => void; stop: () => void; call: () => any;
  isConnected: () => boolean; isRegistered: () => boolean;
  register: ReturnType<typeof vi.fn>; unregister: ReturnType<typeof vi.fn>;
};
let ua: FakeUA;
let uaCount = 0;
const makeSession = (user: string, displayName = '') => {
  const e = emitter();
  let ended = false;
  const session = {
    ...e,
    remote_identity: { display_name: displayName, uri: { user, toString: () => `sip:${user}@pbx.test` } },
    isEnded: () => ended,
    answer: vi.fn(),
    terminate: vi.fn(() => { ended = true; e.emit('failed', { cause: 'Rejected' }); }),
    hangUp: () => { ended = true; e.emit('failed', { cause: 'Canceled' }); }, // caller gives up before answer
  };
  return session;
};

const config: WebPhoneConfig = {
  sip_username: '1001', sip_password: 'x', sip_domain: 'pbx.test', sip_uri: 'sip:1001@pbx.test',
  display_name: 'Me', wss_server: 'wss://pbx.test', websocket_port: 443, server_path: '/', sip_contact: '1001',
  profile_name: 'p', registration_mode: 'r', country: 'us',
};

async function renderRegisteredPhone() {
  render(
    <QueryClientProvider client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}>
      <WebPhone
        configQueryFn={() => Promise.resolve({ data: config } as any)}
        callsLogQueryFn={() => Promise.resolve({ data: [] } as any)}
      />
    </QueryClientProvider>,
  );
  await waitFor(() => expect(ua).toBeDefined());
  act(() => ua.emit('registered'));
}

const ring = (session: ReturnType<typeof makeSession>) => act(() => ua.emit('newRTCSession', { originator: 'remote', session }));

beforeEach(() => {
  tones.play.mockClear();
  tones.stop.mockClear();
  ua = undefined as any;
  uaCount = 0;
  (window as any).JsSIP = {
    WebSocketInterface: class {},
    UA: class {
      constructor() {
        uaCount += 1;
        ua = Object.assign(emitter(), {
          start: () => {}, stop: () => {}, call: () => makeSession('x'),
          isConnected: () => true, isRegistered: () => true, register: vi.fn(), unregister: vi.fn(),
        });
        return ua as any;
      }
    },
  };
});

afterEach(() => {
  delete (window as any).JsSIP;
});

describe('WebPhone inbound calls', () => {
  it('rings with the caller shown, and answering stops the ringer', async () => {
    await renderRegisteredPhone();
    const call = makeSession('+15551234567', 'Alice');
    ring(call);

    expect(await screen.findByRole('button', { name: 'Answer' })).toBeInTheDocument();
    expect(screen.getAllByText('Incoming call').length).toBeGreaterThan(0);
    expect(screen.getByText('Alice (+15551234567)')).toBeInTheDocument();
    expect(tones.play).toHaveBeenCalledWith('ring', 'us');

    tones.stop.mockClear();
    fireEvent.click(screen.getByRole('button', { name: 'Answer' }));
    expect(tones.stop).toHaveBeenCalled();
    expect(call.answer).toHaveBeenCalledWith({ mediaConstraints: { audio: true, video: false } });
    expect(screen.queryByRole('button', { name: 'Answer' })).not.toBeInTheDocument();

    // Connected: caller ID first, then the name in the larger font.
    act(() => call.emit('confirmed'));
    const id = await screen.findByText('+15551234567');
    const name = screen.getByText('Alice');
    expect(id.compareDocumentPosition(name) & Node.DOCUMENT_POSITION_FOLLOWING).toBeTruthy();
    expect(name.className).toContain('text-2xl');
  });

  it('shows the caller ID alone, in the larger font, when the call carries no name', async () => {
    await renderRegisteredPhone();
    const call = makeSession('1002');
    ring(call);
    fireEvent.click(await screen.findByRole('button', { name: 'Answer' }));
    act(() => call.emit('confirmed'));

    expect((await screen.findByText('1002')).className).toContain('text-4xl');
  });

  it('stops ringing when the caller hangs up before it is answered', async () => {
    await renderRegisteredPhone();
    const call = makeSession('1002');
    ring(call);
    await screen.findByRole('button', { name: 'Answer' });

    tones.stop.mockClear();
    act(() => call.hangUp());
    expect(tones.stop).toHaveBeenCalled();
    await waitFor(() => expect(screen.queryByRole('button', { name: 'Answer' })).not.toBeInTheDocument());
  });

  it('declining stops the ringer and rejects the call', async () => {
    await renderRegisteredPhone();
    const call = makeSession('1003');
    ring(call);
    await screen.findByRole('button', { name: 'Answer' });

    fireEvent.click(screen.getByRole('button', { name: 'Reject' }));
    expect(tones.stop).toHaveBeenCalled();
    expect(call.terminate).toHaveBeenCalled();
    await waitFor(() => expect(screen.queryByRole('button', { name: 'Answer' })).not.toBeInTheDocument());
  });

  it('answers a second call busy (486) while one is already ringing, leaving the first untouched', async () => {
    await renderRegisteredPhone();
    const first = makeSession('1004');
    ring(first);
    await screen.findByRole('button', { name: 'Answer' });

    tones.play.mockClear();
    const second = makeSession('1005');
    ring(second);

    expect(second.terminate).toHaveBeenCalledWith({ status_code: 486, reason_phrase: 'Busy Here' });
    expect(first.terminate).not.toHaveBeenCalled();
    expect(tones.play).not.toHaveBeenCalled();
    expect(screen.getByText('1004')).toBeInTheDocument(); // still showing (and ringing for) the first caller
  });
});

describe('WebPhone registration status', () => {
  const openPanel = () => fireEvent.click(screen.getByRole('button', { name: 'Open Web Phone' }));

  it('badge follows the real registration, including a dropped binding and a lost socket', async () => {
    await renderRegisteredPhone();
    openPanel();
    expect(screen.getByRole('status')).toHaveTextContent('Registered');

    act(() => ua.emit('unregistered'));
    expect(screen.getByRole('status')).toHaveTextContent('Not registered');

    act(() => ua.emit('disconnected'));
    expect(screen.getByRole('status')).toHaveTextContent('Disconnected');

    act(() => ua.emit('connecting'));
    expect(screen.getByRole('status')).toHaveTextContent('Registering…');
    act(() => ua.emit('registered'));
    expect(screen.getByRole('status')).toHaveTextContent('Registered');
  });

  it('does not claim Registered after a call ends while the registration is gone', async () => {
    await renderRegisteredPhone();
    const call = makeSession('1006');
    ring(call);
    await screen.findByRole('button', { name: 'Answer' });

    act(() => ua.emit('unregistered'));
    act(() => call.hangUp());

    await waitFor(() => expect(screen.queryByRole('button', { name: 'Answer' })).not.toBeInTheDocument());
    expect(screen.getByRole('status')).toHaveTextContent('Not registered');
    expect(screen.queryByText('Registered')).not.toBeInTheDocument();
  });

  it('Re-register drops the binding, then registers afresh once it is gone', async () => {
    await renderRegisteredPhone();
    openPanel();

    fireEvent.click(screen.getByRole('button', { name: 'Re-register' }));
    expect(ua.unregister).toHaveBeenCalled();
    expect(ua.register).not.toHaveBeenCalled(); // waits for the un-REGISTER to settle
    expect(screen.getByRole('status')).toHaveTextContent('Registering…');
    expect(screen.getByRole('button', { name: 'Re-register' })).toBeDisabled();

    act(() => ua.emit('unregistered'));
    expect(ua.register).toHaveBeenCalledTimes(1);
    expect(screen.getByRole('status')).toHaveTextContent('Registering…');

    act(() => ua.emit('registered'));
    expect(screen.getByRole('status')).toHaveTextContent('Registered');
  });

  it('Re-register rebuilds the phone when the connection is gone', async () => {
    await renderRegisteredPhone();
    openPanel();
    ua.isConnected = () => false;

    fireEvent.click(screen.getByRole('button', { name: 'Re-register' }));
    await waitFor(() => expect(uaCount).toBe(2));
  });

  it('Re-register is disabled during a call', async () => {
    await renderRegisteredPhone();
    ring(makeSession('1007'));
    await screen.findByRole('button', { name: 'Answer' });
    expect(screen.getByRole('button', { name: 'Re-register' })).toBeDisabled();
  });
});
