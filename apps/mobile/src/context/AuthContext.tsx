import React, { createContext, useContext, useState, useEffect } from 'react';
import { MobileApi, SecureTokenStorage, setOnSessionExpired } from '../api/client';
import { SafeLogger } from '../security/secureStorage';

export interface UserProfile {
  user_id: number;
  full_name: string;
  email: string;
  role: 'user' | 'seller' | 'admin';
  kyc_status: 'unverified' | 'pending' | 'approved' | 'rejected';
  seller_kyc_status: 'unverified' | 'pending' | 'approved' | 'rejected';
  phone?: string;
  wallet_balance?: number;
  two_factor_enabled?: boolean;
}

interface AuthContextType {
  user: UserProfile | null;
  isAuthenticated: boolean;
  isLoading: boolean;
  activeRole: 'buyer' | 'seller';
  setActiveRole: (role: 'buyer' | 'seller') => void;
  login: (email: string, pass: string) => Promise<{ requires2FA?: boolean; userId?: number }>;
  register: (payload: any) => Promise<any>;
  googleLogin: (accessToken: string) => Promise<void>;
  verify2FA: (userId: number, code: string) => Promise<void>;
  verifyEmail: (token: string) => Promise<any>;
  changePassword: (currentPassword: string, newPassword: string) => Promise<void>;
  setup2FA: () => Promise<any>;
  confirm2FA: (code: string) => Promise<void>;
  disable2FA: (password: string) => Promise<void>;
  logout: () => Promise<void>;
  logoutAll: () => Promise<void>;
  refreshProfile: () => Promise<void>;
}

const AuthContext = createContext<AuthContextType | undefined>(undefined);

export const AuthProvider: React.FC<{ children: React.ReactNode }> = ({ children }) => {
  const [user, setUser] = useState<UserProfile | null>(null);
  const [isLoading, setIsLoading] = useState<boolean>(true);
  const [activeRole, setActiveRole] = useState<'buyer' | 'seller'>('buyer');

  const refreshProfile = async () => {
    try {
      const token = await SecureTokenStorage.getAccessToken();
      if (!token) {
        setUser(null);
        return;
      }
      const res = await MobileApi.getMe();
      if (res.data) {
        setUser(res.data);
        if (res.data.role === 'seller') {
          setActiveRole('seller');
        }
      }
    } catch (err: any) {
      SafeLogger.warn('Profile refresh failed', err.message);
      setUser(null);
      await SecureTokenStorage.clear();
    } finally {
      setIsLoading(false);
    }
  };

  useEffect(() => {
    refreshProfile();

    // Register callback for when refresh token expires
    setOnSessionExpired(() => {
      setUser(null);
      setActiveRole('buyer');
    });
  }, []);

  const login = async (email: string, pass: string) => {
    setIsLoading(true);
    try {
      const res = await MobileApi.login({ email, password: pass });
      if (res.data?.requires2FA) {
        return { requires2FA: true, userId: res.data.userId };
      }
      await refreshProfile();
      return {};
    } finally {
      setIsLoading(false);
    }
  };

  const register = async (payload: any) => {
    setIsLoading(true);
    try {
      const res = await MobileApi.register(payload);
      return res;
    } finally {
      setIsLoading(false);
    }
  };

  const googleLogin = async (accessToken: string) => {
    setIsLoading(true);
    try {
      await MobileApi.googleAuth(accessToken);
      await refreshProfile();
    } finally {
      setIsLoading(false);
    }
  };

  const verify2FA = async (userId: number, code: string) => {
    setIsLoading(true);
    try {
      await MobileApi.verify2Fa(userId, code);
      await refreshProfile();
    } finally {
      setIsLoading(false);
    }
  };

  const verifyEmail = async (token: string) => {
    setIsLoading(true);
    try {
      const res = await MobileApi.verifyEmail(token);
      await refreshProfile();
      return res;
    } finally {
      setIsLoading(false);
    }
  };

  const changePassword = async (currentPassword: string, newPassword: string) => {
    setIsLoading(true);
    try {
      await MobileApi.changePassword({ currentPassword, newPassword });
    } finally {
      setIsLoading(false);
    }
  };

  const setup2FA = async () => {
    return await MobileApi.setup2Fa();
  };

  const confirm2FA = async (code: string) => {
    setIsLoading(true);
    try {
      await MobileApi.confirm2Fa(code);
      await refreshProfile();
    } finally {
      setIsLoading(false);
    }
  };

  const disable2FA = async (password: string) => {
    setIsLoading(true);
    try {
      await MobileApi.disable2Fa(password);
      await refreshProfile();
    } finally {
      setIsLoading(false);
    }
  };

  const logout = async () => {
    setIsLoading(true);
    try {
      await MobileApi.logout();
      setUser(null);
      setActiveRole('buyer');
    } finally {
      setIsLoading(false);
    }
  };

  const logoutAll = async () => {
    setIsLoading(true);
    try {
      await MobileApi.logoutAll();
      setUser(null);
      setActiveRole('buyer');
    } finally {
      setIsLoading(false);
    }
  };

  return (
    <AuthContext.Provider
      value={{
        user,
        isAuthenticated: !!user,
        isLoading,
        activeRole,
        setActiveRole,
        login,
        register,
        googleLogin,
        verify2FA,
        verifyEmail,
        changePassword,
        setup2FA,
        confirm2FA,
        disable2FA,
        logout,
        logoutAll,
        refreshProfile,
      }}
    >
      {children}
    </AuthContext.Provider>
  );
};

export const useAuth = () => {
  const context = useContext(AuthContext);
  if (!context) throw new Error('useAuth must be used within an AuthProvider');
  return context;
};
