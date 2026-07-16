import { useEffect, useRef, useState, useCallback } from 'react';
import { useQuery } from '@tanstack/react-query';
import {
  Phone,
  Loader2,
  AlertTriangle,
  RefreshCw,
  Mic,
  MicOff,
  Pause,
  Play,
  PhoneOff,
  PhoneCall,
  Delete,
  X,
  Volume1,
  Volume2,
  VolumeX,
} from 'lucide-react';
import { Button } from '@/components/ui/button';
import {
  getWebPhoneConfig,
  type WebPhoneConfigResponse,
  type WebPhoneCallsLogResponse,
} from '@/services/webPhone.service';
import { loadJsSipScript } from './loadJsSipScript';
import { subscribeCoach } from './webPhoneBus';
import { getEmbedBus } from './embedBus';
import { WebPhoneTabs, type WebPhoneTab } from './WebPhoneTabs';
import { CallsLogView } from './CallsLogView';
import { TonePlayer } from '@/lib/TonePlayer';
import type { WebPhoneConfig } from '@/types/webPhone.types';

type PhoneLifecycle = 'loading' | 'ready' | 'no_extension' | 'error';
type CallState = 'idle' | 'ringing' | 'connected';

type IconPosition = 'bottom-right' | 'bottom-left' | 'top-right' | 'top-left';

interface WebPhoneProps {
  // Config/calls-log sources; embed overrides these to hit /embed/*.
  configQueryFn?: () => Promise<WebPhoneConfigResponse>;
  callsLogQueryFn?: () => Promise<WebPhoneCallsLogResponse>;
  // Pull-tab placement + color (embed is themable per token).
  iconPosition?: IconPosition;
  iconBackgroundColor?: string;
  // Embed may open the dialer immediately.
  autoOpen?: boolean;
}

// Map the 4 corners to fixed-position Tailwind classes for the pull tab + panel.
const CORNER_CLASSES: Record<IconPosition, string> = {
  'bottom-right': 'bottom-6 right-6',
  'bottom-left': 'bottom-6 left-6',
  'top-right': 'top-6 right-6',
  'top-left': 'top-6 left-6',
};

const DIAL_KEYS = ['1', '2', '3', '4', '5', '6', '7', '8', '9', '*', '0', '#'];

function coachLabelFor(destination: string): string {
  if (destination.startsWith('spy_')) return 'Spy';
  if (destination.startsWith('barge_')) return 'Barge';
  if (destination.startsWith('whisper_')) return 'Whisper';
  return 'Coaching';
}

interface ErrorDetail {
  title: string;
  message: string;
  hint: string;
  cause?: string;
}

// Turn a raw JsSIP registration cause into something a human can act on.
// Cause values come from JsSIP.C.causes (e.g. "Request Timeout", "Connection Error").
function explainRegistrationFailure(cause: string): ErrorDetail {
  const c = cause.toLowerCase();

  if (c.includes('timeout')) {
    return {
      title: 'Web Phone Error',
      message: 'The registration request reached the voice server but no reply came back in time.',
      hint: 'This is usually a temporary network or upstream issue — retry in a moment. If it keeps happening, the Cloudonix WebRTC service may be unreachable.',
      cause,
    };
  }
  if (c.includes('connection') || c.includes('websocket') || c.includes('transport')) {
    return {
      title: 'Connection Failed',
      message: 'Could not open a connection to the voice server.',
      hint: 'Check your internet connection and that wss://webrtc.cloudonix.io is reachable, then retry.',
      cause,
    };
  }
  if (c.includes('401') || c.includes('403') || c.includes('unauthorized') || c.includes('forbidden') || c.includes('authentication')) {
    return {
      title: 'Authentication Rejected',
      message: 'The voice server rejected your extension credentials.',
      hint: 'Your extension password may be out of sync with Cloudonix. Contact your administrator.',
      cause,
    };
  }
  if (c.includes('404') || c.includes('not found')) {
    return {
      title: 'Extension Not Found',
      message: 'The voice server does not recognize this extension.',
      hint: 'The subscriber may not be provisioned in Cloudonix. Contact your administrator.',
      cause,
    };
  }
  return {
    title: 'Web Phone Error',
    message: 'Registration with the voice server failed.',
    hint: 'Retry, and if the problem persists contact your administrator.',
    cause,
  };
}

