/**
 * Local Storage Utilities
 *
 * The normal SPA session lives in localStorage (shared across tabs).
 *
 * Platform-owner impersonation runs in a SEPARATE browser tab and must NOT
 * clobber the owner's shared session. To achieve per-tab isolation, an
 * impersonation session is stored in sessionStorage (scoped to a single tab).
 * When an impersonation token is present, the token/user getters prefer it, so
 * the existing axios interceptor automatically uses the impersonation token in
 * that tab only — with zero changes to individual API calls.
 */

const TOKEN_KEY = 'opbx_token';
const USER_KEY = 'opbx_user';

// Per-tab (sessionStorage) keys for an active impersonation session.
const IMPERSONATION_TOKEN_KEY = 'opbx_impersonation_token';
const IMPERSONATION_USER_KEY = 'opbx_impersonation_user';
const IMPERSONATION_ORG_KEY = 'opbx_impersonation_org';

export interface ImpersonationOrganization {
  id: number | string;
  name: string;
  slug?: string;
  status?: string;
}

export const storage = {
  // ---- Impersonation (per-tab, sessionStorage) ----

  isImpersonating(): boolean {
    return sessionStorage.getItem(IMPERSONATION_TOKEN_KEY) !== null;
  },

  getImpersonationToken(): string | null {
    return sessionStorage.getItem(IMPERSONATION_TOKEN_KEY);
  },

  getImpersonationOrganization(): ImpersonationOrganization | null {
    const org = sessionStorage.getItem(IMPERSONATION_ORG_KEY);
    return org ? JSON.parse(org) : null;
  },

  setImpersonation<T>(token: string, user: T, organization: ImpersonationOrganization): void {
    sessionStorage.setItem(IMPERSONATION_TOKEN_KEY, token);
    sessionStorage.setItem(IMPERSONATION_USER_KEY, JSON.stringify(user));
    sessionStorage.setItem(IMPERSONATION_ORG_KEY, JSON.stringify(organization));
  },

  clearImpersonation(): void {
    sessionStorage.removeItem(IMPERSONATION_TOKEN_KEY);
    sessionStorage.removeItem(IMPERSONATION_USER_KEY);
    sessionStorage.removeItem(IMPERSONATION_ORG_KEY);
  },

  // ---- Token management ----
  // Prefers the per-tab impersonation token when present.

  getToken(): string | null {
    return sessionStorage.getItem(IMPERSONATION_TOKEN_KEY) ?? localStorage.getItem(TOKEN_KEY);
  },

  setToken(token: string): void {
    localStorage.setItem(TOKEN_KEY, token);
  },

  removeToken(): void {
    localStorage.removeItem(TOKEN_KEY);
  },

  // ---- User management ----
  // Prefers the per-tab impersonation user when impersonating.

  getUser<T>(): T | null {
    const impersonationUser = sessionStorage.getItem(IMPERSONATION_USER_KEY);
    if (impersonationUser) {
      return JSON.parse(impersonationUser);
    }
    const user = localStorage.getItem(USER_KEY);
    return user ? JSON.parse(user) : null;
  },

  setUser<T>(user: T): void {
    localStorage.setItem(USER_KEY, JSON.stringify(user));
  },

  removeUser(): void {
    localStorage.removeItem(USER_KEY);
  },

  // ---- Clear ----

  /**
   * Clear the session. In an impersonation tab this clears ONLY the per-tab
   * impersonation session, leaving the owner's shared localStorage session
   * intact. Otherwise clears the normal session.
   */
  clearAll(): void {
    if (this.isImpersonating()) {
      this.clearImpersonation();
      return;
    }
    this.removeToken();
    this.removeUser();
  },
};
