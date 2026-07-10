import { useEffect, useRef, useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { Phone, Loader2, AlertTriangle } from 'lucide-react';
import {
  Sheet,
  SheetContent,
  SheetHeader,
  SheetTitle,
  SheetDescription,
} from '@/components/ui/sheet';
import { getWebPhoneConfig } from '@/services/webPhone.service';
import { loadSiperbScript } from './loadSiperbScript';
import type { WebPhoneConfig } from '@/types/webPhone.types';

export interface WebPhoneDrawerProps {
  open: boolean;
  onOpenChange: (open: boolean) => void;
}

type DrawerState =
  | { status: 'loading' }
  | { status: 'ready' }
  | { status: 'no_extension'; message: string }
  | { status: 'error'; message: string };

export function WebPhoneDrawer({ open, onOpenChange }: WebPhoneDrawerProps) {
  const iframeRef = useRef<HTMLIFrameElement | null>(null);
  const [state, setState] = useState<DrawerState>({ status: 'loading' });
  const [isInitialized, setIsInitialized] = useState(false);

  const { data: configResponse, error: configError } = useQuery({
    queryKey: ['webphone-config'],
    queryFn: getWebPhoneConfig,
    enabled: open,
    retry: false,
    staleTime: Infinity,
  });

  const config = configResponse?.data;

  useEffect(() => {
    if (!open) {
      setState({ status: 'loading' });
      setIsInitialized(false);
      return;
    }

    if (configError) {
      const status = (configError as any)?.response?.status;
      if (status === 404) {
        setState({ status: 'no_extension', message: 'No extension is assigned to your account. Web Phone cannot be used.' });
      } else {
        setState({ status: 'error', message: 'Failed to load Web Phone configuration. Please try again later.' });
      }
      return;
    }

    if (!config) {
      setState({ status: 'loading' });
      return;
    }

    let cancelled = false;

    async function initPhone() {
      try {
        await loadSiperbScript();
        if (cancelled) return;

        const iframe = iframeRef.current;
        if (!iframe) return;

        const Siperb = (window as any).Siperb;
        if (!Siperb) {
          setState({ status: 'error', message: 'Failed to load the Web Phone.' });
          return;
        }

        await Siperb.LoadBrowserPhone(iframe);
        if (cancelled) return;

        const phone = (window as any).phone;
        if (!phone) {
          setState({ status: 'error', message: 'Failed to load the Web Phone.' });
          return;
        }

        phone.LoadFromStorage = async (storeName: string) => {
          return localStorage.getItem(storeName) || '';
        };
        phone.SaveToStorage = async (storeName: string, data: string) => {
          localStorage.setItem(storeName, data);
        };

        phone.OnIncomingCall = (details: any) => {
          console.log('[WebPhone] Incoming call:', details);
        };

        await phone.InitBrowserPhone();
        if (cancelled) return;

        await phone.Init({
          SipUsername: config.sip_username,
          SipPassword: config.sip_password,
          SipDomain: config.sip_domain,
          WssServer: config.wss_server,
          WebSocketPort: config.websocket_port,
        });

        if (!cancelled) {
          setState({ status: 'ready' });
          setIsInitialized(true);
        }
      } catch (error: any) {
        console.error('[WebPhone] Initialization failed:', error);
        if (!cancelled) {
          setState({ status: 'error', message: 'The Web Phone could not register. Please check your extension settings.' });
        }
      }
    }

    initPhone();

    return () => {
      cancelled = true;
    };
  }, [open, config, configError]);

  return (
    <Sheet open={open} onOpenChange={onOpenChange}>
      <SheetContent side="right" className="w-full sm:max-w-[420px] p-0 overflow-hidden flex flex-col">
        <SheetHeader className="p-6 pb-2">
          <SheetTitle className="flex items-center gap-2">
            <Phone className="h-5 w-5" />
            Web Phone
          </SheetTitle>
          <SheetDescription>
            Make and receive calls from your assigned extension.
          </SheetDescription>
        </SheetHeader>

        <div className="flex-1 relative min-h-0 p-6 pt-2">
          {state.status === 'loading' && (
            <div className="flex flex-col items-center justify-center h-full gap-3">
              <Loader2 className="h-8 w-8 animate-spin text-muted-foreground" />
              <p className="text-sm text-muted-foreground">Initializing Web Phone...</p>
            </div>
          )}

          {(state.status === 'no_extension' || state.status === 'error') && (
            <div className="flex flex-col items-center justify-center h-full gap-3 text-center">
              <AlertTriangle className="h-10 w-10 text-destructive" />
              <div className="space-y-1">
                <h4 className="font-semibold">{state.status === 'no_extension' ? 'Web Phone Unavailable' : 'Web Phone Error'}</h4>
                <p className="text-sm text-muted-foreground">{state.message}</p>
              </div>
            </div>
          )}

          <iframe
            ref={iframeRef}
            title="Web Phone"
            src="about:blank"
            className={
              state.status === 'ready'
                ? 'w-full h-full rounded-md border border-border'
                : 'hidden'
            }
            allow="microphone; camera"
          />
        </div>
      </SheetContent>
    </Sheet>
  );
}

export default WebPhoneDrawer;
