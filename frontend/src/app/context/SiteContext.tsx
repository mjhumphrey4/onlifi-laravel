import { createContext, useContext, useState, useEffect, ReactNode } from 'react';

export interface Site {
  id: number;
  name: string;
  slug: string;
  description?: string;
  is_active: boolean;
  api_token?: string;
}

interface SiteContextType {
  sites: Site[];
  selectedSite: Site | null;
  setSelectedSite: (site: Site | null) => void;
  loading: boolean;
  refreshSites: () => Promise<void>;
}

const SiteContext = createContext<SiteContextType | null>(null);

export function SiteProvider({ children }: { children: ReactNode }) {
  const [sites, setSites] = useState<Site[]>([]);
  const [selectedSite, setSelectedSite] = useState<Site | null>(null);
  const [loading, setLoading] = useState(true);

  useEffect(() => {
    loadSites();
  }, []);

  const getAuthHeaders = (): HeadersInit => {
    const token = localStorage.getItem('tenant_token') || localStorage.getItem('admin_token');
    const headers: HeadersInit = {
      'Content-Type': 'application/json',
      'Accept': 'application/json',
    };
    if (token) headers['Authorization'] = `Bearer ${token}`;
    return headers;
  };

  const loadSites = async () => {
    try {
      setLoading(true);
      const response = await fetch('/api/sites', { headers: getAuthHeaders() });
      if (response.ok) {
        const data = await response.json();
        const siteList = data.sites || [];
        setSites(siteList);
        
        // Auto-select first site if none selected
        if (siteList.length > 0 && !selectedSite) {
          setSelectedSite(siteList[0]);
        }
      }
    } catch (error) {
      console.error('Failed to load sites:', error);
    } finally {
      setLoading(false);
    }
  };

  const refreshSites = async () => {
    await loadSites();
  };

  return (
    <SiteContext.Provider value={{ sites, selectedSite, setSelectedSite, loading, refreshSites }}>
      {children}
    </SiteContext.Provider>
  );
}

export function useSite() {
  const ctx = useContext(SiteContext);
  if (!ctx) throw new Error('useSite must be used within SiteProvider');
  return ctx;
}
