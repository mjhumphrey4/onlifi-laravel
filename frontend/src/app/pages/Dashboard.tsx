import { useState, useEffect, useCallback } from 'react';
import { DollarSign, TrendingUp, RefreshCw, Users, ArrowRight, Server, Activity, Cpu, HardDrive, ArrowUp, ArrowDown, CalendarDays } from 'lucide-react';
import { Link } from 'react-router';
import { StatsCard } from '../components/StatsCard';
import { useAuth } from '../context/AuthContext';
import { useSite } from '../context/SiteContext';
import { apiStats, getTelemetryStats } from '../utils/api';

interface SiteStat {
  total_amount: number;
  today_amount: number;
  week_amount: number;
  withdrawn: number;
  pending_withdraw: number;
  balance: number;
  total_sales: number;
}

interface TxRow {
  id: string;
  msisdn: string;
  amount: string;
  status: string;
  created_at: string;
  origin_site: string;
  voucher_code: string;
  external_ref: string;
}

interface DeviceStats {
  total_routers: number;
  online_routers: number;
  total_clients: number;
  active_connections: number;
  avg_cpu: number;
  avg_memory: number;
  bandwidth_up: number;
  bandwidth_down: number;
}

interface Client {
  id: number;
  mac_address: string;
  username: string;
  ip_address: string;
  total_sessions: number;
  total_spent: number;
  last_seen: string;
  status: string;
}

type DateFilter = 'today' | 'yesterday' | 'week' | 'month' | 'all';

const DATE_FILTERS: { id: DateFilter; label: string }[] = [
  { id: 'today', label: 'Today' },
  { id: 'yesterday', label: 'Yesterday' },
  { id: 'week', label: 'This Week' },
  { id: 'month', label: 'This Month' },
  { id: 'all', label: 'All' },
];

const SITE_COLORS: Record<string, string> = {
  Enock:   'from-blue-600 to-blue-700',
  Richard: 'from-emerald-600 to-emerald-700',
  STK:     'from-purple-600 to-purple-700',
  Remmy:   'from-orange-500 to-orange-600',
  Guma:    'from-teal-600 to-teal-700',
};

function fmt(n: number) {
  return 'UGX ' + Math.round(n).toLocaleString();
}

function statusStyle(s: string) {
  switch (s.toLowerCase()) {
    case 'success': return 'bg-primary/10 text-primary';
    case 'pending': return 'bg-yellow-500/10 text-yellow-500';
    case 'failed':  return 'bg-destructive/10 text-destructive';
    default:        return 'bg-muted text-muted-foreground';
  }
}

