import { useState, useEffect } from 'react';
import { Users, RefreshCw, Wifi, HardDrive, Clock, TrendingUp, TrendingDown, Activity } from 'lucide-react';
import { useSite } from '../context/SiteContext';
import { collectRouterTelemetry, getRouters } from '../utils/api';

interface Client {
  id: number;
  mac_address: string;
  ip_address: string;
  username: string | null;
  device_type: string | null;
  uptime_seconds: number;
  data_uploaded_mb: number;
  data_downloaded_mb: number;
  total_data_mb: number;
  signal_strength: number | null;
  last_seen: string;
  router_name: string;
  voucher_code: string | null;
  profile_name: string | null;
  expires_at: string | null;
}

export function Clients() {
  const { selectedSite } = useSite();
  const [clients, setClients] = useState<Client[]>([]);
  const [routers, setRouters] = useState<any[]>([]);
  const [loading, setLoading] = useState(true);
  const [refreshing, setRefreshing] = useState(false);
  const [pullingTelemetry, setPullingTelemetry] = useState(false);
  const [lastUpdated, setLastUpdated] = useState<Date | null>(null);
  const [telemetryMessage, setTelemetryMessage] = useState('');

  const getAuthHeaders = (): HeadersInit => {
    const token = localStorage.getItem('tenant_token') || localStorage.getItem('admin_token');
    const headers: HeadersInit = {
      'Content-Type': 'application/json',
      'Accept': 'application/json',
    };
    if (token) headers['Authorization'] = `Bearer ${token}`;
    const siteId = localStorage.getItem('selected_site_id');
    if (siteId) headers['X-Site-ID'] = siteId;
    return headers;
  };

  const loadClients = async (refresh = false) => {
    try {
      if (refresh) setRefreshing(true);
      else setLoading(true);

      const headers = getAuthHeaders();
      const response = await fetch('/api/clients', { headers });
      
      if (response.ok) {
        const data = await response.json();
        setClients(data.clients || data.data || []);
        setLastUpdated(new Date());
      }
    } catch (error) {
      console.error('Failed to load clients:', error);
    } finally {
      setLoading(false);
      setRefreshing(false);
    }
  };

  const loadRouters = async () => {
    try {
      const response = await getRouters();
      setRouters(Array.isArray(response) ? response : response.routers || response.data || []);
    } catch (error) {
      console.error('Failed to load routers:', error);
    }
  };

  const pullTelemetry = async () => {
    if (routers.length === 0) {
      setTelemetryMessage('No router is registered for this site yet.');
      return;
    }

    setPullingTelemetry(true);
    setTelemetryMessage('');
    try {
      const results = await Promise.allSettled(routers.map((router) => collectRouterTelemetry(router.id)));
      const successCount = results.filter((result) => result.status === 'fulfilled').length;
      setTelemetryMessage(`Telemetry pulled from ${successCount} of ${routers.length} router${routers.length === 1 ? '' : 's'}.`);
      await loadClients(true);
    } finally {
      setPullingTelemetry(false);
    }
  };

  useEffect(() => {
    loadClients();
    loadRouters();
    const interval = setInterval(() => loadClients(), 30000); // Auto-refresh every 30s
    return () => clearInterval(interval);
  }, [selectedSite?.id]);

  const formatUptime = (seconds: number) => {
    const hours = Math.floor(seconds / 3600);
    const minutes = Math.floor((seconds % 3600) / 60);
    if (hours > 0) return `${hours}h ${minutes}m`;
    return `${minutes}m`;
  };

  const formatBytes = (mb: number) => {
    if (mb >= 1024) return `${(mb / 1024).toFixed(2)} GB`;
    return `${mb.toFixed(2)} MB`;
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
      {/* Header */}
      <div className="mb-6 sm:mb-8 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
        <div>
          <h1 className="text-2xl sm:text-3xl text-foreground mb-1 flex items-center gap-2">
            <Users className="w-8 h-8 text-primary" />
            Active Clients
          </h1>
          <p className="text-sm text-muted-foreground">
            Real-time monitoring of connected devices on your MikroTik network
          </p>
        </div>
        <div className="flex items-center gap-3">
          <button
            onClick={pullTelemetry}
            disabled={pullingTelemetry}
            className="flex items-center gap-2 px-4 py-2 border border-border text-foreground rounded-lg hover:bg-muted transition-colors disabled:opacity-50"
          >
            <Activity className={`w-4 h-4 ${pullingTelemetry ? 'animate-pulse' : ''}`} />
            {pullingTelemetry ? 'Pulling...' : 'Pull Router Telemetry'}
          </button>
          <button
            onClick={() => loadClients(true)}
            disabled={refreshing}
            className="flex items-center gap-2 px-4 py-2 bg-primary text-primary-foreground rounded-lg hover:bg-primary/90 transition-colors disabled:opacity-50"
          >
            <RefreshCw className={`w-4 h-4 ${refreshing ? 'animate-spin' : ''}`} />
            {refreshing ? 'Refreshing...' : 'Refresh'}
          </button>
          {lastUpdated && (
            <span className="text-xs text-muted-foreground">
              Updated {lastUpdated.toLocaleTimeString()}
            </span>
          )}
        </div>
      </div>

      {telemetryMessage && (
        <div className="mb-4 rounded-lg border border-border bg-card p-3 text-sm text-card-foreground">
          {telemetryMessage}
        </div>
      )}

      {/* Stats Cards */}
      <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
        <div className="bg-card border border-border rounded-lg p-4">
          <div className="flex items-center justify-between">
            <div>
              <p className="text-sm text-muted-foreground">Total Clients</p>
              <p className="text-2xl font-bold text-card-foreground">{clients.length}</p>
            </div>
            <Wifi className="w-8 h-8 text-primary" />
          </div>
        </div>

        <div className="bg-card border border-border rounded-lg p-4">
          <div className="flex items-center justify-between">
            <div>
              <p className="text-sm text-muted-foreground">With Vouchers</p>
              <p className="text-2xl font-bold text-card-foreground">
                {clients.filter(c => c.voucher_code).length}
              </p>
            </div>
            <HardDrive className="w-8 h-8 text-emerald-500" />
          </div>
        </div>

        <div className="bg-card border border-border rounded-lg p-4">
          <div className="flex items-center justify-between">
            <div>
              <p className="text-sm text-muted-foreground">Total Upload</p>
              <p className="text-2xl font-bold text-card-foreground">
                {formatBytes(clients.reduce((sum, c) => sum + c.data_uploaded_mb, 0))}
              </p>
            </div>
            <TrendingUp className="w-8 h-8 text-blue-500" />
          </div>
        </div>

        <div className="bg-card border border-border rounded-lg p-4">
          <div className="flex items-center justify-between">
            <div>
              <p className="text-sm text-muted-foreground">Total Download</p>
              <p className="text-2xl font-bold text-card-foreground">
                {formatBytes(clients.reduce((sum, c) => sum + c.data_downloaded_mb, 0))}
              </p>
            </div>
            <TrendingDown className="w-8 h-8 text-purple-500" />
          </div>
        </div>
      </div>

      {/* Clients Table */}
      <div className="bg-card border border-border rounded-lg overflow-hidden">
        <div className="overflow-x-auto">
          <table className="w-full">
            <thead className="bg-muted/50 border-b border-border">
              <tr>
                <th className="text-left py-3 px-4 text-xs font-semibold text-muted-foreground uppercase">Device</th>
                <th className="text-left py-3 px-4 text-xs font-semibold text-muted-foreground uppercase">IP Address</th>
                <th className="text-left py-3 px-4 text-xs font-semibold text-muted-foreground uppercase">MAC Address</th>
                <th className="text-left py-3 px-4 text-xs font-semibold text-muted-foreground uppercase">Voucher</th>
                <th className="text-left py-3 px-4 text-xs font-semibold text-muted-foreground uppercase">Uptime</th>
                <th className="text-left py-3 px-4 text-xs font-semibold text-muted-foreground uppercase">Upload</th>
                <th className="text-left py-3 px-4 text-xs font-semibold text-muted-foreground uppercase">Download</th>
                <th className="text-left py-3 px-4 text-xs font-semibold text-muted-foreground uppercase">Router</th>
              </tr>
            </thead>
            <tbody>
              {clients.length === 0 ? (
                <tr>
                  <td colSpan={8} className="py-8 text-center text-muted-foreground">
                    No active clients found
                  </td>
                </tr>
              ) : (
                clients.map((client) => (
                  <tr key={client.id} className="border-b border-border/50 hover:bg-muted/30 transition-colors">
                    <td className="py-3 px-4">
                      <div className="flex items-center gap-2">
                        <div className="w-8 h-8 bg-primary/10 rounded-full flex items-center justify-center">
                          <Wifi className="w-4 h-4 text-primary" />
                        </div>
                        <div>
                          <p className="text-sm font-medium text-card-foreground">
                            {client.device_type || 'Unknown Device'}
                          </p>
                          {client.username && (
                            <p className="text-xs text-muted-foreground">{client.username}</p>
                          )}
                        </div>
                      </div>
                    </td>
                    <td className="py-3 px-4 text-sm text-card-foreground font-mono">
                      {client.ip_address}
                    </td>
                    <td className="py-3 px-4 text-xs text-muted-foreground font-mono">
                      {client.mac_address}
                    </td>
                    <td className="py-3 px-4">
                      {client.voucher_code ? (
                        <div>
                          <p className="text-sm font-medium text-primary">{client.voucher_code}</p>
                          {client.profile_name && (
                            <p className="text-xs text-muted-foreground">{client.profile_name}</p>
                          )}
                        </div>
                      ) : (
                        <span className="text-xs text-muted-foreground">—</span>
                      )}
                    </td>
                    <td className="py-3 px-4">
                      <div className="flex items-center gap-1 text-sm text-card-foreground">
                        <Clock className="w-3 h-3 text-muted-foreground" />
                        {formatUptime(client.uptime_seconds)}
                      </div>
                    </td>
                    <td className="py-3 px-4 text-sm text-card-foreground">
                      <div className="flex items-center gap-1">
                        <TrendingUp className="w-3 h-3 text-blue-500" />
                        {formatBytes(client.data_uploaded_mb)}
                      </div>
                    </td>
                    <td className="py-3 px-4 text-sm text-card-foreground">
                      <div className="flex items-center gap-1">
                        <TrendingDown className="w-3 h-3 text-purple-500" />
                        {formatBytes(client.data_downloaded_mb)}
                      </div>
                    </td>
                    <td className="py-3 px-4 text-sm text-muted-foreground">
                      {client.router_name}
                    </td>
                  </tr>
                ))
              )}
            </tbody>
          </table>
        </div>
      </div>
    </div>
  );
}
