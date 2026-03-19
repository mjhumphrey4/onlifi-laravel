import { createContext, useContext, useState, useEffect, ReactNode } from 'react';
import { adminMe, adminLogin, adminLogout } from '../utils/api';

export interface AuthUser {
  id: number;
  username: string;
  name: string;
  email: string;
  role: 'super_admin' | 'tenant' | 'user';
  tenant_id?: number;
  tenant_name?: string;
}

interface AuthContextType {
  user: AuthUser | null;
  loading: boolean;
  login: (email: string, password: string) => Promise<void>;
  logout: () => Promise<void>;
  isAdmin: () => boolean;
  isTenant: () => boolean;
}

const AuthContext = createContext<AuthContextType | null>(null);

export function AuthProvider({ children }: { children: ReactNode }) {
  const [user, setUser] = useState<AuthUser | null>(null);
  const [loading, setLoading] = useState(true);

  useEffect(() => {
    checkAuth();
  }, []);

  const checkAuth = async () => {
    const token = localStorage.getItem('admin_token') || localStorage.getItem('tenant_token');
    
    if (!token) {
      setUser(null);
      setLoading(false);
      return;
    }

    try {
      // Try admin auth first
      if (localStorage.getItem('admin_token')) {
        const data = await adminMe();
        setUser({
          id: data.admin.id,
          username: data.admin.email,
          name: data.admin.name,
          email: data.admin.email,
          role: 'super_admin',
        });
      }
      // TODO: Add tenant auth check when tenant login is implemented
    } catch (error) {
      // Token invalid, clear it
      localStorage.removeItem('admin_token');
      localStorage.removeItem('tenant_token');
      setUser(null);
    } finally {
      setLoading(false);
    }
  };

  const login = async (email: string, password: string) => {
    const data = await adminLogin(email, password);

    // Store token
    localStorage.setItem('admin_token', data.token);
    localStorage.setItem('admin_user', JSON.stringify(data.admin));

    setUser({
      id: data.admin.id,
      username: data.admin.email,
      name: data.admin.name,
      email: data.admin.email,
      role: 'super_admin',
    });
  };

  const logout = async () => {
    try {
      await adminLogout();
    } catch (error) {
      // Ignore logout errors
    }
    localStorage.removeItem('admin_token');
    localStorage.removeItem('admin_user');
    localStorage.removeItem('tenant_token');
    setUser(null);
  };

  const isAdmin = () => user?.role === 'super_admin';
  const isTenant = () => user?.role === 'tenant';

  return (
    <AuthContext.Provider value={{ user, loading, login, logout, isAdmin, isTenant }}>
      {children}
    </AuthContext.Provider>
  );
}

export function useAuth() {
  const ctx = useContext(AuthContext);
  if (!ctx) throw new Error('useAuth must be used within AuthProvider');
  return ctx;
}
