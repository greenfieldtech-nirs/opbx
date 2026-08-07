/**
 * Auth0 Callback Page
 *
 * Handles the OAuth callback from Auth0, exchanges the code for a session,
 * and routes the user based on the backend response.
 */

import { useEffect, useRef, useState } from 'react';
import { useNavigate, useSearchParams } from 'react-router-dom';
import { auth0Service, type Auth0RegistrationRequired } from '@/services/auth0.service';
import { useAuth } from '@/hooks/useAuth';
import { storage } from '@/utils/storage';
import { toast } from 'sonner';
import type { User } from '@/types';

export default function Auth0Callback() {
  const [searchParams] = useSearchParams();
  const navigate = useNavigate();
  const { setUser, setToken } = useAuth();
  const [status, setStatus] = useState('Processing login...');
  const hasHandled = useRef(false);

  useEffect(() => {
    if (hasHandled.current) {
      return;
    }

    const code = searchParams.get('code');
    const state = searchParams.get('state');

    if (!code || !state) {
      toast.error('Invalid callback.');
      navigate('/ui/login');
      return;
    }

    hasHandled.current = true;

    auth0Service
      .callback(code, state)
      .then((data) => {
        if ('error' in data) {
          const code = data.error.code;

          if (code === 'AUTH0_REGISTRATION_REQUIRED') {
            const registrationData = data as Auth0RegistrationRequired;
            navigate(
              `/ui/auth/onboarding?email=${encodeURIComponent(registrationData.profile.email)}&provider=${registrationData.profile.provider}&subject=${registrationData.profile.subject}&name=${encodeURIComponent(registrationData.profile.name)}`
            );
            return;
          }

          if (code === 'JOIN_REQUEST_PENDING') {
            setStatus('Your request to join the organization is pending approval.');
            return;
          }

          if (code === 'INVITE_EMAIL_MISMATCH') {
            toast.error('Invitation email mismatch', {
              description: 'The email address from your social account does not match the invited email.',
            });
            navigate('/ui/login');
            return;
          }

          if (code === 'AUTH0_EMAIL_UNVERIFIED') {
            toast.error('Email not verified', {
              description: 'Please verify your email with your social provider before continuing.',
            });
            navigate('/ui/login');
            return;
          }

          // Account-linking errors: the user was linking a provider from their
          // Profile page, so route them back there with a clear message.
          if (code === 'AUTH0_LINK_EMAIL_MISMATCH') {
            toast.error('Could not link account', {
              description:
                'The email on your social account does not match your account email. Please use the social account that matches your email.',
            });
            navigate('/ui/profile');
            return;
          }

          if (code === 'AUTH0_LINK_ALREADY_LINKED') {
            toast.error('Could not link account', {
              description: data.error.message,
            });
            navigate('/ui/profile');
            return;
          }

          if (code === 'INVITE_INVALID_USER') {
            toast.error('Invalid invitation', {
              description: 'This invitation is not valid for the selected account.',
            });
            navigate('/ui/login');
            return;
          }

          if (code === 'INVITE_EXPIRED_OR_INVALID') {
            toast.error('Invitation expired', {
              description: 'This invitation has expired or is no longer valid.',
            });
            navigate('/ui/login');
            return;
          }

          toast.error(data.error.message);
          navigate('/ui/login');
          return;
        }

        // Account-linking success returns { message } with no access token.
        // The user is already logged in (they linked from their Profile page),
        // so send them back to Profile rather than mis-handling it as a login.
        if (!('access_token' in data)) {
          toast.success('Account linked successfully!');
          navigate('/ui/profile');
          return;
        }

        storage.setToken(data.access_token);
        storage.setUser(data.user);
        setToken(data.access_token);
        setUser(data.user as unknown as User);
        toast.success('Login successful!');
        navigate('/ui/dashboard');
      })
      .catch(() => {
        toast.error('Login failed.');
        navigate('/ui/login');
      });
  }, [searchParams, navigate, setUser, setToken]);

  return (
    <div className="min-h-screen flex items-center justify-center">
      <p>{status}</p>
    </div>
  );
}
