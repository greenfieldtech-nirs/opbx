/**
 * Local Storage Utilities
 */

const TOKEN_KEY = 'opbx_token';
const USER_KEY = 'opbx_user';

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

  // Clear all
  clearAll(): void {
    this.removeToken();
    this.removeUser();
  },
};
