import { createContext, ReactNode, useCallback, useContext, useEffect, useMemo, useRef, useState } from 'react';
import { useAuth } from './AuthContext';

export interface Site {
  id: number;
  tenant_id?: number;
  name: string;
  slug: string;
  description?: string | null;
  is_active?: boolean;
  api_token?: string;
}

interface SiteContextType {
  sites: Site[];
  selectedSite: Site | null;
  setSelectedSite: (site: Site | null) => void;
  refreshSites: () => Promise<void>;
  loadingSites: boolean;
}

const SiteContext = createContext<SiteContextType | null>(null);

function getAuthHeaders(): Record<string, string> {
  const token = localStorage.getItem('tenant_token') || localStorage.getItem('admin_token');
  const headers: Record<string, string> = {
    Accept: 'application/json',
    'Content-Type': 'application/json',
  };

  if (token) {
    headers.Authorization = `Bearer ${token}`;
  }

  return headers;
}

export function SiteProvider({ children }: { children: ReactNode }) {
  const { user, loading } = useAuth();
  const [sites, setSites] = useState<Site[]>([]);
  const [selectedSite, setSelectedSiteState] = useState<Site | null>(null);
  const [loadingSites, setLoadingSites] = useState(false);
  const selectedSiteIdRef = useRef<number | null>(null);

  const applySelectedSite = useCallback((site: Site | null, notify = true) => {
    const previousId = selectedSiteIdRef.current;
    const nextId = site?.id ?? null;

    selectedSiteIdRef.current = nextId;
    setSelectedSiteState(site);

    if (site) {
      localStorage.setItem('selected_site_id', String(site.id));
    } else {
      localStorage.removeItem('selected_site_id');
    }

    if (notify && previousId !== nextId) {
      window.dispatchEvent(new CustomEvent('onlifi:site-changed', { detail: { siteId: nextId } }));
    }
  }, []);

  const setSelectedSite = useCallback((site: Site | null) => {
    applySelectedSite(site, true);
  }, [applySelectedSite]);

  const refreshSites = useCallback(async () => {
    const hasTenantToken = Boolean(localStorage.getItem('tenant_token'));

    if (!user || !hasTenantToken) {
      setSites([]);
      applySelectedSite(null, true);
      return;
    }

    setLoadingSites(true);

    try {
      const response = await fetch('/api/sites', {
        headers: getAuthHeaders(),
      });

      if (!response.ok) {
        setSites([]);
        applySelectedSite(null, true);
        return;
      }

      const data = await response.json();
      const nextSites: Site[] = data.sites || [];
      const storedSiteId = Number(localStorage.getItem('selected_site_id'));
      const currentSiteId = selectedSiteIdRef.current;
      const nextSelected =
        nextSites.find((site) => site.id === currentSiteId) ||
        nextSites.find((site) => site.id === storedSiteId) ||
        nextSites[0] ||
        null;

      setSites(nextSites);
      applySelectedSite(nextSelected, selectedSiteIdRef.current !== (nextSelected?.id ?? null));
    } catch (error) {
      console.error('Failed to load sites:', error);
      setSites([]);
      applySelectedSite(null, true);
    } finally {
      setLoadingSites(false);
    }
  }, [applySelectedSite, user]);

  useEffect(() => {
    if (loading) return;
    refreshSites();
  }, [loading, refreshSites]);

  const value = useMemo(
    () => ({
      sites,
      selectedSite,
      setSelectedSite,
      refreshSites,
      loadingSites,
    }),
    [loadingSites, refreshSites, selectedSite, setSelectedSite, sites],
  );

  return <SiteContext.Provider value={value}>{children}</SiteContext.Provider>;
}

export function useSite() {
  const ctx = useContext(SiteContext);
  if (!ctx) throw new Error('useSite must be used within SiteProvider');
  return ctx;
}
