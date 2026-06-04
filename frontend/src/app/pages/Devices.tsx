import { useEffect, useState } from 'react';
import { Activity, CheckCircle, Clock, Cpu, Database, Gauge, Globe, MapPin, RefreshCw, Server, Wifi, XCircle } from 'lucide-react';
import { useSite } from '../context/SiteContext';
import { API_BASE, getTelemetryUsage } from '../utils/api';

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

interface TelemetryRouter {
  id: number;
  name: string;
  location?: string | null;
  cpu_load: number;
  memory_used_mb: number;
  memory_total_mb: number;
  active_users: number;
  last_seen?: string | null;
  is_online: boolean;
  uptime_seconds: number;
  bandwidth_download_kbps: number;
  bandwidth_upload_kbps: number;
  total_tx_bytes: number;
  total_rx_bytes: number;
}

interface TelemetryStats {
  total_active_users: number;
  total_routers: number;
  online_routers: number;
  avg_cpu: number;
  avg_memory: number;
  bandwidth_download_kbps?: number;
  bandwidth_upload_kbps?: number;
  routers: TelemetryRouter[];
  resource_trend?: TrendSample[];
  timestamp?: string;
}

interface TrendSample {
  cpu: number;
  memory: number;
  download: number;
  upload: number;
}

interface UsageStats {
  period: 'today' | 'week' | 'month';
  download_bytes: number;
  upload_bytes: number;
  total_bytes: number;
  sample_count: number;
  wan_interfaces: string[];
}

