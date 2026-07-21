/**
 * Impersonation Callback Page
 *
 * Bootstraps an impersonation session in a NEW browser tab. The platform owner's
 * "Open as admin" action opens this route with the scoped token + organization
 * passed in the URL fragment (never the query string, so it is not sent to
 * servers or written to logs).
 *
 * The token/user/org are stored in sessionStorage (per-tab isolation), so this
 * tab acts as an org admin WITHOUT clobbering the owner's shared localStorage
 * session in the original tab.
 */

import { useEffect, useRef, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { useAuth } from '@/hooks/useAuth';
import { storage } from '@/utils/storage';
import { authService } from '@/services/auth.service';
import type { User } from '@/types';

interface ImpersonationPayload {
  token: string;
  organization: {
    id: number | string;
    name: string;
    slug?: string;
    status?: string;
  };
}

export default function ImpersonateCallback() {
  const navigate = useNavigate();
  const { setUser, setToken } = useAuth();
  const [status, setStatus] = useState('Opening organization...');
  const hasHandled = useRef(false);

  useEffect(() => {
    if (hasHandled.current) {
      return;
    }
    hasHandled.current = true;

    // Parse the fragment payload.
    const hash = window.location.hash.startsWith('#')
      ? window.location.hash.slice(1)
      : window.location.hash;

    if (!hash) {
      setStatus('Invalid impersonation link.');
      navigate('/ui/login');
      return;
    }

    let payload: ImpersonationPayload;
    try {
      payload = JSON.parse(decodeURIComponent(hash));
    } catch {
      setStatus('Invalid impersonation link.');
      navigate('/ui/login');
      return;
    }

    // Immediately clear the fragment so the token is not left in the URL.
    window.history.replaceState(null, '', window.location.pathname);

    if (!payload.token || !payload.organization) {
      setStatus('Invalid impersonation link.');
      navigate('/ui/login');
      return;
    }

    // Store the per-tab impersonation session first, so the API client's
    // interceptor immediately uses the impersonation token.
    storage.setImpersonation(payload.token, null, payload.organization);

    // Resolve the current user (the platform owner) under the impersonation
    // token, then hydrate the session and enter the org dashboard.
    authService
      .me()
      .then((user) => {
        storage.setImpersonation(payload.token, user, payload.organization);
        setToken(payload.token);
        setUser(user as unknown as User);
        navigate('/ui/dashboard');
      })
      .catch(() => {
        storage.clearImpersonation();
        setStatus('Could not start the management session. It may have expired.');
      });
  }, [navigate, setToken, setUser]);

  return (
    <div className="min-h-screen flex items-center justify-center">
      <p>{status}</p>
    </div>
  );
}
