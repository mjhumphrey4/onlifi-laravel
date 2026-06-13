import { createContext, useContext, useState, useEffect, ReactNode } from 'react';
import { apiMe, apiLogin, apiLogout, apiSites } from '../utils/api';

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

interface PaymentSite {
  display_name: string;
  active: number | boolean;
}

const AuthContext = createContext<AuthContextType | null>(null);

export function AuthProvider({ children }: { children: ReactNode }) {
  const [user, setUser] = useState<AuthUser | null>(null);
  const [siteNames, setSiteNames] = useState<string[]>([]);
  const [loading, setLoading] = useState(true);

  useEffect(() => {
    apiMe()
      .then(async (d) => {
        setUser(d.user ?? null);
        if (d.user?.role === 'admin') {
          const sites = await apiSites().catch(() => ({ sites: [] }));
          setSiteNames((sites.sites ?? []).filter((site: PaymentSite) => site.active === 1 || site.active === true).map((site: PaymentSite) => site.display_name));
        }
      })
      .catch(() => setUser(null))
      .finally(() => setLoading(false));
  }, []);

  const login = async (username: string, password: string) => {
    const d = await apiLogin(username, password);
    setUser(d.user);
    if (d.user?.role === 'admin') {
      const sites = await apiSites().catch(() => ({ sites: [] }));
      setSiteNames((sites.sites ?? []).filter((site: PaymentSite) => site.active === 1 || site.active === true).map((site: PaymentSite) => site.display_name));
    }
  };

  const logout = async () => {
    await apiLogout();
    setUser(null);
    setSiteNames([]);
  };

  const isAdmin = () => user?.role === 'admin';
  const userSites = () => (user?.role === 'admin' ? siteNames : user?.site ? [user.site] : []);

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