export function Devices() {
  const { selectedSite } = useSite();
  const [router, setRouter] = useState<RouterRecord | null>(null);
  const [stats, setStats] = useState<TelemetryStats | null>(null);
  const [trend, setTrend] = useState<TrendSample[]>([]);
  const [usagePeriod, setUsagePeriod] = useState<'today' | 'week' | 'month'>('today');
  const [usage, setUsage] = useState<UsageStats | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');

  useEffect(() => {
    setTrend([]);
    loadData(true);
    const interval = setInterval(() => loadData(false), 15000);
    return () => clearInterval(interval);
  }, [selectedSite?.id, usagePeriod]);

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

  const loadData = async (showSpinner = false) => {
    if (!selectedSite?.id) {
      setRouter(null);
      setStats(null);
      setLoading(false);
      return;
    }

    if (showSpinner) setLoading(true);
    setError('');

    try {
      const [routerResponse, statsResponse] = await Promise.all([
        fetch(`${API_BASE}/routers`, { headers: getAuthHeaders(), credentials: 'include' }),
        fetch(`${API_BASE}/telemetry/stats`, { headers: getAuthHeaders(), credentials: 'include' }),
      ]);

      if (routerResponse.ok) {
        const data = await routerResponse.json();
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

      if (statsResponse.ok) {
        const nextStats = await statsResponse.json();
        setStats(nextStats);
        const liveRouter = (nextStats.routers || [])[0];
        if (Array.isArray(nextStats.resource_trend) && nextStats.resource_trend.length > 0) {
          setTrend(nextStats.resource_trend.slice(-12).map((sample: any) => ({
            cpu: Number(sample.cpu || 0),
            memory: Number(sample.memory || 0),
            download: Number(sample.download || 0),
            upload: Number(sample.upload || 0),
          })));
        }
        if (liveRouter) {
          const memory = liveRouter.memory_total_mb > 0
            ? (liveRouter.memory_used_mb / liveRouter.memory_total_mb) * 100
            : 0;
          if (!Array.isArray(nextStats.resource_trend) || nextStats.resource_trend.length === 0) {
            setTrend((items) => [
              ...items.slice(-11),
              {
                cpu: Number(liveRouter.cpu_load || 0),
                memory,
                download: Number(liveRouter.bandwidth_download_kbps || 0),
                upload: Number(liveRouter.bandwidth_upload_kbps || 0),
              },
            ]);
          }
        }
      } else {
        setError(`Telemetry returned ${statsResponse.status}.`);
      }

      try {
        const usageData = await getTelemetryUsage(usagePeriod);
        setUsage(usageData);
      } catch (usageError) {
        console.error('Failed to load router usage:', usageError);
      }
    } catch (err: any) {
      console.error('Failed to load router monitor:', err);
      setError(err.message || 'Failed to load router monitor.');
    } finally {
      setLoading(false);
    }
  };

  const liveRouter = stats?.routers?.[0] || null;
  const memoryPercent = liveRouter && liveRouter.memory_total_mb > 0
    ? (liveRouter.memory_used_mb / liveRouter.memory_total_mb) * 100
    : Number(stats?.avg_memory || 0);

  if (loading) {
    return (
      <div className="flex items-center justify-center h-64">
        <RefreshCw className="w-6 h-6 text-primary animate-spin" />
      </div>
    );
  }

  return (
    <div className="p-4 sm:p-6 lg:p-8 space-y-6">
      <div className="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
        <div>
          <h1 className="text-2xl sm:text-3xl text-foreground mb-1 flex items-center gap-2">
            <Server className="w-8 h-8 text-primary" />
            Monitor Router
          </h1>
          <p className="text-sm text-muted-foreground">Live MikroTik telemetry for {selectedSite?.name || 'the active site'}.</p>
        </div>
        <button onClick={() => loadData(true)} className="inline-flex items-center gap-2 px-4 py-2 border border-border rounded-lg hover:bg-muted">
          <RefreshCw className="w-4 h-4" />
          Refresh
        </button>
      </div>

      {error && <div className="rounded-lg border border-destructive/30 bg-destructive/10 p-3 text-sm text-destructive">{error}</div>}

      {!router && !liveRouter ? (
        <div className="bg-card border border-border rounded-lg p-12 text-center">
          <Server className="w-16 h-16 text-muted-foreground mx-auto mb-4" />
          <h3 className="text-lg font-semibold text-card-foreground mb-2">No router telemetry yet</h3>
          <p className="text-sm text-muted-foreground">Choose a site or provision its router to start receiving live stats.</p>
        </div>
      ) : (
        <>
          <div className="bg-card border border-border rounded-lg p-6">
            <div className="flex flex-col lg:flex-row lg:items-center lg:justify-between gap-5">
              <div className="flex items-center gap-4">
                <div className="w-14 h-14 bg-primary/10 rounded-lg flex items-center justify-center">
                  <Server className="w-7 h-7 text-primary" />
                </div>
                <div>
                  <div className="flex flex-wrap items-center gap-2">
                    <h2 className="text-lg font-semibold text-card-foreground">{liveRouter?.name || router?.name}</h2>
                    {getStatusBadge(liveRouter ? (liveRouter.is_online ? 'online' : 'offline') : router?.status || 'pending')}
                  </div>
                  <div className="flex flex-wrap items-center gap-3 mt-2 text-sm text-muted-foreground">
                    <span className="flex items-center gap-1 font-mono">
                      <Globe className="w-3 h-3" />
                      {router?.ip_address || 'VPN IP pending'}
                    </span>
                    {(router?.location || liveRouter?.location) && (
                      <span className="flex items-center gap-1">
                        <MapPin className="w-3 h-3" />
                        {router?.location || liveRouter?.location}
                      </span>
                    )}
                  </div>
                </div>
              </div>
              <div className="text-sm text-muted-foreground lg:text-right">
                <p>Last telemetry</p>
                <p className="font-medium text-card-foreground">{formatDate(liveRouter?.last_seen || router?.last_seen)}</p>
              </div>
            </div>

            {router?.needs_remote_access && (
              <div className="mt-6 rounded-lg border border-yellow-500/20 bg-yellow-500/10 p-4 text-sm text-yellow-600">
                The router record is ready, but the admin still needs to assign this site VPN details before live remote pulls can run.
              </div>
            )}
          </div>

          <div className="grid sm:grid-cols-2 xl:grid-cols-4 gap-4">
            <MetricCard icon={Cpu} label="CPU usage" value={`${Math.round(Number(liveRouter?.cpu_load || stats?.avg_cpu || 0))}%`} percent={Number(liveRouter?.cpu_load || stats?.avg_cpu || 0)} />
            <MetricCard icon={Database} label="Memory usage" value={`${Math.round(memoryPercent)}%`} percent={memoryPercent} detail={liveRouter ? `${formatMb(liveRouter.memory_used_mb)} / ${formatMb(liveRouter.memory_total_mb)}` : undefined} />
            <MetricCard icon={Wifi} label="Active users" value={String(liveRouter?.active_users ?? stats?.total_active_users ?? 0)} detail={`${stats?.online_routers || 0}/${stats?.total_routers || 0} routers online`} />
            <MetricCard icon={Gauge} label="Uptime" value={formatUptime(liveRouter?.uptime_seconds || 0)} detail="Router reported uptime" />
          </div>

          <div className="bg-card border border-border rounded-lg p-5">
            <div className="flex flex-col lg:flex-row lg:items-center lg:justify-between gap-4 mb-5">
              <div>
                <h2 className="font-semibold text-card-foreground">Total Data Usage</h2>
                <p className="text-sm text-muted-foreground">WAN traffic only. Uses MikroTik WAN interface list, with ether1 as fallback.</p>
              </div>
              <div className="inline-flex rounded-lg border border-border bg-muted/40 p-1">
                {[
                  ['today', 'Today'],
                  ['week', 'This Week'],
                  ['month', 'This Month'],
                ].map(([value, label]) => (
                  <button
                    key={value}
                    onClick={() => setUsagePeriod(value as UsageStats['period'])}
                    className={`px-3 py-1.5 text-sm rounded-md transition-colors ${
                      usagePeriod === value ? 'bg-primary text-primary-foreground' : 'text-muted-foreground hover:text-foreground'
                    }`}
                  >
                    {label}
                  </button>
                ))}
              </div>
            </div>

            <div className="grid sm:grid-cols-3 gap-4">
              <div className="rounded-lg bg-muted/50 p-4">
                <p className="text-sm text-muted-foreground">Total traffic</p>
                <p className="text-2xl font-semibold mt-1">{formatBytes(usage?.total_bytes || 0)}</p>
              </div>
              <div className="rounded-lg bg-muted/50 p-4">
                <p className="text-sm text-muted-foreground">Download</p>
                <p className="text-2xl font-semibold mt-1">{formatBytes(usage?.download_bytes || 0)}</p>
              </div>
              <div className="rounded-lg bg-muted/50 p-4">
                <p className="text-sm text-muted-foreground">Upload</p>
                <p className="text-2xl font-semibold mt-1">{formatBytes(usage?.upload_bytes || 0)}</p>
              </div>
            </div>
            <p className="text-xs text-muted-foreground mt-3">
              Counted interfaces: {usage?.wan_interfaces?.length ? usage.wan_interfaces.join(', ') : 'Waiting for WAN telemetry'}
            </p>
          </div>

          <div className="grid xl:grid-cols-[1.1fr_0.9fr] gap-4">
            <div className="bg-card border border-border rounded-lg p-5">
              <div className="flex items-center justify-between gap-3 mb-5">
                <div>
                  <h2 className="font-semibold text-card-foreground">Live Throughput</h2>
                  <p className="text-sm text-muted-foreground">Download and upload rates from recent telemetry samples.</p>
                </div>
                <Activity className="w-5 h-5 text-primary" />
              </div>
              <div className="grid sm:grid-cols-2 gap-4">
                <BandwidthPanel label="Download" value={liveRouter?.bandwidth_download_kbps || stats?.bandwidth_download_kbps || 0} samples={trend.map((item) => item.download)} />
                <BandwidthPanel label="Upload" value={liveRouter?.bandwidth_upload_kbps || stats?.bandwidth_upload_kbps || 0} samples={trend.map((item) => item.upload)} />
              </div>
            </div>

            <div className="bg-card border border-border rounded-lg p-5">
              <h2 className="font-semibold text-card-foreground mb-4">Resource Trend</h2>
              <TrendBars label="CPU" samples={trend.map((item) => item.cpu)} suffix="%" />
              <TrendBars label="Memory" samples={trend.map((item) => item.memory)} suffix="%" />
              <div className="grid grid-cols-2 gap-3 mt-5 text-sm">
                <div className="rounded-lg bg-muted/50 p-3">
                  <p className="text-muted-foreground">Total download</p>
                  <p className="font-semibold mt-1">{formatBytes(liveRouter?.total_rx_bytes || 0)}</p>
                </div>
                <div className="rounded-lg bg-muted/50 p-3">
                  <p className="text-muted-foreground">Total upload</p>
                  <p className="font-semibold mt-1">{formatBytes(liveRouter?.total_tx_bytes || 0)}</p>
                </div>
              </div>
            </div>
          </div>
        </>
      )}
    </div>
  );
}

function MetricCard({ icon: Icon, label, value, percent, detail }: { icon: any; label: string; value: string; percent?: number; detail?: string }) {
  const safePercent = Math.min(100, Math.max(0, Number(percent || 0)));
  return (
    <div className="bg-card border border-border rounded-lg p-5">
      <div className="flex items-center justify-between">
        <Icon className="w-6 h-6 text-primary" />
        {percent !== undefined && <span className="text-xs text-muted-foreground">{Math.round(safePercent)}%</span>}
      </div>
      <p className="text-sm text-muted-foreground mt-4">{label}</p>
      <p className="text-2xl font-semibold text-card-foreground mt-1">{value}</p>
      {percent !== undefined && (
        <div className="h-2 bg-muted rounded-full overflow-hidden mt-3">
          <div className="h-full bg-primary rounded-full transition-all" style={{ width: `${safePercent}%` }} />
        </div>
      )}
      {detail && <p className="text-xs text-muted-foreground mt-2">{detail}</p>}
    </div>
  );
}

function BandwidthPanel({ label, value, samples }: { label: string; value: number; samples: number[] }) {
  return (
    <div className="rounded-lg bg-muted/50 p-4">
      <p className="text-sm text-muted-foreground">{label}</p>
      <p className="text-2xl font-semibold mt-1">{formatKbps(value)}</p>
      <TrendBars label="" samples={samples} suffix="" compact />
    </div>
  );
}

function TrendBars({ label, samples, suffix, compact = false }: { label: string; samples: number[]; suffix: string; compact?: boolean }) {
  const max = Math.max(...samples, 1);
  const padded = samples.length ? samples : [0, 0, 0, 0, 0, 0];

  return (
    <div className={compact ? 'mt-4' : 'mt-4'}>
      {label && (
        <div className="flex items-center justify-between text-sm mb-2">
          <span className="text-muted-foreground">{label}</span>
          <span className="font-medium">{Math.round(padded[padded.length - 1] || 0)}{suffix}</span>
        </div>
      )}
      <div className="h-20 flex items-end gap-1">
        {padded.map((sample, index) => (
          <div key={`${sample}-${index}`} className="flex-1 bg-primary/20 rounded-t-sm overflow-hidden">
            <div
              className="w-full bg-primary rounded-t-sm transition-all"
              style={{ height: `${Math.max(6, Math.min(100, (sample / max) * 100))}%` }}
            />
          </div>
        ))}
      </div>
    </div>
  );
}

function getStatusBadge(status: RouterRecord['status']) {
  if (status === 'online') {
    return <span className="flex items-center gap-1 text-xs px-2 py-1 rounded-full bg-emerald-500/10 text-emerald-500"><CheckCircle className="w-3 h-3" /> Online</span>;
  }
  if (status === 'offline') {
    return <span className="flex items-center gap-1 text-xs px-2 py-1 rounded-full bg-red-500/10 text-red-500"><XCircle className="w-3 h-3" /> Offline</span>;
  }
  return <span className="flex items-center gap-1 text-xs px-2 py-1 rounded-full bg-yellow-500/10 text-yellow-500"><Clock className="w-3 h-3" /> Pending setup</span>;
}

function formatDate(value?: string | null) {
  return value ? new Date(value).toLocaleString() : 'Waiting for telemetry';
}

function formatMb(value: number) {
  return `${Number(value || 0).toLocaleString()} MB`;
}

function formatBytes(value: number) {
  const bytes = Number(value || 0);
  if (bytes >= 1073741824) return `${(bytes / 1073741824).toFixed(2)} GB`;
  if (bytes >= 1048576) return `${(bytes / 1048576).toFixed(2)} MB`;
  if (bytes >= 1024) return `${(bytes / 1024).toFixed(2)} KB`;
  return `${bytes} B`;
}

function formatKbps(value: number) {
  const kbps = Number(value || 0);
  return kbps >= 1024 ? `${(kbps / 1024).toFixed(2)} Mbps` : `${kbps.toFixed(2)} Kbps`;
}

function formatUptime(seconds: number) {
  const total = Number(seconds || 0);
  if (!total) return 'Unknown';
  const days = Math.floor(total / 86400);
  const hours = Math.floor((total % 86400) / 3600);
  const minutes = Math.floor((total % 3600) / 60);
  if (days > 0) return `${days}d ${hours}h`;
  if (hours > 0) return `${hours}h ${minutes}m`;
  return `${minutes}m`;
}
