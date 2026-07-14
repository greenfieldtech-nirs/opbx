// Embedded Dialer widget entry. Bundled by vite.embed.config.ts into a single
// self-contained IIFE and loaded by the OPBX-served iframe (see
// EmbedDialerController::renderIframe). It reads the token/theme injected as
// window.__OPBX_EMBED__, talks to the public /v1/embed/* API with that token,
// and mounts the shared <WebPhone /> wired to the postMessage bus.

import React from 'react';
import ReactDOM from 'react-dom/client';
import axios from 'axios';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { WebPhone } from '@/components/WebPhone/WebPhone';
import { createEmbedBus } from './embedApi';
import type {
  WebPhoneConfigResponse,
  WebPhoneCallsLogResponse,
} from '@/services/webPhone.service';
import '../index.css';

interface EmbedConfig {
  token: string;
  iconPosition?: 'bottom-right' | 'bottom-left' | 'top-right' | 'top-left';
  iconBackgroundColor?: string;
}

declare global {
  interface Window {
    __OPBX_EMBED__?: EmbedConfig;
  }
}

function mount(): void {
  const embed = window.__OPBX_EMBED__;
  const rootEl = document.getElementById('opbx-dialer-root');

  if (!embed?.token || !rootEl) {
    // Nothing to render without a token or mount point.
    return;
  }

  // Same-origin API (the iframe is served by OPBX), authenticated with the
  // per-user embed token rather than the SPA session.
  const embedApi = axios.create({
    baseURL: '/api/v1',
    headers: {
      Accept: 'application/json',
      Authorization: `Bearer ${embed.token}`,
    },
    timeout: 30000,
  });

  const configQueryFn = async (): Promise<WebPhoneConfigResponse> => {
    const res = await embedApi.get<WebPhoneConfigResponse>('/embed/config');
    return res.data;
  };

  const callsLogQueryFn = async (): Promise<WebPhoneCallsLogResponse> => {
    const res = await embedApi.get<WebPhoneCallsLogResponse>('/embed/calls-log');
    return res.data;
  };

  // Install the postMessage bridge before mounting so WebPhone's embed effect
  // finds the bus on first render.
  createEmbedBus();

  const queryClient = new QueryClient({
    defaultOptions: {
      queries: { retry: false, refetchOnWindowFocus: false, staleTime: 0, gcTime: 0 },
    },
  });

  ReactDOM.createRoot(rootEl).render(
    <React.StrictMode>
      <QueryClientProvider client={queryClient}>
        <WebPhone
          configQueryFn={configQueryFn}
          callsLogQueryFn={callsLogQueryFn}
          iconPosition={embed.iconPosition}
          iconBackgroundColor={embed.iconBackgroundColor}
          autoOpen
        />
      </QueryClientProvider>
    </React.StrictMode>,
  );
}

mount();
