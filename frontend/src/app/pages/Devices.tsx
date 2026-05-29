import { useEffect, useState } from 'react';
import { CheckCircle, Clock, Globe, MapPin, RefreshCw, Server, XCircle } from 'lucide-react';
import { useSite } from '../context/SiteContext';
import { API_BASE } from '../utils/api';

interface RouterRecord {
  id: number | null;
  name: string;
  ip_address: string | null;
  location: string | null;
  is_active: boolean;
  last_seen?: string | null;
  managed_by_site?: boolean;
  needs_remote_access?: boolean;
  status: 'online' | 'offline' | 'pending';
}

export function Devices() {
  const { selectedSite } = useSite();
  const [router, setRouter] = useState<RouterRecord | null>(null);
  const [loading, setLoading] = useState(true);

  useEffect(() => {
    loadData();
    const interval = setInterval(loadData, 30000);
    return () => clearInterval(interval);
  }, [selectedSite?.id]);

  const getAuthHeaders = (): HeadersInit => {
    const token = localStorage.getItem('tenant_token');
    const headers: HeadersInit = {
      'Content-Type': 'application/json',
      Accept: 'application/json',
    };
    if (token) headers.Authorization = `Bearer ${token}`;
    if (selectedSite?.id) headers['X-Site-ID'] = String(selectedSite.id);
    return headers;
  };

  const loadData = async () => {
    if (!selectedSite?.id) {
      setRouter(null);
      setLoading(false);
      return;
    }

    try {
      const response = await fetch(`${API_BASE}/routers`, { headers: getAuthHeaders() });
      if (response.ok) {
        const data = await response.json();
        const record = (Array.isArray(data) ? data : data.data || [])[0] || null;
        setRouter(record ? {
          ...record,
          status: record.needs_remote_access
            ? 'pending'
            : record.last_seen && Date.now() - new Date(record.last_seen).getTime() < 600000
              ? 'online'
              : record.last_seen ? 'offline' : 'pending',
        } : null);
      }
    } catch (error) {
      console.error('Failed to load site router:', error);
    } finally {
      setLoading(false);
    }
  };

  const getStatusBadge = (status: RouterRecord['status']) => {
    if (status === 'online') {
      return <span className="flex items-center gap-1 text-xs px-2 py-1 rounded-full bg-emerald-500/10 text-emerald-500"><CheckCircle className="w-3 h-3" /> Online</span>;
    }
    if (status === 'offline') {
      return <span className="flex items-center gap-1 text-xs px-2 py-1 rounded-full bg-red-500/10 text-red-500"><XCircle className="w-3 h-3" /> Offline</span>;
    }
    return <span className="flex items-center gap-1 text-xs px-2 py-1 rounded-full bg-yellow-500/10 text-yellow-500"><Clock className="w-3 h-3" /> Pending setup</span>;
  };

  if (loading) {
    return (
      <div className="flex items-center justify-center h-64">
        <RefreshCw className="w-6 h-6 text-primary animate-spin" />
      </div>
    );
  }

  return (
    <div className="p-4 sm:p-6 lg:p-8">
      <div className="mb-6 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
        <div>
          <h1 className="text-2xl sm:text-3xl text-foreground mb-1 flex items-center gap-2">
            <Server className="w-8 h-8 text-primary" />
            Network Devices
          </h1>
          <p className="text-sm text-muted-foreground">Each site has one managed MikroTik device named after the site.</p>
        </div>
        <button onClick={loadData} className="inline-flex items-center gap-2 px-4 py-2 border border-border rounded-lg hover:bg-muted">
          <RefreshCw className="w-4 h-4" />
          Refresh
        </button>
      </div>

      {!router ? (
        <div className="bg-card border border-border rounded-lg p-12 text-center">
          <Server className="w-16 h-16 text-muted-foreground mx-auto mb-4" />
          <h3 className="text-lg font-semibold text-card-foreground mb-2">No site selected</h3>
          <p className="text-sm text-muted-foreground">Choose a site from the sidebar to view its router.</p>
        </div>
      ) : (
        <div className="bg-card border border-border rounded-lg p-6">
          <div className="flex flex-col lg:flex-row lg:items-center lg:justify-between gap-5">
            <div className="flex items-center gap-4">
              <div className="w-14 h-14 bg-primary/10 rounded-lg flex items-center justify-center">
                <Server className="w-7 h-7 text-primary" />
              </div>
              <div>
                <div className="flex flex-wrap items-center gap-2">
                  <h2 className="text-lg font-semibold text-card-foreground">{router.name}</h2>
                  {getStatusBadge(router.status)}
                </div>
                <div className="flex flex-wrap items-center gap-3 mt-2 text-sm text-muted-foreground">
                  <span className="flex items-center gap-1 font-mono">
                    <Globe className="w-3 h-3" />
                    {router.ip_address || 'VPN IP pending'}
                  </span>
                  {router.location && (
                    <span className="flex items-center gap-1">
                      <MapPin className="w-3 h-3" />
                      {router.location}
                    </span>
                  )}
                </div>
              </div>
            </div>
            <div className="text-sm text-muted-foreground lg:text-right">
              <p>Last seen</p>
              <p className="font-medium text-card-foreground">{router.last_seen ? new Date(router.last_seen).toLocaleString() : 'Waiting for telemetry'}</p>
            </div>
          </div>

          {router.needs_remote_access && (
            <div className="mt-6 rounded-lg border border-yellow-500/20 bg-yellow-500/10 p-4 text-sm text-yellow-600">
              The router record is ready, but the admin still needs to assign this site VPN details before live remote pulls can run.
            </div>
          )}
        </div>
      )}
    </div>
  );
}
