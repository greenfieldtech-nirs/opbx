/**
 * Local Storage Utilities
 */

const TOKEN_KEY = 'opbx_token';
const USER_KEY = 'opbx_user';
const OPERATE_AS_KEY = 'opbx_operate_as';

export interface OperateAsOrg {
  id: number;
  name: string;
}

export const storage = {
  // Token management
  getToken(): string | null {
    return localStorage.getItem(TOKEN_KEY);
  },

  setToken(token: string): void {
    localStorage.setItem(TOKEN_KEY, token);
  },

  removeToken(): void {
    localStorage.removeItem(TOKEN_KEY);
  },

  // User management
  getUser<T>(): T | null {
    const user = localStorage.getItem(USER_KEY);
    return user ? JSON.parse(user) : null;
  },

  setUser<T>(user: T): void {
    localStorage.setItem(USER_KEY, JSON.stringify(user));
  },

  removeUser(): void {
    localStorage.removeItem(USER_KEY);
  },

  // Operate-as (platform owner impersonation) management
  getOperateAsOrg(): OperateAsOrg | null {
    const value = localStorage.getItem(OPERATE_AS_KEY);
    if (!value) return null;
    try {
      return JSON.parse(value) as OperateAsOrg;
    } catch {
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
