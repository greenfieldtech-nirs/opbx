/**
 * Authentication Context
 *
 * Manages user authentication state across the application
 */

import React, { createContext, useContext, useEffect, useState } from 'react';
import { authService } from '@/services/auth.service';
import { storage } from '@/utils/storage';
import type { User, LoginRequest } from '@/types';
import { getApiErrorMessage } from '@/services/api';

interface AuthContextType {
  user: User | null;
  token: string | null;
  isAuthenticated: boolean;
  isLoading: boolean;
  login: (credentials: LoginRequest, onSuccess?: () => void) => Promise<void>;
  logout: (onSuccess?: () => void) => Promise<void>;
  refreshUser: () => Promise<void>;
}

const AuthContext = createContext<AuthContextType | undefined>(undefined);

export function AuthProvider({ children }: { children: React.ReactNode }) {
  const [user, setUser] = useState<User | null>(storage.getUser());
  const [token, setToken] = useState<string | null>(storage.getToken());
  const [isLoading, setIsLoading] = useState(true);

  // Verify auth on mount
  useEffect(() => {
    const verifyAuth = async () => {
      const storedToken = storage.getToken();
      const storedUser = storage.getUser<User>();

      if (storedToken && storedUser) {
        try {
          // Verify token is still valid by fetching user profile
          const currentUser = await authService.me();
          setUser(currentUser);
          setToken(storedToken);
          storage.setUser(currentUser);
        } catch (error) {
          console.error('Auth verification failed:', error);
          storage.clearAll();
          setUser(null);
          setToken(null);
        }
      }

      setIsLoading(false);
    };

    verifyAuth();
  }, []);

  /**
   * Login user
   */
  const login = async (credentials: LoginRequest, onSuccess?: () => void): Promise<void> => {
    try {
      const response = await authService.login(credentials);

      // Store auth data
      storage.setToken(response.access_token);
      storage.setUser(response.user);

      setToken(response.access_token);
      setUser(response.user);

      // Call success callback (for navigation)
      if (onSuccess) {
        onSuccess();
      }
    } catch (error) {
      const message = getApiErrorMessage(error);
      throw new Error(message);
    }
  };

  /**
   * Logout user
   */
  const logout = async (onSuccess?: () => void): Promise<void> => {
    try {
      await authService.logout();
    } catch (error) {
      console.error('Logout failed:', error);
    } finally {
      // Clear auth data regardless of API call result
      storage.clearAll();
      setToken(null);
      setUser(null);

      // Call success callback (for navigation)
      if (onSuccess) {
        onSuccess();
      }
    }
  };

  /**
   * Refresh user profile
   */
  const refreshUser = async (): Promise<void> => {
    try {
      const currentUser = await authService.me();
      setUser(currentUser);
      storage.setUser(currentUser);
    } catch (error) {
      console.error('Failed to refresh user:', error);
    }
  };

  const value: AuthContextType = {
    user,
    token,
    isAuthenticated: !!token && !!user,
    isLoading,
    login,
    logout,
    refreshUser,
  };

  return <AuthContext.Provider value={value}>{children}</AuthContext.Provider>;
}

/**
 * Hook to use auth context
 */
export function useAuth(): AuthContextType {
  const context = useContext(AuthContext);
  if (context === undefined) {
    throw new Error('useAuth must be used within an AuthProvider');
  }
  return context;
}
