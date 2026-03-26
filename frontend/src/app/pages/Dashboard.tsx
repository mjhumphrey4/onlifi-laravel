import { useState, useEffect, useCallback } from 'react';
import { DollarSign, TrendingUp, RefreshCw, Users, ArrowRight, Server, Wifi, Activity } from 'lucide-react';
import { Link } from 'react-router';
import { StatsCard } from '../components/StatsCard';
import { useAuth } from '../context/AuthContext';
import { apiStats, apiTransactions } from '../utils/api';

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
  const [sites, setSites] = useState<Record<string, SiteStat>>({});
  const [txs, setTxs] = useState<TxRow[]>([]);
  const [clients, setClients] = useState<Client[]>([]);
  const [loading, setLoading] = useState(true);
  const [lastUpdated, setLastUpdated] = useState<Date | null>(null);
  const [deviceStats, setDeviceStats] = useState<DeviceStats>({ total_routers: 0, online_routers: 0, total_clients: 0, active_connections: 0 });

  const getAuthHeaders = (): HeadersInit => {
    const token = localStorage.getItem('tenant_token') || localStorage.getItem('admin_token');
    const headers: HeadersInit = {
      'Content-Type': 'application/json',
      'Accept': 'application/json',
    };
    if (token) headers['Authorization'] = `Bearer ${token}`;
    return headers;
  };

  const load = useCallback(async () => {
    try {
      const headers = getAuthHeaders();
      
      // Fetch stats and transactions
      const [statsRes, txRes] = await Promise.all([
        apiStats(),
        apiTransactions({ limit: 20 }),
      ]);
      setSites(statsRes.sites ?? {});
      setTxs(txRes.transactions ?? []);

      // Fetch clients (top 10)
      try {
        const clientsRes = await fetch('/api/clients?limit=10', { headers });
        if (clientsRes.ok) {
          const clientsData = await clientsRes.json();
          setClients(clientsData.data || clientsData.clients || []);
        }
      } catch {
        setClients([]);
      }

      // Fetch device stats
      try {
        const routersRes = await fetch('/api/routers', { headers });
        if (routersRes.ok) {
          const routersData = await routersRes.json();
          const routers = Array.isArray(routersData) ? routersData : routersData.data || [];
          const onlineRouters = routers.filter((r: any) => {
            if (!r.last_seen) return false;
            const diff = Date.now() - new Date(r.last_seen).getTime();
            return diff < 600000; // 10 minutes
          });
          const totalConnections = routers.reduce((sum: number, r: any) => sum + (r.last_active_connections || 0), 0);
          setDeviceStats({
            total_routers: routers.length,
            online_routers: onlineRouters.length,
            total_clients: clients.length,
            active_connections: totalConnections,
          });
        }
      } catch {
        // Device stats not available
      }

      setLastUpdated(new Date());
    } catch (e) {
      console.error(e);
    } finally {
      setLoading(false);
    }
  }, []);

  useEffect(() => {
    load();
    const iv = setInterval(load, 5000); // Refresh every 5 seconds
    return () => clearInterval(iv);
  }, [load]);

  const siteList = Object.entries(sites);
  const totalEarnings   = siteList.reduce((s, [, v]) => s + v.total_amount,  0);
  const todayEarnings   = siteList.reduce((s, [, v]) => s + v.today_amount,  0);

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
          <p className="text-sm text-muted-foreground">Here's what's happening with your WIFI Network.</p>
        </div>
        <div className="flex items-center gap-2 text-xs text-muted-foreground">
          <RefreshCw className="w-3 h-3" />
          {lastUpdated ? `Updated ${lastUpdated.toLocaleTimeString()}` : 'Loading…'}
        </div>
      </div>

      {/* Device Stats Widget */}
      <div className="bg-card border border-border rounded-lg p-4 mb-6">
        <div className="flex items-center gap-2 mb-3">
          <Server className="w-5 h-5 text-primary" />
          <h2 className="text-sm font-semibold text-card-foreground">Network Status</h2>
          <span className="ml-auto flex items-center gap-1 text-xs text-emerald-500">
            <span className="w-2 h-2 bg-emerald-500 rounded-full animate-pulse" />
            Live
          </span>
        </div>
        <div className="grid grid-cols-2 sm:grid-cols-4 gap-3">
          <div className="bg-muted/30 rounded-lg p-3 text-center">
            <div className="flex items-center justify-center gap-2 mb-1">
              <Server className="w-4 h-4 text-primary" />
              <span className="text-xl font-bold text-card-foreground">{deviceStats.total_routers}</span>
            </div>
            <p className="text-xs text-muted-foreground">Total Routers</p>
          </div>
          <div className="bg-emerald-500/10 rounded-lg p-3 text-center">
            <div className="flex items-center justify-center gap-2 mb-1">
              <Wifi className="w-4 h-4 text-emerald-500" />
              <span className="text-xl font-bold text-emerald-500">{deviceStats.online_routers}</span>
            </div>
            <p className="text-xs text-muted-foreground">Online</p>
          </div>
          <div className="bg-blue-500/10 rounded-lg p-3 text-center">
            <div className="flex items-center justify-center gap-2 mb-1">
              <Users className="w-4 h-4 text-blue-500" />
              <span className="text-xl font-bold text-blue-500">{deviceStats.total_clients}</span>
            </div>
            <p className="text-xs text-muted-foreground">Clients</p>
          </div>
          <div className="bg-purple-500/10 rounded-lg p-3 text-center">
            <div className="flex items-center justify-center gap-2 mb-1">
              <Activity className="w-4 h-4 text-purple-500" />
              <span className="text-xl font-bold text-purple-500">{deviceStats.active_connections}</span>
            </div>
            <p className="text-xs text-muted-foreground">Active Connections</p>
          </div>
        </div>
      </div>

      {/* Summary stats */}
      <div className="grid grid-cols-1 sm:grid-cols-2 gap-4 sm:gap-6 mb-6 sm:mb-8">
        <StatsCard title="Today's Earnings" value={fmt(todayEarnings)} icon={DollarSign} trend={{ value: 'Live', isPositive: true }} />
        <StatsCard title="Total Earnings" value={fmt(totalEarnings)} icon={TrendingUp} />
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
                    <span className="opacity-80">Today</span>
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