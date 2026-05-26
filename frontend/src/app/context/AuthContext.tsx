import { createContext, useContext, useState, useEffect, ReactNode } from 'react';
import { adminMe, adminLogin, adminLogout, tenantLogin, tenantLogout, tenantMe } from '../utils/api';

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
  login: (email: string, password: string, twoFactorCode?: string, twoFactorToken?: string) => Promise<any>;
  loginAsTenant: (email: string, password: string, twoFactorCode?: string, twoFactorToken?: string) => Promise<any>;
  logout: () => Promise<void>;
  isAdmin: () => boolean;
  isTenant: () => boolean;
  userSites: () => string[];
}

const AuthContext = createContext<AuthContextType | null>(null);

export function AuthProvider({ children }: { children: ReactNode }) {
  const [user, setUser] = useState<AuthUser | null>(null);
  const [loading, setLoading] = useState(true);

  useEffect(() => {
    checkAuth();
  }, []);

  const checkAuth = async () => {
    const adminToken = localStorage.getItem('admin_token');
    const tenantToken = localStorage.getItem('tenant_token');
    
    if (!adminToken && !tenantToken) {
      setUser(null);
      setLoading(false);
      return;
    }

    try {
      // Try admin auth first
      if (adminToken) {
        const data = await adminMe();
        setUser({
          id: data.admin.id,
          username: data.admin.email,
          name: data.admin.name,
          email: data.admin.email,
          role: 'super_admin',
        });
      } else if (tenantToken) {
        // Try tenant auth
        const data = await tenantMe();
        setUser({
          id: data.user.id,
          username: data.user.email,
          name: data.user.name,
          email: data.user.email,
          role: 'tenant',
          tenant_id: data.user.tenant_id,
          tenant_name: data.user.tenant_name,
        });
      }
    } catch (error) {
      // Token invalid, clear it
      localStorage.removeItem('admin_token');
      localStorage.removeItem('tenant_token');
      setUser(null);
    } finally {
      setLoading(false);
    }
  };

  const login = async (email: string, password: string, twoFactorCode?: string, twoFactorToken?: string) => {
    // Try tenant login first (most common use case)
    try {
      const data = await tenantLogin(email, password, twoFactorCode, twoFactorToken);
      if (data.requires_2fa) return data;
      localStorage.setItem('tenant_token', data.token);
      localStorage.setItem('tenant_user', JSON.stringify(data.user));
      
      setUser({
        id: data.user.id,
        username: data.user.email,
        name: data.user.name,
        email: data.user.email,
        role: 'tenant',
        tenant_id: data.user.tenant_id,
        tenant_name: data.user.tenant_name,
      });
    } catch (tenantError) {
      // If tenant login fails, try admin login
      try {
        const data = await adminLogin(email, password, twoFactorCode, twoFactorToken);
        if (data.requires_2fa) return data;
        localStorage.setItem('admin_token', data.token);
        localStorage.setItem('admin_user', JSON.stringify(data.admin));

        setUser({
          id: data.admin.id,
          username: data.admin.email,
          name: data.admin.name,
          email: data.admin.email,
          role: 'super_admin',
        });
      } catch (adminError) {
        // Both failed, throw the tenant error (more relevant for main login)
        throw tenantError;
      }
    }
  };

  const loginAsTenant = async (email: string, password: string, twoFactorCode?: string, twoFactorToken?: string) => {
    const data = await tenantLogin(email, password, twoFactorCode, twoFactorToken);
    if (data.requires_2fa) return data;
    localStorage.setItem('tenant_token', data.token);
    localStorage.setItem('tenant_user', JSON.stringify(data.user));
    
    setUser({
      id: data.user.id,
      username: data.user.email,
      name: data.user.name,
      email: data.user.email,
      role: 'tenant',
      tenant_id: data.user.tenant_id,
      tenant_name: data.user.tenant_name,
    });
  };

  const logout = async () => {
    try {
      if (localStorage.getItem('admin_token')) {
        await adminLogout();
      } else if (localStorage.getItem('tenant_token')) {
        await tenantLogout();
      }
    } catch (error) {
      // Ignore logout errors
    }
    localStorage.removeItem('admin_token');
    localStorage.removeItem('admin_user');
    localStorage.removeItem('tenant_token');
    localStorage.removeItem('tenant_user');
    setUser(null);
  };

  const isAdmin = () => user?.role === 'super_admin';
  const isTenant = () => user?.role === 'tenant';
  const userSites = (): string[] => {
    // For super_admin, return all sites or a default list
    // For tenant users, return their assigned sites
    if (user?.role === 'super_admin') {
      return ['Default Site'];
    }
    return user?.tenant_name ? [user.tenant_name] : ['Default Site'];
  };

  return (
    <AuthContext.Provider value={{ user, loading, login, loginAsTenant, logout, isAdmin, isTenant, userSites }}>
      {children}
    </AuthContext.Provider>
  );
}

export function useAuth() {
  const ctx = useContext(AuthContext);
  if (!ctx) throw new Error('useAuth must be used within AuthProvider');
  return ctx;
}
