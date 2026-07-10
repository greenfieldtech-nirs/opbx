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
  X,
} from 'lucide-react';
import {
  Sheet,
  SheetContent,
  SheetHeader,
  SheetTitle,
  SheetDescription,
} from '@/components/ui/sheet';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { getWebPhoneConfig } from '@/services/webPhone.service';
import { loadJsSipScript } from './loadJsSipScript';
import type { WebPhoneConfig } from '@/types/webPhone.types';

export interface WebPhoneDrawerProps {
  open: boolean;
  onOpenChange: (open: boolean) => void;
}

type DrawerState = 'loading' | 'ready' | 'no_extension' | 'error';
type CallState = 'idle' | 'ringing' | 'connected';

const DIAL_KEYS = ['1', '2', '3', '4', '5', '6', '7', '8', '9', '*', '0', '#'];

export function WebPhoneDrawer({ open, onOpenChange }: WebPhoneDrawerProps) {
  const audioRef = useRef<HTMLAudioElement | null>(null);
  const uaRef = useRef<any>(null);
  const sessionRef = useRef<any>(null);

  const [state, setState] = useState<DrawerState>('loading');
  const [status, setStatus] = useState('Initializing...');
  const [number, setNumber] = useState('');
  const [callState, setCallState] = useState<CallState>('idle');
  const [isMuted, setIsMuted] = useState(false);
  const [isHeld, setIsHeld] = useState(false);
  const [incomingSession, setIncomingSession] = useState<any>(null);
  const [incomingNumber, setIncomingNumber] = useState('');

  const { data: configResponse, error: configError, refetch } = useQuery({
    queryKey: ['webphone-config'],
    queryFn: getWebPhoneConfig,
    enabled: open,
    retry: false,
    staleTime: Infinity,
  });

  const config = configResponse?.data;

  const resetCallState = useCallback(() => {
    setCallState('idle');
    setIsMuted(false);
    setIsHeld(false);
    setIncomingSession(null);
    setIncomingNumber('');
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
      session.on('confirmed', () => {
        setCallState('connected');
        setStatus('On call');
        attachRemoteStream(session);
      });

      session.on('ended', () => {
        resetCallState();
        setStatus('Registered');
      });

      session.on('failed', () => {
        resetCallState();
        setStatus('Registered');
      });

      session.on('hold', () => setIsHeld(true));
      session.on('unhold', () => setIsHeld(false));
      session.on('muted', () => setIsMuted(true));
      session.on('unmuted', () => setIsMuted(false));
    },
    [attachRemoteStream, resetCallState]
  );

  useEffect(() => {
    if (!open) {
      setState('loading');
      resetCallState();
      setStatus('Initializing...');
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
          setState('error');
          setStatus('Failed to load the Web Phone.');
          return;
        }

        const socket = new JsSIP.WebSocketInterface(
          `${config.wss_server}:${config.websocket_port}`
        );

        const ua = new JsSIP.UA({
          sockets: [socket],
          uri: config.sip_uri,
          password: config.sip_password,
          display_name: config.display_name,
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
          setStatus(`Registration failed: ${event.cause || 'unknown'}`);
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
        setState('error');
      }
    }

    initPhone();

    return () => {
      cancelled = true;
    };
  }, [open, config, configError, handleSessionEvents, resetCallState]);

  const handleCall = useCallback(() => {
    if (!uaRef.current || !number || !config) return;

    const target = number.includes('@')
      ? `sip:${number}`
      : `sip:${number}@${config.sip_domain}`;

    const session = uaRef.current.call(target, {
      mediaConstraints: { audio: true, video: false },
      eventHandlers: {
        connecting: () => setStatus('Calling...'),
        progress: () => setStatus('Ringing...'),
      },
    });

    sessionRef.current = session;
    handleSessionEvents(session);
  }, [number, config, handleSessionEvents]);

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
          sessionRef.current.unmute('audio');
        } else {
          sessionRef.current.mute('audio');
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

  const clearNumber = useCallback(() => {
    setNumber('');
  }, []);

  const backspace = useCallback(() => {
    setNumber((prev) => prev.slice(0, -1));
  }, []);

  return (
    <Sheet open={open} onOpenChange={onOpenChange}>
      <SheetContent side="right" className="w-full sm:max-w-[380px] p-0 overflow-hidden flex flex-col">
        <SheetHeader className="p-6 pb-2">
          <SheetTitle className="flex items-center gap-2">
            <Phone className="h-5 w-5" />
            Web Phone
          </SheetTitle>
          <SheetDescription>{status}</SheetDescription>
        </SheetHeader>

        <div className="flex-1 flex flex-col p-6 gap-4 min-h-0">
          {state === 'loading' && (
            <div className="flex-1 flex flex-col items-center justify-center gap-3">
              <Loader2 className="h-8 w-8 animate-spin text-muted-foreground" />
              <p className="text-sm text-muted-foreground">Initializing Web Phone...</p>
            </div>
          )}

          {(state === 'no_extension' || state === 'error') && (
            <div className="flex-1 flex flex-col items-center justify-center gap-3 text-center">
              <AlertTriangle className="h-10 w-10 text-destructive" />
              <div className="space-y-1">
                <h4 className="font-semibold">
                  {state === 'no_extension' ? 'Web Phone Unavailable' : 'Web Phone Error'}
                </h4>
                <p className="text-sm text-muted-foreground">
                  {state === 'no_extension'
                    ? 'No extension is assigned to your account. Web Phone cannot be used.'
                    : status}
                </p>
              </div>
              {state === 'error' && (
                <Button variant="outline" size="sm" onClick={() => refetch()}>
                  <RefreshCw className="h-4 w-4 mr-2" />
                  Retry
                </Button>
              )}
            </div>
          )}

          {state === 'ready' && (
            <>
              <div className="flex items-center gap-2">
                <Input
                  value={number}
                  onChange={(e) => setNumber(e.target.value)}
                  placeholder="Enter number or extension..."
                  className="flex-1 text-center text-lg"
                />
                <Button variant="ghost" size="icon" onClick={backspace} aria-label="Backspace">
                  <X className="h-4 w-4" />
                </Button>
              </div>

              <div className="grid grid-cols-3 gap-2">
                {DIAL_KEYS.map((key) => (
                  <Button
                    key={key}
                    variant="outline"
                    size="lg"
                    onClick={() => appendDigit(key)}
                    className="text-lg font-medium"
                  >
                    {key}
                  </Button>
                ))}
              </div>

              <div className="grid grid-cols-2 gap-2">
                <Button variant="outline" onClick={clearNumber}>Clear</Button>
                <Button variant="outline" onClick={backspace}>Backspace</Button>
              </div>

              {incomingSession ? (
                <div className="space-y-3">
                  <div className="text-center">
                    <p className="font-semibold">Incoming call</p>
                    <p className="text-sm text-muted-foreground">{incomingNumber}</p>
                  </div>
                  <div className="flex gap-2">
                    <Button className="flex-1" onClick={handleAnswer}>
                      <PhoneCall className="h-4 w-4 mr-2" />
                      Answer
                    </Button>
                    <Button variant="destructive" className="flex-1" onClick={handleReject}>
                      <PhoneOff className="h-4 w-4 mr-2" />
                      Reject
                    </Button>
                  </div>
                </div>
              ) : (
                <div className="flex gap-2">
                  <Button
                    className="flex-1"
                    onClick={handleCall}
                    disabled={callState !== 'idle' || !number}
                  >
                    <PhoneCall className="h-4 w-4 mr-2" />
                    Call
                  </Button>
                  <Button
                    variant="destructive"
                    className="flex-1"
                    onClick={handleHangup}
                    disabled={callState === 'idle' && !incomingSession}
                  >
                    <PhoneOff className="h-4 w-4 mr-2" />
                    Hangup
                  </Button>
                </div>
              )}

              {callState === 'connected' && (
                <div className="flex gap-2">
                  <Button variant="outline" className="flex-1" onClick={handleMute}>
                    {isMuted ? (
                      <>
                        <MicOff className="h-4 w-4 mr-2" />
                        Unmute
                      </>
                    ) : (
                      <>
                        <Mic className="h-4 w-4 mr-2" />
                        Mute
                      </>
                    )}
                  </Button>
                  <Button variant="outline" className="flex-1" onClick={handleHold}>
                    {isHeld ? (
                      <>
                        <Play className="h-4 w-4 mr-2" />
                        Resume
                      </>
                    ) : (
                      <>
                        <Pause className="h-4 w-4 mr-2" />
                        Hold
                      </>
                    )}
                  </Button>
                </div>
              )}
            </>
          )}

          <audio ref={audioRef} autoPlay playsInline className="hidden" />
        </div>
      </SheetContent>
    </Sheet>
  );
}

export default WebPhoneDrawer;
