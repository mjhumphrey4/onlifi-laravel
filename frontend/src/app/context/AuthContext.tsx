import { createContext, useContext, useState, useEffect, ReactNode } from 'react';

export interface AuthUser {
  id: number;
  username: string;
  name: string;
  email: string;
  role: 'admin' | 'user' | 'reseller';
  site: string | null;
}

interface AuthContextType {
  user: AuthUser | null;
  loading: boolean;
  selectedSite: string | null;
  setSelectedSite: (site: string) => void;
  login: (username: string, password: string) => Promise<void>;
  logout: () => Promise<void>;
  isAdmin: () => boolean;
  userSites: () => string[];
}

const ALL_SITES = ['Enock', 'Richard', 'STK', 'Remmy', 'Guma'];

const AuthContext = createContext<AuthContextType | null>(null);

export function AuthProvider({ children }: { children: ReactNode }) {
  const [user, setUser] = useState<AuthUser | null>(null);
  const [loading, setLoading] = useState(true);
  const [selectedSite, setSelectedSite] = useState<string | null>(null);

  useEffect(() => {
    // Check authentication with new multi-tenant API
    fetch('/api/auth_api.php?action=me')
      .then(res => res.json())
      .then((d) => {
        if (d.success && d.user) {
          setUser({
            id: d.user.id,
            username: d.user.username,
            name: d.user.name,
            email: d.user.email,
            role: d.user.role,
            site: null // Multi-tenant system doesn't use site concept
          });
        } else {
          setUser(null);
        }
      })
      .catch(() => setUser(null))
      .finally(() => setLoading(false));
  }, []);

  const login = async (username: string, password: string) => {
    const response = await fetch('/api/auth_api.php?action=login', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ username, password }),
    });

    const data = await response.json();

    if (!data.success) {
      throw new Error(data.error || 'Login failed');
    }

    setUser({
      id: data.user.id,
      username: data.user.username,
      name: data.user.name,
      email: data.user.email,
      role: data.user.role,
      site: null
    });
  };

  const logout = async () => {
    await fetch('/api/auth_api.php?action=logout', { method: 'POST' });
    setUser(null);
  };

  const isAdmin = () => user?.role === 'admin';
  const userSites = () => (user?.role === 'admin' ? ALL_SITES : user?.site ? [user.site] : []);

  return (
    <AuthContext.Provider value={{ user, loading, selectedSite, setSelectedSite, login, logout, isAdmin, userSites }}>
      {children}
    </AuthContext.Provider>
  );
}

export function useAuth() {
  const ctx = useContext(AuthContext);
  if (!ctx) throw new Error('useAuth must be used within AuthProvider');
  return ctx;
}
