/**
 * Browser storage utilities (localStorage / sessionStorage)
 */

const TOKEN_KEY = 'opbx_token';
const USER_KEY = 'opbx_user';
const OPERATE_AS_KEY = 'opbx_operate_as';

export interface OperateAsOrg {
  id: number;
  name: string;
}

// The session lives in localStorage when the user chose "Remember me" (survives a browser
// restart) and in sessionStorage otherwise (gone when the tab or browser closes).
const sessionArea = (): Storage => (sessionStorage.getItem(TOKEN_KEY) ? sessionStorage : localStorage);

export const storage = {
  // Token management
  getToken(): string | null {
    return sessionStorage.getItem(TOKEN_KEY) ?? localStorage.getItem(TOKEN_KEY);
  },

  setToken(token: string, remember = true): void {
    const [keep, drop] = remember ? [localStorage, sessionStorage] : [sessionStorage, localStorage];
    drop.removeItem(TOKEN_KEY);
    drop.removeItem(USER_KEY);
    keep.setItem(TOKEN_KEY, token);
  },

  removeToken(): void {
    localStorage.removeItem(TOKEN_KEY);
    sessionStorage.removeItem(TOKEN_KEY);
  },

  // User management (stored next to the token)
  getUser<T>(): T | null {
    const area = sessionArea();
    const user = area.getItem(USER_KEY);
    if (!user) return null;
    try {
      return JSON.parse(user) as T;
    } catch {
      // Corrupt value: drop it so it cannot crash every page load
      area.removeItem(USER_KEY);
      return null;
    }
  },

  setUser<T>(user: T): void {
    sessionArea().setItem(USER_KEY, JSON.stringify(user));
  },

  removeUser(): void {
    localStorage.removeItem(USER_KEY);
    sessionStorage.removeItem(USER_KEY);
  },

  // Operate-as (platform owner impersonation) management
  getOperateAsOrg(): OperateAsOrg | null {
    const value = localStorage.getItem(OPERATE_AS_KEY);
    if (!value) return null;
    try {
      return JSON.parse(value) as OperateAsOrg;
    } catch {
      localStorage.removeItem(OPERATE_AS_KEY);
      return null;
    }
  },

  setOperateAsOrg(org: OperateAsOrg): void {
    localStorage.setItem(OPERATE_AS_KEY, JSON.stringify(org));
  },

  clearOperateAsOrg(): void {
    localStorage.removeItem(OPERATE_AS_KEY);
  },

  // Clear all
  clearAll(): void {
    this.removeToken();
    this.removeUser();
    this.clearOperateAsOrg();
  },
};