// Scale the number-display font so at least 14 digits fit without clipping.
function numberSizeClass(len: number): string {
  if (len <= 10) return 'text-4xl tracking-widest';
  if (len <= 14) return 'text-3xl tracking-wide';
  if (len <= 18) return 'text-2xl tracking-normal';
  return 'text-xl tracking-tight';
}

// Format elapsed call time: "M:SS" under an hour, "H:MM:SS" from an hour on.
export function formatCallDuration(totalSeconds: number): string {
  const s = Math.max(0, Math.floor(totalSeconds));
  const seconds = s % 60;
  const minutes = Math.floor(s / 60) % 60;
  const hours = Math.floor(s / 3600);
  const ss = String(seconds).padStart(2, '0');
  if (hours > 0) {
    return `${hours}:${String(minutes).padStart(2, '0')}:${ss}`;
  }
  return `${minutes}:${ss}`;
}

export function WebPhone({
  configQueryFn,
  callsLogQueryFn,
  iconPosition = 'bottom-right',
  iconBackgroundColor,
  autoOpen = false,
}: WebPhoneProps = {}) {
  const audioRef = useRef<HTMLAudioElement | null>(null);
  const uaRef = useRef<any>(null);
  const sessionRef = useRef<any>(null);
  const tonePlayerRef = useRef<TonePlayer | null>(null);

  const cornerClass = CORNER_CLASSES[iconPosition];

  const [open, setOpen] = useState(autoOpen);
  const [state, setState] = useState<PhoneLifecycle>('loading');
  const [status, setStatus] = useState('Initializing...');
  const [errorDetail, setErrorDetail] = useState<ErrorDetail | null>(null);
  const [number, setNumber] = useState('');
  const [callState, setCallState] = useState<CallState>('idle');
  const [isMuted, setIsMuted] = useState(false);
  const [isHeld, setIsHeld] = useState(false);
  const [volume, setVolume] = useState(1);
  const [incomingSession, setIncomingSession] = useState<any>(null);
  const [incomingNumber, setIncomingNumber] = useState('');
  const pendingCoachRef = useRef<string | null>(null);
  const isCoachCallRef = useRef(false);
  const [coachLabel, setCoachLabel] = useState<string | null>(null);
  const [activeTab, setActiveTab] = useState<WebPhoneTab>('dialer');
  const pendingRedialRef = useRef<string | null>(null);

  // Elapsed call time (seconds), ticking from connect to disconnect.
  const [callSeconds, setCallSeconds] = useState(0);
  const callStartRef = useRef<number | null>(null);
  const timerIntervalRef = useRef<ReturnType<typeof setInterval> | null>(null);

  const stopCallTimer = useCallback(() => {
    if (timerIntervalRef.current !== null) {
      clearInterval(timerIntervalRef.current);
      timerIntervalRef.current = null;
    }
    callStartRef.current = null;
  }, []);

  const startCallTimer = useCallback(() => {
    stopCallTimer();
    callStartRef.current = Date.now();
    setCallSeconds(0);
    // Derive from the start timestamp so it stays accurate if the tab throttles the interval.
    timerIntervalRef.current = setInterval(() => {
      if (callStartRef.current !== null) {
        setCallSeconds(Math.floor((Date.now() - callStartRef.current) / 1000));
      }
    }, 1000);
  }, [stopCallTimer]);

  // Clear the interval if the component unmounts mid-call.
  useEffect(() => stopCallTimer, [stopCallTimer]);

  // Active call = mid-call OR an incoming ringing session. While active the
  // dialer cannot be collapsed and the rest of the UI is blocked.
  const inActiveCall = callState !== 'idle' || incomingSession !== null;

  // During a coaching call the header names the coaching function, e.g. "On call - Coaching: Spy".
  const headerStatus =
    coachLabel && callState === 'connected' ? `${status} - Coaching: ${coachLabel}` : status;

  const { data: configResponse, error: configError, refetch } = useQuery({
    queryKey: ['webphone-config'],
    queryFn: configQueryFn ?? getWebPhoneConfig,
    enabled: open,
    retry: false,
    staleTime: Infinity,
  });

  const config = configResponse?.data as WebPhoneConfig | undefined;

  const resetCallState = useCallback(() => {
    setCallState('idle');
    setIsMuted(false);
    setIsHeld(false);
    setIncomingSession(null);
    setIncomingNumber('');
    setCoachLabel(null);
    isCoachCallRef.current = false;
    stopCallTimer();
  }, [stopCallTimer]);

  // Apply the volume slider to the remote audio element.
  useEffect(() => {
    if (audioRef.current) {
      audioRef.current.volume = volume;
    }
  }, [volume]);

  // Live Calls -> Web Phone: open and queue a coach auto-dial.
  useEffect(() => {
    return subscribeCoach((destination) => {
      pendingCoachRef.current = destination;
      isCoachCallRef.current = true;
      setCoachLabel(coachLabelFor(destination));
      setNumber(destination);
      setOpen(true);
    });
  }, []);

  const getTonePlayer = useCallback(() => {
    if (!tonePlayerRef.current) {
      tonePlayerRef.current = new TonePlayer();
    }
    return tonePlayerRef.current;
  }, []);

  const attachRemoteStream = useCallback((session: any) => {
    const connection = session?.connection;
    if (!connection || !audioRef.current) return;

    const stream = new MediaStream();
    connection.getReceivers().forEach((receiver: any) => {
      if (receiver.track) {
        stream.addTrack(receiver.track);
      }
    });

    if (stream.getTracks().length > 0) {
      audioRef.current.srcObject = stream;
    }
  }, []);

  const handleSessionEvents = useCallback(
    (session: any) => {
      const country = config?.country ?? 'us';

      session.on('confirmed', () => {
        getTonePlayer().stop();
        setCallState('connected');
        setStatus('On call');
        startCallTimer();
        attachRemoteStream(session);
        getEmbedBus()?.emit('call.started');
      });

      session.on('ended', () => {
        getTonePlayer().stop();
        getEmbedBus()?.emit('call.ended');
        stopCallTimer();
        if (isCoachCallRef.current) {
          isCoachCallRef.current = false;
          setOpen(false); // Coaching finished: close the Web Phone entirely.
          return;
        }
        resetCallState();
        setStatus('Registered');
      });

      session.on('failed', (event: any) => {
        getTonePlayer().stop();
        getEmbedBus()?.emit('call.failed', { cause: event?.cause });
        stopCallTimer();

        const cause = (event?.cause ?? '').toString().toLowerCase();
        if (cause === 'busy') {
          getTonePlayer().play('busy', country);
        } else if (['congestion', 'unavailable', 'request_timeout', 'timeout'].some((c) => cause.includes(c))) {
          getTonePlayer().play('congestion', country);
        }

        if (isCoachCallRef.current) {
          isCoachCallRef.current = false;
          setOpen(false); // Coaching attempt ended/failed: close the Web Phone entirely.
          return;
        }

        resetCallState();
        setStatus('Registered');
      });

      session.on('hold', () => setIsHeld(true));
      session.on('unhold', () => setIsHeld(false));
      session.on('muted', () => setIsMuted(true));
      session.on('unmuted', () => setIsMuted(false));
    },
    [attachRemoteStream, config, getTonePlayer, resetCallState, startCallTimer, stopCallTimer]
  );

  useEffect(() => {
    if (!open) {
      setState('loading');
      resetCallState();
      setStatus('Initializing...');
      setErrorDetail(null);
      getTonePlayer().stop();
      if (uaRef.current) {
        try {
          uaRef.current.stop();
        } catch (error) {
          console.error('[WebPhone] Error stopping UA:', error);
        }
        uaRef.current = null;
      }
      return;
    }

    if (configError) {
      const status = (configError as any)?.response?.status;
      if (status === 404) {
        setState('no_extension');
      } else {
        setErrorDetail({
          title: 'Web Phone Unavailable',
          message: 'Could not load the Web Phone configuration from the server.',
          hint: 'Retry, and if the problem persists contact your administrator.',
        });
        setState('error');
      }
      return;
    }

    if (!config) {
      setState('loading');
      return;
    }

    let cancelled = false;

    async function initPhone() {
      try {
        await loadJsSipScript();
        if (cancelled) return;

        const JsSIP = (window as any).JsSIP;
        if (!JsSIP) {
          setErrorDetail({
            title: 'Web Phone Error',
            message: 'The Web Phone engine failed to load.',
            hint: 'Reload the page and retry. If it persists, check your network or contact your administrator.',
          });
          setState('error');
          setStatus('Failed to load the Web Phone.');
          return;
        }

        const socket = new JsSIP.WebSocketInterface(
          `${config!.wss_server}:${config!.websocket_port}`
        );

        const ua = new JsSIP.UA({
          sockets: [socket],
          uri: config!.sip_uri,
          password: config!.sip_password,
          display_name: config!.display_name,
          register: true,
          register_expires: 600,
          session_timers: false,
        });

        ua.on('connecting', () => {
          setStatus('Connecting...');
        });

        ua.on('connected', () => {
          setStatus('Connected, registering...');
        });

        ua.on('disconnected', () => {
          setStatus('Disconnected');
        });

        ua.on('registered', () => {
          setStatus('Registered');
          setState('ready');
        });

        ua.on('registrationFailed', (event: any) => {
          const cause = event?.cause || 'unknown';
          setStatus(`Registration failed: ${cause}`);
          setErrorDetail(explainRegistrationFailure(cause));
          setState('error');
        });

        ua.on('newRTCSession', (event: any) => {
          const session = event.session;
          handleSessionEvents(session);

          if (event.originator === 'remote') {
            const remoteUri = session.remote_identity?.uri?.toString() || 'Unknown';
            setIncomingNumber(remoteUri);
            setIncomingSession(session);
            setStatus('Incoming call');
          } else {
            setCallState('ringing');
            setStatus('Calling...');
          }
        });

        ua.start();
        uaRef.current = ua;
      } catch (error: any) {
        console.error('[WebPhone] Initialization failed:', error);
        setStatus('The Web Phone could not register.');
        setErrorDetail({
          title: 'Web Phone Error',
          message: 'The Web Phone could not start.',
          hint: 'Reload the page and retry. If it persists, contact your administrator.',
          cause: error?.message,
        });
        setState('error');
      }
    }

    initPhone();

    return () => {
      cancelled = true;
      getTonePlayer().stop();
      if (uaRef.current) {
        try {
          uaRef.current.stop();
        } catch (error) {
          console.error('[WebPhone] Error stopping UA:', error);
        }
        uaRef.current = null;
      }
    };
  }, [open, config, configError, handleSessionEvents, resetCallState, getTonePlayer]);

  const handleCall = useCallback(() => {
    if (!uaRef.current || !number || !config) return;

    const target = number.includes('@')
      ? `sip:${number}`
      : `sip:${number}@${config.sip_domain}`;

    const session = uaRef.current.call(target, {
      mediaConstraints: { audio: true, video: false },
      eventHandlers: {
        connecting: () => setStatus('Calling...'),
        progress: () => {
          setStatus('Ringing...');
          getTonePlayer().play('ring', config.country);
        },
      },
    });

    sessionRef.current = session;
    handleSessionEvents(session);
  }, [number, config, handleSessionEvents, getTonePlayer]);

  // When a coach destination is queued and the UA is registered, place the call.
  useEffect(() => {
    if (state === 'ready' && callState === 'idle' && pendingCoachRef.current) {
      pendingCoachRef.current = null;
      handleCall();
    }
  }, [state, callState, handleCall]);

  // Redial from the calls log: number is set via setNumber, then this effect
  // places the call once the number state has flushed and the UA is idle.
  // Mirrors the coach queue pattern rather than calling handleCall synchronously.
  useEffect(() => {
    if (
      state === 'ready' &&
      callState === 'idle' &&
      pendingRedialRef.current &&
      number === pendingRedialRef.current
    ) {
      pendingRedialRef.current = null;
      handleCall();
    }
  }, [state, callState, number, handleCall]);

  const handleHangup = useCallback(() => {
    if (sessionRef.current) {
      try {
        sessionRef.current.terminate();
      } catch (error) {
        console.error('[WebPhone] Hangup failed:', error);
      }
    }
    if (incomingSession) {
      try {
        incomingSession.terminate();
      } catch (error) {
        console.error('[WebPhone] Reject failed:', error);
      }
      setIncomingSession(null);
      setIncomingNumber('');
    }
  }, [incomingSession]);

  // Embed command bus: host page -> widget. Reuses the redial queue so a `dial`
  // command auto-places once the UA is registered. No-op in the SPA (no bus).
  useEffect(() => {
    const bus = getEmbedBus();
    if (!bus) return;

    bus.onCommand((name, args) => {
      switch (name) {
        case 'dial': {
          const destination = String(args?.[0] ?? '').trim();
          if (!destination) return;
          setCoachLabel(null);
          isCoachCallRef.current = false;
          pendingRedialRef.current = destination;
          setNumber(destination);
          setActiveTab('dialer');
          setOpen(true);
          break;
        }
        case 'hangup':
          handleHangup();
          break;
        case 'open':
          setOpen(true);
          break;
        case 'close':
          setOpen(false);
          break;
      }
    });
  }, [handleHangup]);

  // Tell the host the widget is registered and ready to take commands.
  useEffect(() => {
    if (state === 'ready') {
      getEmbedBus()?.emit('ready');
    }
  }, [state]);

  const handleAnswer = useCallback(() => {
    if (incomingSession) {
      try {
        incomingSession.answer({
          mediaConstraints: { audio: true, video: false },
        });
        sessionRef.current = incomingSession;
        setIncomingSession(null);
        setIncomingNumber('');
      } catch (error) {
        console.error('[WebPhone] Answer failed:', error);
      }
    }
  }, [incomingSession]);

  // Tap-to-redial from the calls log: switch to the dialer, prefill the number,
  // and queue an auto-dial (the redial effect fires once the number flushes).
  const handleRedial = useCallback((destination: string) => {
    setCoachLabel(null);
    isCoachCallRef.current = false;
    pendingRedialRef.current = destination;
    setNumber(destination);
    setActiveTab('dialer');
  }, []);

  const handleReject = useCallback(() => {
    if (incomingSession) {
      try {
        incomingSession.terminate();
      } catch (error) {
        console.error('[WebPhone] Reject failed:', error);
      }
      setIncomingSession(null);
      setIncomingNumber('');
      setStatus('Registered');
    }
  }, [incomingSession]);

  const handleMute = useCallback(() => {
    if (sessionRef.current) {
      try {
        if (isMuted) {
          sessionRef.current.unmute({ audio: true });
        } else {
          sessionRef.current.mute({ audio: true });
        }
      } catch (error) {
        console.error('[WebPhone] Mute toggle failed:', error);
      }
    }
  }, [isMuted]);

  const handleHold = useCallback(() => {
    if (sessionRef.current) {
      try {
        if (isHeld) {
          sessionRef.current.unhold();
        } else {
          sessionRef.current.hold();
        }
      } catch (error) {
        console.error('[WebPhone] Hold toggle failed:', error);
      }
    }
  }, [isHeld]);

  const appendDigit = useCallback((digit: string) => {
    setNumber((prev) => prev + digit);
  }, []);

  const backspace = useCallback(() => {
    setNumber((prev) => prev.slice(0, -1));
  }, []);

  const handleCollapse = useCallback(() => {
    // ponytail: guard against collapsing mid-call; call ladder is the only exit.
    if (inActiveCall) return;
    setOpen(false);
  }, [inActiveCall]);

  // Retry from the error screen. Config errors need a refetch; registration
  // failures need a full UA re-init, so close+reopen to re-run the init effect.
  const handleRetry = useCallback(() => {
    setErrorDetail(null);
    setStatus('Initializing...');
    setState('loading');
    if (configError) {
      refetch();
      return;
    }
    setOpen(false);
    setTimeout(() => setOpen(true), 0);
  }, [configError, refetch]);

  return (
    <>
      {/* Right-edge pull tab — hidden while the dialer is open */}
      {!open && (
        <button
          type="button"
          onClick={() => setOpen(true)}
          className={`fixed ${cornerClass} z-40 flex h-16 w-16 items-center justify-center rounded-full rounded-br-none bg-primary text-primary-foreground shadow-lg hover:scale-105 hover:shadow-xl transition-all`}
          style={iconBackgroundColor ? { backgroundColor: iconBackgroundColor } : undefined}
          aria-label="Open Web Phone"
          title="Web Phone"
        >
          <Phone className="h-7 w-7" />
        </button>
      )}

      {/* Backdrop — only during an active call, blocks the rest of the UI */}
      {open && inActiveCall && (
        <div
          className="fixed inset-0 z-40 bg-black/40 backdrop-blur-[1px]"
          aria-hidden="true"
        />
      )}

      {/* Bottom-right floating dialer */}
      {open && (
        <div className={`fixed ${cornerClass} z-50 w-[360px] max-w-[calc(100vw-2rem)] rounded-2xl border bg-background shadow-2xl overflow-hidden flex flex-col max-h-[calc(100vh-4rem)]`}>
            {/* Header */}
            <div className="flex items-center justify-between border-b px-5 py-3">
              <div className="min-w-0">
                <div className="flex items-center gap-2 font-semibold">
                  <Phone className="h-4 w-4" />
                  Web Phone
                </div>
                <p className="truncate text-xs text-muted-foreground">{headerStatus}</p>
              </div>
              <button
                type="button"
                onClick={handleCollapse}
                disabled={inActiveCall}
                className="rounded-full p-1.5 text-muted-foreground hover:bg-muted disabled:opacity-30 disabled:cursor-not-allowed"
                aria-label="Close Web Phone"
                title={inActiveCall ? 'End the call before closing' : 'Close'}
              >
                <X className="h-4 w-4" />
              </button>
            </div>

            {/* ponytail: fixed height pinned to the dial-pad view (tallest view) so the panel never
                resizes between views; re-measure if the keypad layout changes. */}
            <div className="flex flex-col p-6 gap-4 h-[556px] overflow-y-auto">
              {state === 'loading' && (
                <div className="flex flex-col items-center justify-center gap-3 py-12">
                  <Loader2 className="h-8 w-8 animate-spin text-muted-foreground" />
                  <p className="text-sm text-muted-foreground">Initializing Web Phone...</p>
                </div>
              )}

              {(state === 'no_extension' || state === 'error') && (
                <div className="flex flex-col items-center justify-center gap-4 py-10 text-center">
                  <AlertTriangle className="h-10 w-10 text-destructive" />
                  <div className="space-y-2">
                    <h4 className="font-semibold">
                      {state === 'no_extension'
                        ? 'Web Phone Unavailable'
                        : errorDetail?.title ?? 'Web Phone Error'}
                    </h4>
                    <p className="text-sm text-muted-foreground">
                      {state === 'no_extension'
                        ? 'No extension is assigned to your account. Web Phone cannot be used.'
                        : errorDetail?.message ?? status}
                    </p>
                    {state === 'error' && errorDetail?.hint && (
                      <p className="text-xs text-muted-foreground/80">{errorDetail.hint}</p>
                    )}
                  </div>

                  {state === 'error' && errorDetail?.cause && (
                    <p className="rounded-md bg-muted px-2.5 py-1 font-mono text-[11px] text-muted-foreground">
                      {errorDetail.cause}
                    </p>
                  )}

                  {state === 'error' && (
                    <Button variant="outline" size="sm" onClick={handleRetry}>
                      <RefreshCw className="h-4 w-4 mr-2" />
                      Retry
                    </Button>
                  )}
                </div>
              )}

              {state === 'ready' && (
                <>
                  {incomingSession ? (
                    <div className="flex flex-col items-center justify-center gap-8 py-6">
                      <div className="text-center space-y-2">
                        <p className="text-lg font-semibold">Incoming call</p>
                        <p className="text-sm text-muted-foreground break-all px-4">{incomingNumber}</p>
                      </div>
                      <div className="flex items-center justify-center gap-10">
                        <button
                          type="button"
                          onClick={handleAnswer}
                          className="h-[72px] w-[72px] rounded-full bg-green-500 text-white shadow-lg shadow-green-500/30 hover:bg-green-600 active:scale-95 transition-all flex items-center justify-center"
                          aria-label="Answer"
                        >
                          <PhoneCall className="h-8 w-8" />
                        </button>
                        <button
                          type="button"
                          onClick={handleReject}
                          className="h-[72px] w-[72px] rounded-full bg-red-500 text-white shadow-lg shadow-red-500/30 hover:bg-red-600 active:scale-95 transition-all flex items-center justify-center"
                          aria-label="Reject"
                        >
                          <PhoneOff className="h-8 w-8" />
                        </button>
                      </div>
                    </div>
                  ) : callState === 'connected' ? (
                    <div className="flex flex-col items-center justify-center gap-10 py-6">
                      <div className="text-center space-y-2">
                        {coachLabel ? (
                          // Coaching: show only the function name, never the raw sentinel destination.
                          <>
                            <p className={`font-medium px-4 ${numberSizeClass(coachLabel.length)}`}>
                              {coachLabel}
                            </p>
                            <p className="text-sm text-muted-foreground tabular-nums">
                              {formatCallDuration(callSeconds)}
                            </p>
                          </>
                        ) : (
                          <>
                            <p className="text-sm text-muted-foreground tabular-nums">
                              {formatCallDuration(callSeconds)}
                            </p>
                            <p className={`font-medium break-all px-4 ${numberSizeClass(number.length)}`}>
                              {number || ' '}
                            </p>
                          </>
                        )}
                      </div>
                      <div className="flex items-center justify-center gap-6">
                        <button
                          type="button"
                          onClick={handleMute}
                          className={`h-[72px] w-[72px] rounded-full shadow-lg active:scale-95 transition-all flex flex-col items-center justify-center gap-1 ${
                            isMuted
                              ? 'bg-primary text-primary-foreground'
                              : 'bg-gray-100 text-gray-900 hover:bg-gray-200'
                          }`}
                          aria-label={isMuted ? 'Unmute' : 'Mute'}
                        >
                          {isMuted ? <MicOff className="h-6 w-6" /> : <Mic className="h-6 w-6" />}
                          <span className="text-[10px] font-medium">{isMuted ? 'Unmute' : 'Mute'}</span>
                        </button>
                        <button
                          type="button"
                          onClick={handleHangup}
                          className="h-[72px] w-[72px] rounded-full bg-red-500 text-white shadow-lg shadow-red-500/30 hover:bg-red-600 active:scale-95 transition-all flex items-center justify-center"
                          aria-label="Hangup"
                        >
                          <PhoneOff className="h-8 w-8" />
                        </button>
                        <button
                          type="button"
                          onClick={handleHold}
                          className={`h-[72px] w-[72px] rounded-full shadow-lg active:scale-95 transition-all flex flex-col items-center justify-center gap-1 ${
                            isHeld
                              ? 'bg-primary text-primary-foreground'
                              : 'bg-gray-100 text-gray-900 hover:bg-gray-200'
                          }`}
                          aria-label={isHeld ? 'Resume' : 'Hold'}
                        >
                          {isHeld ? <Play className="h-6 w-6" /> : <Pause className="h-6 w-6" />}
                          <span className="text-[10px] font-medium">{isHeld ? 'Resume' : 'Hold'}</span>
                        </button>
                      </div>

                      {/* Volume control */}
                      <div className="flex w-full max-w-[260px] items-center gap-3 px-2">
                        <button
                          type="button"
                          onClick={() => setVolume((v) => (v === 0 ? 1 : 0))}
                          className="text-muted-foreground hover:text-foreground shrink-0"
                          aria-label={volume === 0 ? 'Unmute speaker' : 'Mute speaker'}
                        >
                          {volume === 0 ? (
                            <VolumeX className="h-5 w-5" />
                          ) : volume < 0.5 ? (
                            <Volume1 className="h-5 w-5" />
                          ) : (
                            <Volume2 className="h-5 w-5" />
                          )}
                        </button>
                        <input
                          type="range"
                          min={0}
                          max={1}
                          step={0.01}
                          value={volume}
                          onChange={(e) => setVolume(Number(e.target.value))}
                          className="h-1.5 w-full cursor-pointer accent-primary"
                          aria-label="Call volume"
                        />
                      </div>
                    </div>
                  ) : activeTab === 'calls' ? (
                    <CallsLogView
                      onRedial={handleRedial}
                      active={open && activeTab === 'calls'}
                      callsLogQueryFn={callsLogQueryFn}
                    />
                  ) : (
                    <div className="flex flex-col items-center gap-6">
                      {/* Number display */}
                      <div className="flex flex-col items-center justify-center w-full gap-3">
                        <p className="text-sm text-muted-foreground">{status}</p>
                        {coachLabel ? (
                          // Coaching dial-out: show the function name, never the raw sentinel destination.
                          <div className="flex items-center justify-center min-h-[3.5rem] px-4">
                            <p className={`font-medium text-foreground ${numberSizeClass(coachLabel.length)}`}>
                              {coachLabel}
                            </p>
                          </div>
                        ) : (
                          <div className="flex items-center justify-center gap-2 min-h-[3.5rem] px-4">
                            <input
                              type="text"
                              value={number}
                              onChange={(e) => setNumber(e.target.value)}
                              className={`w-full max-w-[280px] border-0 bg-transparent text-center font-medium text-foreground focus:outline-none focus-visible:ring-0 caret-transparent ${numberSizeClass(number.length)}`}
                              aria-label="Phone number"
                            />
                            {number && (
                              <button
                                type="button"
                                onClick={backspace}
                                className="h-10 w-10 rounded-full flex items-center justify-center text-muted-foreground hover:bg-gray-100 active:scale-95 transition-all shrink-0"
                                aria-label="Backspace"
                              >
                                <Delete className="h-5 w-5" />
                              </button>
                            )}
                          </div>
                        )}
                      </div>

                      {/* Dial pad */}
                      <div className="grid grid-cols-3 gap-x-6 gap-y-3">
                        {DIAL_KEYS.map((key) => (
                          <button
                            key={key}
                            type="button"
                            onClick={() => appendDigit(key)}
                            className="h-16 w-16 rounded-full bg-gray-100 text-xl font-medium text-gray-900 hover:bg-gray-200 active:scale-95 active:bg-gray-300 transition-all shadow-sm"
                            aria-label={`Dial ${key}`}
                          >
                            {key}
                          </button>
                        ))}
                      </div>

                      {/* Action buttons */}
                      <div className="flex items-center justify-center gap-10 pt-2">
                        <button
                          type="button"
                          onClick={handleCall}
                          disabled={callState !== 'idle' || !number}
                          className="h-[72px] w-[72px] rounded-full bg-green-500 text-white shadow-lg shadow-green-500/30 hover:bg-green-600 active:scale-95 transition-all disabled:opacity-50 disabled:cursor-not-allowed disabled:hover:bg-green-500 flex items-center justify-center"
                          aria-label="Call"
                        >
                          <PhoneCall className="h-8 w-8" />
                        </button>
                        <button
                          type="button"
                          onClick={handleHangup}
                          disabled={callState === 'idle' && !incomingSession}
                          className="h-[72px] w-[72px] rounded-full bg-red-500 text-white shadow-lg shadow-red-500/30 hover:bg-red-600 active:scale-95 transition-all disabled:opacity-50 disabled:cursor-not-allowed disabled:hover:bg-red-500 flex items-center justify-center"
                          aria-label="Hangup"
                        >
                          <PhoneOff className="h-8 w-8" />
                        </button>
                      </div>
                    </div>
                  )}
                </>
              )}

              <audio ref={audioRef} autoPlay playsInline className="hidden" />
            </div>

            {/* Bottom tab bar — locked during an active call */}
            {state === 'ready' && (
              <WebPhoneTabs
                active={activeTab}
                onChange={setActiveTab}
                disabled={inActiveCall}
              />
            )}
        </div>
      )}
    </>
  );
}

export default WebPhone;
