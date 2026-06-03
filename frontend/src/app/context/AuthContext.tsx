import { createContext, useContext, useState, useEffect, ReactNode } from 'react';
import { adminMe, adminLogin, adminLogout, tenantLogin, tenantLogout, tenantMe } from '../utils/api';

export interface AuthUser {
  id: number;
  username: string;
  name: string;
  email: string;
  role: 'super_admin' | 'tenant' | 'sub_user' | 'user';
  tenant_id?: number;
  tenant_name?: string;
  permissions?: string[];
  allowed_site_ids?: number[];
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
      // Prefer the tenant token when both tokens exist so tenant dashboards keep tenant context.
      if (tenantToken) {
        const data = await tenantMe();
        setUser({
          id: data.user.id,
          username: data.user.email,
          name: data.user.name,
          email: data.user.email,
          role: data.user.role === 'sub_user' ? 'sub_user' : 'tenant',
          tenant_id: data.user.tenant_id,
          tenant_name: data.user.tenant_name,
          permissions: data.user.permissions || [],
          allowed_site_ids: data.user.allowed_site_ids || [],
        });
      } else if (adminToken) {
        const data = await adminMe();
        setUser({
          id: data.admin.id,
          username: data.admin.email,
          name: data.admin.name,
          email: data.admin.email,
          role: 'super_admin',
        });
      }
    } catch (error) {
      // Token invalid, clear it
      localStorage.removeItem('admin_token');
      localStorage.removeItem('tenant_token');
      localStorage.removeItem('selected_site_id');
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
      localStorage.removeItem('admin_token');
      localStorage.removeItem('admin_user');
      localStorage.removeItem('selected_site_id');
      localStorage.setItem('tenant_token', data.token);
      localStorage.setItem('tenant_user', JSON.stringify(data.user));
      
      setUser({
        id: data.user.id,
        username: data.user.email,
        name: data.user.name,
        email: data.user.email,
        role: 'tenant',
        ...(data.user.role === 'sub_user' ? { role: 'sub_user' as const } : {}),
        tenant_id: data.user.tenant_id,
        tenant_name: data.user.tenant_name,
        permissions: data.user.permissions || [],
        allowed_site_ids: data.user.allowed_site_ids || [],
      });
    } catch (tenantError) {
      // If tenant login fails, try admin login
      try {
        const data = await adminLogin(email, password, twoFactorCode, twoFactorToken);
        if (data.requires_2fa) return data;
        localStorage.removeItem('tenant_token');
        localStorage.removeItem('tenant_user');
        localStorage.removeItem('selected_site_id');
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
        const status = adminError instanceof Error
          ? (adminError as Error & { status?: number }).status
          : undefined;

        if (status && status >= 500) {
          throw adminError;
        }

        // Both credential checks failed, so show the tenant-facing credential message.
        throw tenantError;
      }
    }
  };

  const loginAsTenant = async (email: string, password: string, twoFactorCode?: string, twoFactorToken?: string) => {
    const data = await tenantLogin(email, password, twoFactorCode, twoFactorToken);
    if (data.requires_2fa) return data;
    localStorage.removeItem('admin_token');
    localStorage.removeItem('admin_user');
    localStorage.removeItem('selected_site_id');
    localStorage.setItem('tenant_token', data.token);
    localStorage.setItem('tenant_user', JSON.stringify(data.user));
    
    setUser({
      id: data.user.id,
      username: data.user.email,
      name: data.user.name,
      email: data.user.email,
      role: 'tenant',
      ...(data.user.role === 'sub_user' ? { role: 'sub_user' as const } : {}),
      tenant_id: data.user.tenant_id,
      tenant_name: data.user.tenant_name,
      permissions: data.user.permissions || [],
      allowed_site_ids: data.user.allowed_site_ids || [],
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
    localStorage.removeItem('selected_site_id');
    setUser(null);
  };

  const isAdmin = () => user?.role === 'super_admin';
  const isTenant = () => user?.role === 'tenant' || user?.role === 'sub_user';
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
