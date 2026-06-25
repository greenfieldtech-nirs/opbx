/**
 * Auth0 Syndicated Authentication Service
 *
 * Handles Auth0 social login/linking API calls.
 */

import api from '@/services/api';

export interface Auth0RedirectResponse {
  redirect_url: string;
  state: string;
}

export interface Auth0CallbackResponse {
  user: {
    id: number;
    organization_id: number;
    name: string;
    email: string;
    role: string;
    status: string;
  };
  organization: {
    id: number;
    name: string;
    slug: string;
    status: string;
    timezone: string;
  };
  access_token: string;
  token_type: string;
  expires_in: number;
}

export interface Auth0RegistrationRequired {
  error: { code: 'AUTH0_REGISTRATION_REQUIRED'; message: string };
  profile: {
    email: string;
    name: string;
    provider: string;
    subject: string;
  };
}

export interface Auth0Error {
  error: { code: string; message: string };
}

export const auth0Service = {
  redirect(provider: string, intent: 'login' | 'register' | 'link'): Promise<Auth0RedirectResponse> {
    return api.post('/v1/auth/auth0/redirect', { provider, intent }).then((res) => res.data);
  },

  callback(code: string, state: string): Promise<Auth0CallbackResponse | Auth0RegistrationRequired | Auth0Error> {
    return api.get('/v1/auth/auth0/callback', { params: { code, state } }).then((res) => res.data);
  },

  initiateLink(provider: string): Promise<Auth0RedirectResponse> {
    return api.post('/v1/auth/auth0/link', { provider }).then((res) => res.data);
  },

  unlink(provider: string): Promise<{ message: string }> {
    return api.post('/v1/auth/auth0/unlink', { provider }).then((res) => res.data);
  },

  submitJoinRequest(data: {
    organization_slug: string;
    provider: string;
    provider_subject: string;
    email: string;
    name: string;
  }): Promise<unknown> {
    return api.post('/v1/organizations/join-requests', data).then((res) => res.data);
  },
};
