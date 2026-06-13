import { createContext, useContext, useState, useEffect, ReactNode } from 'react';
import { apiMe, apiLogin, apiLogout } from '../utils/api';

export interface AuthUser {
  username: string;
  name: string;
  email: string;
  role: 'admin' | 'user';
  site: string | null;
}

interface AuthContextType {
  user: AuthUser | null;
  loading: boolean;
  login: (username: string, password: string) => Promise<void>;
  logout: () => Promise<void>;
  isAdmin: () => boolean;
  userSites: () => string[];
}

const ALL_SITES = ['Enock', 'Richard', 'STK', 'Remmy', 'Guma', 'Namungoona'];

const AuthContext = createContext<AuthContextType | null>(null);

export function AuthProvider({ children }: { children: ReactNode }) {
  const [user, setUser] = useState<AuthUser | null>(null);
  const [loading, setLoading] = useState(true);

  useEffect(() => {
    apiMe()
      .then((d) => setUser(d.user ?? null))
      .catch(() => setUser(null))
      .finally(() => setLoading(false));
  }, []);

  const login = async (username: string, password: string) => {
    const d = await apiLogin(username, password);
    setUser(d.user);
  };

  const logout = async () => {
    await apiLogout();
    setUser(null);
  };

  const isAdmin = () => user?.role === 'admin';
  const userSites = () => (user?.role === 'admin' ? ALL_SITES : user?.site ? [user.site] : []);

  return (
    <AuthContext.Provider value={{ user, loading, login, logout, isAdmin, userSites }}>
      {children}
    </AuthContext.Provider>
  );
}

export function useAuth() {
  const ctx = useContext(AuthContext);
  if (!ctx) throw new Error('useAuth must be used within AuthProvider');
  return ctx;
}
