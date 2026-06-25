/**
 * Accept Invitation Page
 *
 * Public page that validates an invitation token and lets the user accept it.
 * After acceptance the backend returns the Auth0 redirect URL for account creation.
 */

import { useEffect, useState } from 'react';
import { useSearchParams } from 'react-router-dom';
import axios, { AxiosError } from 'axios';
import { Loader2, Mail, AlertCircle } from 'lucide-react';
import { Button } from '@/components/ui/button';
import {
  Card,
  CardContent,
  CardDescription,
  CardHeader,
  CardTitle,
} from '@/components/ui/card';
import { validateToken, accept } from '@/services/invitation.service';
import type { APIError } from '@/types';

type InvitationState =
  | { status: 'loading' }
  | { status: 'valid'; email: string; organizationName: string }
  | { status: 'error'; message: string };

export default function AcceptInvitation() {
  const [searchParams] = useSearchParams();
  const [state, setState] = useState<InvitationState>({ status: 'loading' });
  const [isAccepting, setIsAccepting] = useState(false);

  const token = searchParams.get('token');

  useEffect(() => {
    if (!token) {
      setState({ status: 'error', message: 'Invitation token is missing.' });
      return;
    }

    validateToken(token)
      .then((response) => {
        setState({
          status: 'valid',
          email: response.data.email,
          organizationName: response.data.organization_name,
        });
      })
      .catch((error) => {
        if (axios.isAxiosError(error)) {
          const axiosError = error as AxiosError<APIError>;
          setState({
            status: 'error',
            message: axiosError.response?.data?.error?.message || 'This invitation is invalid or has expired.',
          });
          return;
        }

        setState({ status: 'error', message: 'This invitation is invalid or has expired.' });
      });
  }, [token]);

  const handleAccept = async () => {
    if (!token) return;

    setIsAccepting(true);

    try {
      const response = await accept(token);
      window.location.href = response.redirect_url;
    } catch (error) {
      setIsAccepting(false);

      if (axios.isAxiosError(error)) {
        const axiosError = error as AxiosError<APIError>;
        setState({
          status: 'error',
          message: axiosError.response?.data?.error?.message || 'Failed to accept invitation.',
        });
        return;
      }

      setState({ status: 'error', message: 'Failed to accept invitation.' });
    }
  };

  return (
    <div className="min-h-screen flex items-center justify-center bg-muted/40 p-4">
      <Card className="w-full max-w-md">
        {state.status === 'loading' && (
          <CardContent className="py-12">
            <div className="flex flex-col items-center justify-center text-center space-y-4">
              <Loader2 className="h-8 w-8 animate-spin text-primary" />
              <p className="text-muted-foreground">Verifying your invitation...</p>
            </div>
          </CardContent>
        )}

        {state.status === 'valid' && (
          <>
            <CardHeader className="text-center">
              <CardTitle className="text-2xl">You're invited</CardTitle>
              <CardDescription>
                Join <strong>{state.organizationName}</strong> on OPBX.
              </CardDescription>
            </CardHeader>
            <CardContent className="space-y-6">
              <div className="flex items-center gap-3 p-3 bg-muted rounded-lg">
                <Mail className="h-5 w-5 text-muted-foreground" />
                <span className="text-sm">{state.email}</span>
              </div>
              <Button
                className="w-full"
                onClick={handleAccept}
                disabled={isAccepting}
              >
                {isAccepting ? (
                  <>
                    <Loader2 className="h-4 w-4 mr-2 animate-spin" />
                    Accepting...
                  </>
                ) : (
                  'Accept & Continue'
                )}
              </Button>
            </CardContent>
          </>
        )}

        {state.status === 'error' && (
          <>
            <CardHeader className="text-center">
              <CardTitle className="text-2xl flex items-center justify-center gap-2">
                <AlertCircle className="h-6 w-6 text-destructive" />
                Invitation Error
              </CardTitle>
              <CardDescription>{state.message}</CardDescription>
            </CardHeader>
            <CardContent>
              <Button
                variant="outline"
                className="w-full"
                onClick={() => { window.location.href = '/ui/login'; }}
              >
                Go to Login
              </Button>
            </CardContent>
          </>
        )}
      </Card>
    </div>
  );
}