export function Dashboard() {
  const { user, isAdmin } = useAuth();
  const { selectedSite } = useSite();
  const [sites, setSites] = useState<Record<string, SiteStat>>({});
  const [txs, setTxs] = useState<TxRow[]>([]);
  const [clients, setClients] = useState<Client[]>([]);
  const [loading, setLoading] = useState(true);
  const [dateFilter, setDateFilter] = useState<DateFilter>('today');
  const [lastUpdated, setLastUpdated] = useState<Date | null>(null);
  const [deviceStats, setDeviceStats] = useState<DeviceStats>({ total_routers: 0, online_routers: 0, total_clients: 0, active_connections: 0, avg_cpu: 0, avg_memory: 0, bandwidth_up: 0, bandwidth_down: 0 });

  const getAuthHeaders = (): HeadersInit => {
    const token = localStorage.getItem('tenant_token') || localStorage.getItem('admin_token');
    const headers: HeadersInit = {
      'Content-Type': 'application/json',
      'Accept': 'application/json',
    };
    if (token) headers['Authorization'] = `Bearer ${token}`;
    if (selectedSite?.id) headers['X-Site-ID'] = String(selectedSite.id);
    return headers;
  };

  const toDateInput = (date: Date) => date.toISOString().slice(0, 10);

  const getDateRange = (filter: DateFilter) => {
    const now = new Date();
    const start = new Date(now);
    const end = new Date(now);

    if (filter === 'all') return {};

    if (filter === 'yesterday') {
      start.setDate(now.getDate() - 1);
      end.setDate(now.getDate() - 1);
    }

    if (filter === 'week') {
      const day = now.getDay() || 7;
      start.setDate(now.getDate() - day + 1);
    }

    if (filter === 'month') {
      start.setDate(1);
    }

    return {
      from_date: toDateInput(start),
      to_date: toDateInput(end),
    };
  };

  const load = useCallback(async () => {
    try {
      const headers = getAuthHeaders();
      
      const range = getDateRange(dateFilter);
      const params = new URLSearchParams({ per_page: '20', ...range });

      // Fetch stats and filtered transactions
      const [statsRes, txResponse] = await Promise.all([
        apiStats(),
        fetch(`/api/transactions?${params.toString()}`, { headers }),
      ]);
      const txRes = txResponse.ok ? await txResponse.json() : { data: [] };
      const transactions = txRes.transactions ?? txRes.data ?? [];
      setTxs(transactions);

      const groupedSites = transactions.reduce((acc: Record<string, SiteStat>, tx: TxRow) => {
        const site = tx.origin_site || 'Default Site';
        const amount = tx.status === 'success' ? parseFloat(tx.amount || '0') : 0;
        if (!acc[site]) {
          acc[site] = { total_amount: 0, today_amount: 0, week_amount: 0, withdrawn: 0, pending_withdraw: 0, balance: 0, total_sales: 0 };
        }
        acc[site].total_amount += amount;
        acc[site].today_amount += amount;
        acc[site].balance += amount;
        acc[site].total_sales += tx.status === 'success' ? 1 : 0;
        return acc;
      }, {});
      setSites(Object.keys(groupedSites).length ? groupedSites : (statsRes.sites ?? {}));

      // Fetch active clients from radacct (active hotspot users)
      let activeClientCount = 0;
      try {
        const clientsRes = await fetch('/api/radius/active-users', { headers });
        
        if (clientsRes.ok) {
          const clientsData = await clientsRes.json();
          
          // Map radacct data to client format
          const activeClients = (clientsData.active_users || []).map((user: any) => ({
            id: user.session_id,
            mac_address: user.mac_address,
            username: user.username,
            ip_address: user.ip_address,
            total_sessions: 1,
            total_spent: 0,
            last_seen: user.connected_at,
            status: 'active'
          }));
          
          activeClientCount = activeClients.length;
          setClients(activeClients);
        } else {
          setClients([]);
        }
      } catch (err) {
        console.error('Active clients fetch error:', err);
        setClients([]);
      }

      // Fetch device stats from telemetry endpoint
      try {
        const telemetryData = await getTelemetryStats();

        setDeviceStats({
          total_routers: telemetryData.total_routers || 0,
          online_routers: telemetryData.online_routers || 0,
          total_clients: activeClientCount,
          active_connections: telemetryData.total_active_users || 0,
          avg_cpu: Math.round(telemetryData.avg_cpu || 0),
          avg_memory: Math.round(telemetryData.avg_memory || 0),
          bandwidth_up: Math.round(telemetryData.bandwidth_upload_kbps || 0),
          bandwidth_down: Math.round(telemetryData.bandwidth_download_kbps || 0),
        });
      } catch (err) {
        console.error('Telemetry fetch error:', err);
      }

      setLastUpdated(new Date());
    } catch (e) {
      console.error(e);
    } finally {
      setLoading(false);
    }
  }, [dateFilter, selectedSite?.id]);

  useEffect(() => {
    load();
    const iv = setInterval(load, 5000); // Refresh every 5 seconds
    return () => clearInterval(iv);
  }, [load]);

  const siteList = Object.entries(sites);
  const filteredEarnings = siteList.reduce((s, [, v]) => s + v.total_amount, 0);
  const successfulPurchases = txs.filter((tx) => tx.status === 'success').length;

  if (loading) {
    return (
      <div className="flex items-center justify-center h-64">
        <RefreshCw className="w-6 h-6 text-primary animate-spin" />
      </div>
    );
  }

  return (
    <div className="p-4 sm:p-6 lg:p-8">
      <div className="mb-6 sm:mb-8 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-2">
        <div>
          <h1 className="text-2xl sm:text-3xl text-foreground mb-1">Welcome back, {user?.name}!</h1>
          <p className="text-sm text-muted-foreground">
            {selectedSite ? `Managing ${selectedSite.name}` : "Here's what's happening with your WIFI Network."}
          </p>
        </div>
        <div className="flex items-center gap-2 text-xs text-muted-foreground">
          <RefreshCw className="w-3 h-3" />
          {lastUpdated ? `Updated ${lastUpdated.toLocaleTimeString()}` : 'Loading…'}
        </div>
      </div>

      <div className="mb-6 flex flex-wrap items-center gap-2">
        <div className="flex items-center gap-2 text-sm text-muted-foreground mr-1">
          <CalendarDays className="w-4 h-4" />
          Filter
        </div>
        {DATE_FILTERS.map((filter) => (
          <button
            key={filter.id}
            onClick={() => setDateFilter(filter.id)}
            className={`px-3 py-1.5 rounded-lg text-sm border transition-colors ${
              dateFilter === filter.id
                ? 'bg-primary text-primary-foreground border-primary'
                : 'bg-card border-border text-card-foreground hover:bg-muted'
            }`}
          >
            {filter.label}
          </button>
        ))}
      </div>

      {/* Network Status - Compact Widget */}
      <div className="bg-card border border-border rounded-lg p-3 mb-6">
        <div className="flex items-center flex-wrap gap-x-6 gap-y-2">
          <div className="flex items-center gap-2">
            <Server className="w-4 h-4 text-primary" />
            <span className="text-xs text-muted-foreground">Routers:</span>
            <span className="text-sm font-semibold text-emerald-500">{deviceStats.online_routers}</span>
            <span className="text-xs text-muted-foreground">/ {deviceStats.total_routers}</span>
          </div>
          <div className="flex items-center gap-2">
            <Cpu className="w-4 h-4 text-orange-500" />
            <span className="text-xs text-muted-foreground">CPU:</span>
            <span className={`text-sm font-semibold ${deviceStats.avg_cpu > 80 ? 'text-red-500' : deviceStats.avg_cpu > 50 ? 'text-yellow-500' : 'text-emerald-500'}`}>
              {deviceStats.avg_cpu}%
            </span>
          </div>
          <div className="flex items-center gap-2">
            <HardDrive className="w-4 h-4 text-blue-500" />
            <span className="text-xs text-muted-foreground">Memory:</span>
            <span className={`text-sm font-semibold ${deviceStats.avg_memory > 90 ? 'text-red-500' : deviceStats.avg_memory > 70 ? 'text-yellow-500' : 'text-emerald-500'}`}>
              {deviceStats.avg_memory}%
            </span>
          </div>
          <div className="flex items-center gap-2">
            <ArrowDown className="w-4 h-4 text-emerald-500" />
            <span className="text-xs text-muted-foreground">Down:</span>
            <span className="text-sm font-semibold text-card-foreground">{deviceStats.bandwidth_down} Kbps</span>
          </div>
          <div className="flex items-center gap-2">
            <ArrowUp className="w-4 h-4 text-blue-500" />
            <span className="text-xs text-muted-foreground">Up:</span>
            <span className="text-sm font-semibold text-card-foreground">{deviceStats.bandwidth_up} Kbps</span>
          </div>
          <div className="flex items-center gap-2">
            <Activity className="w-4 h-4 text-purple-500" />
            <span className="text-xs text-muted-foreground">Connections:</span>
            <span className="text-sm font-semibold text-purple-500">{deviceStats.active_connections}</span>
          </div>
          <span className="ml-auto flex items-center gap-1 text-xs text-emerald-500">
            <span className="w-1.5 h-1.5 bg-emerald-500 rounded-full animate-pulse" />
            Live
          </span>
        </div>
      </div>

      {/* Summary stats */}
      <div className="grid grid-cols-1 sm:grid-cols-3 gap-4 sm:gap-6 mb-6 sm:mb-8">
        <StatsCard title={`${DATE_FILTERS.find((f) => f.id === dateFilter)?.label} Earnings`} value={fmt(filteredEarnings)} icon={DollarSign} trend={{ value: 'Live', isPositive: true }} />
        <StatsCard title="Successful Purchases" value={successfulPurchases.toLocaleString()} icon={Users} />
        <StatsCard title="Transactions Loaded" value={txs.length.toLocaleString()} icon={TrendingUp} />
      </div>

      {/* Per-site cards (admin sees all, user sees their own) */}
      {siteList.length > 0 && (
        <div className="mb-6 sm:mb-8">
          {isAdmin() && <h2 className="text-lg text-foreground mb-4">Site Performance</h2>}
          <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-5 gap-4">
            {siteList.map(([site, stat]) => (
              <div
                key={site}
                className={`bg-gradient-to-br ${SITE_COLORS[site] ?? 'from-slate-600 to-slate-700'} rounded-xl p-5 text-white`}
              >
                <p className="text-sm font-semibold opacity-80 mb-1">{site}</p>
                <p className="text-2xl font-bold mb-3">{fmt(stat.total_amount)}</p>
                <div className="space-y-1 text-xs bg-white/10 rounded-lg p-3">
                  <div className="flex justify-between">
                    <span className="opacity-80">{DATE_FILTERS.find((f) => f.id === dateFilter)?.label}</span>
                    <span className="font-semibold">{fmt(stat.today_amount)}</span>
                  </div>
                  <div className="flex justify-between border-t border-white/20 pt-1 mt-1">
                    <span className="font-semibold">Balance</span>
                    <span className="font-bold text-yellow-200">{fmt(stat.balance)}</span>
                  </div>
                </div>
              </div>
            ))}
          </div>
        </div>
      )}

      {/* Clients and Recent Transactions - Side by Side */}
      <div className="grid grid-cols-1 lg:grid-cols-2 gap-6">
        {/* Top Clients */}
        <div className="bg-card border border-border rounded-lg p-4 sm:p-6">
          <div className="flex items-center justify-between mb-4">
            <div className="flex items-center gap-2">
              <Users className="w-5 h-5 text-primary" />
              <h2 className="text-lg font-semibold text-card-foreground">Top Clients</h2>
            </div>
            <Link to="/clients" className="flex items-center gap-1 text-sm text-primary hover:text-primary/80 transition-colors">
              View all <ArrowRight className="w-4 h-4" />
            </Link>
          </div>

          <div className="space-y-3 max-h-[400px] overflow-y-auto">
            {clients.length === 0 ? (
              <div className="py-8 text-center">
                <Users className="w-10 h-10 text-muted-foreground mx-auto mb-2" />
                <p className="text-sm text-muted-foreground">No clients yet</p>
              </div>
            ) : (
              clients.slice(0, 20).map((client, i) => (
                <div
                  key={client.id || i}
                  className="flex items-center justify-between p-3 bg-muted/30 rounded-lg hover:bg-muted/50 transition-colors"
                >
                  <div className="flex items-center gap-3">
                    <div className="w-10 h-10 bg-primary/10 rounded-full flex items-center justify-center">
                      <span className="text-sm font-semibold text-primary">
                        {(client.username || client.mac_address || 'U').charAt(0).toUpperCase()}
                      </span>
                    </div>
                    <div>
                      <p className="text-sm font-medium text-card-foreground">
                        {client.username || client.mac_address || 'Unknown'}
                      </p>
                      <p className="text-xs text-muted-foreground">
                        {client.ip_address || 'No IP'} • {client.total_sessions || 0} sessions
                      </p>
                    </div>
                  </div>
                  <div className="text-right">
                    <p className="text-sm font-semibold text-card-foreground">
                      {fmt(client.total_spent || 0)}
                    </p>
                    <span className={`inline-block px-2 py-0.5 rounded-full text-xs ${
                      client.status === 'online' ? 'bg-emerald-500/10 text-emerald-500' : 'bg-muted text-muted-foreground'
                    }`}>
                      {client.status || 'offline'}
                    </span>
                  </div>
                </div>
              ))
            )}
          </div>
        </div>

        {/* Recent Transactions */}
        <div className="bg-card border border-border rounded-lg p-4 sm:p-6">
          <div className="flex items-center justify-between mb-4">
            <div className="flex items-center gap-2">
              <DollarSign className="w-5 h-5 text-primary" />
              <h2 className="text-lg font-semibold text-card-foreground">Recent Transactions</h2>
            </div>
            <Link to="/transactions" className="flex items-center gap-1 text-sm text-primary hover:text-primary/80 transition-colors">
              View all <ArrowRight className="w-4 h-4" />
            </Link>
          </div>

          <div className="space-y-3 max-h-[400px] overflow-y-auto">
            {txs.length === 0 ? (
              <div className="py-8 text-center">
                <DollarSign className="w-10 h-10 text-muted-foreground mx-auto mb-2" />
                <p className="text-sm text-muted-foreground">No transactions found</p>
              </div>
            ) : (
              txs.slice(0, 20).map((tx, i) => (
                <div
                  key={`${tx.id}-${i}`}
                  className="flex items-center justify-between p-3 bg-muted/30 rounded-lg hover:bg-muted/50 transition-colors"
                >
                  <div className="flex items-center gap-3">
                    <div className={`w-10 h-10 rounded-full flex items-center justify-center ${
                      tx.status === 'success' ? 'bg-emerald-500/10' : 
                      tx.status === 'pending' ? 'bg-yellow-500/10' : 'bg-destructive/10'
                    }`}>
                      <DollarSign className={`w-5 h-5 ${
                        tx.status === 'success' ? 'text-emerald-500' : 
                        tx.status === 'pending' ? 'text-yellow-500' : 'text-destructive'
                      }`} />
                    </div>
                    <div>
                      <p className="text-sm font-medium text-card-foreground">{tx.msisdn}</p>
                      <p className="text-xs text-muted-foreground">
                        {tx.voucher_code || 'No voucher'} • {tx.origin_site || 'Unknown'}
                      </p>
                    </div>
                  </div>
                  <div className="text-right">
                    <p className="text-sm font-semibold text-card-foreground">
                      {fmt(parseFloat(tx.amount))}
                    </p>
                    <p className="text-xs text-muted-foreground">
                      {new Date(tx.created_at).toLocaleString('en-GB', { day:'2-digit', month:'short', hour:'2-digit', minute:'2-digit' })}
                    </p>
                  </div>
                </div>
              ))
            )}
          </div>
        </div>
      </div>
    </div>
  );
}
