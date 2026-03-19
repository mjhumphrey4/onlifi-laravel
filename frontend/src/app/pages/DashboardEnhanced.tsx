import { useState, useEffect, useCallback } from 'react';
import { DollarSign, RefreshCw, Users, Server, Ticket, Activity } from 'lucide-react';
import { StatsCard } from '../components/StatsCard';
import { useAuth } from '../context/AuthContext';
import { getTenantDashboardStats, getTransactions, getVoucherStatistics } from '../utils/api';

interface DashboardStats {
  total_active_users: number;
  total_routers: number;
  online_routers: number;
  today_transactions: number;
  today_revenue: number;
  active_vouchers: number;
  unused_vouchers: number;
  routers: RouterStats[];
}

interface RouterStats {
  id: number;
  name: string;
  location: string;
  cpu_load: number;
  memory_used_mb: number;
  memory_total_mb: number;
  active_users: number;
  last_seen: string;
  is_online: boolean;
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

interface VoucherStats {
  total: number;
  unused: number;
  active: number;
  used: number;
  expired: number;
}

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
  const [dashboardStats, setDashboardStats] = useState<DashboardStats | null>(null);
  const [txs, setTxs] = useState<TxRow[]>([]);
  const [voucherStats, setVoucherStats] = useState<VoucherStats | null>(null);
  const [loading, setLoading] = useState(true);
  const [lastUpdated, setLastUpdated] = useState<Date | null>(null);

  const load = useCallback(async () => {
    try {
      const [statsRes, txRes, voucherRes] = await Promise.all([
        getTenantDashboardStats().catch(() => null),
        getTransactions({ page: 1 }).catch(() => ({ data: [] })),
        getVoucherStatistics().catch(() => null),
      ]);
      
      if (statsRes) {
        setDashboardStats(statsRes);
      }
      
      setTxs(txRes.data ?? []);
      
      if (voucherRes) {
        setVoucherStats(voucherRes);
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
    const iv = setInterval(load, 60000);
    return () => clearInterval(iv);
  }, [load]);

  const todayRevenue = dashboardStats?.today_revenue ?? 0;
  const activeUsers = dashboardStats?.total_active_users ?? 0;
  const totalRouters = dashboardStats?.total_routers ?? 0;
  const onlineRouters = dashboardStats?.online_routers ?? 0;
  const routerStats = dashboardStats?.routers ?? [];

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
          <p className="text-sm text-muted-foreground">Here's what's happening with your WIFI Network today.</p>
        </div>
        <div className="flex items-center gap-2 text-xs text-muted-foreground">
          <RefreshCw className="w-3 h-3" />
          {lastUpdated ? `Updated ${lastUpdated.toLocaleTimeString()}` : 'Loading…'}
        </div>
      </div>

      {/* Summary stats */}
      <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 sm:gap-6 mb-6 sm:mb-8">
        <StatsCard title="Today's Revenue"  value={fmt(todayRevenue)}  icon={DollarSign} trend={{ value: 'Live data', isPositive: true }} />
        <StatsCard title="Active Users"    value={activeUsers.toString()}  icon={Users} trend={{ value: 'Real-time', isPositive: true }} />
        <StatsCard title="Online Routers" value={`${onlineRouters}/${totalRouters}`} icon={Server} />
        <StatsCard title="Unused Vouchers" value={(voucherStats?.unused ?? 0).toString()} icon={Ticket} />
      </div>

      {/* MikroTik Stats Section */}
      <div className="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-6">
        {/* Voucher Stats */}
        {voucherStats && (
          <div className="bg-card border border-border rounded-lg p-6">
            <div className="flex items-center justify-between mb-4">
              <h2 className="text-lg font-semibold text-card-foreground flex items-center gap-2">
                <Ticket className="w-5 h-5 text-primary" />
                Voucher Statistics
              </h2>
              <a href="/vouchers" className="text-sm text-primary hover:text-primary/80">View all →</a>
            </div>
            
            <div className="grid grid-cols-2 gap-4 mb-4">
              <div>
                <p className="text-sm text-muted-foreground mb-1">Total Vouchers</p>
                <p className="text-2xl font-bold text-card-foreground">{voucherStats.total}</p>
              </div>
              <div>
                <p className="text-sm text-muted-foreground mb-1">Available</p>
                <p className="text-2xl font-bold text-emerald-500">{voucherStats.unused}</p>
              </div>
              <div>
                <p className="text-sm text-muted-foreground mb-1">Used</p>
                <p className="text-2xl font-bold text-blue-500">{voucherStats.used}</p>
              </div>
              <div>
                <p className="text-sm text-muted-foreground mb-1">Active</p>
                <p className="text-xl font-bold text-primary">{voucherStats.active}</p>
              </div>
            </div>

            {/* Usage Bar */}
            <div className="mt-4">
              <div className="flex items-center justify-between mb-2">
                <span className="text-xs text-muted-foreground">Usage Rate</span>
                <span className="text-xs font-semibold text-card-foreground">
                  {voucherStats.total > 0 
                    ? `${((voucherStats.used / voucherStats.total) * 100).toFixed(1)}%`
                    : '0%'}
                </span>
              </div>
              <div className="w-full bg-muted rounded-full h-2">
                <div
                  className="bg-primary h-2 rounded-full transition-all"
                  style={{ 
                    width: voucherStats.total > 0 
                      ? `${Math.min((voucherStats.used / voucherStats.total) * 100, 100)}%`
                      : '0%'
                  }}
                />
              </div>
            </div>
          </div>
        )}

        {/* Router Status */}
        <div className="bg-card border border-border rounded-lg p-6">
          <div className="flex items-center justify-between mb-4">
            <h2 className="text-lg font-semibold text-card-foreground flex items-center gap-2">
              <Server className="w-5 h-5 text-primary" />
              Router Status
            </h2>
            <a href="/devices" className="text-sm text-primary hover:text-primary/80">View all →</a>
          </div>

          {routerStats.length > 0 ? (
            <div className="space-y-4">
              {routerStats.slice(0, 3).map((router) => {
                const memPercent = router.memory_total_mb > 0 ? (router.memory_used_mb / router.memory_total_mb) * 100 : 0;
                return (
                  <div key={router.id} className="bg-muted/50 rounded-lg p-4">
                    <div className="flex items-center justify-between mb-3">
                      <p className="font-semibold text-card-foreground">{router.name}</p>
                      <Activity className={`w-4 h-4 ${router.is_online ? 'text-emerald-500' : 'text-muted-foreground'}`} />
                    </div>
                    
                    <div className="space-y-2">
                      <div>
                        <div className="flex items-center justify-between mb-1">
                          <span className="text-xs text-muted-foreground">CPU Load</span>
                          <span className="text-xs font-semibold text-card-foreground">{router.cpu_load}%</span>
                        </div>
                        <div className="w-full bg-background rounded-full h-1.5">
                          <div
                            className={`h-1.5 rounded-full transition-all ${
                              router.cpu_load > 80 ? 'bg-destructive' : router.cpu_load > 60 ? 'bg-yellow-500' : 'bg-emerald-500'
                            }`}
                            style={{ width: `${Math.min(router.cpu_load, 100)}%` }}
                          />
                        </div>
                      </div>

                      <div>
                        <div className="flex items-center justify-between mb-1">
                          <span className="text-xs text-muted-foreground">Memory</span>
                          <span className="text-xs font-semibold text-card-foreground">
                            {router.memory_used_mb} / {router.memory_total_mb} MB
                          </span>
                        </div>
                        <div className="w-full bg-background rounded-full h-1.5">
                          <div
                            className={`h-1.5 rounded-full transition-all ${
                              memPercent > 90 ? 'bg-destructive' : memPercent > 75 ? 'bg-yellow-500' : 'bg-blue-500'
                            }`}
                            style={{ width: `${Math.min(memPercent, 100)}%` }}
                          />
                        </div>
                      </div>

                      <div className="grid grid-cols-2 gap-2 mt-3 pt-2 border-t border-border">
                        <div>
                          <span className="text-xs text-muted-foreground block mb-1">Active Users</span>
                          <span className="text-xs font-semibold text-card-foreground">
                            {router.active_users}
                          </span>
                        </div>
                        <div>
                          <span className="text-xs text-muted-foreground block mb-1">Location</span>
                          <span className="text-xs font-semibold text-card-foreground">
                            {router.location || 'N/A'}
                          </span>
                        </div>
                      </div>
                    </div>
                  </div>
                );
              })}
            </div>
          ) : (
            <div className="text-center py-8">
              <Server className="w-8 h-8 text-muted-foreground mx-auto mb-2" />
              <p className="text-sm text-muted-foreground">No router data available</p>
            </div>
          )}
        </div>
      </div>

      {/* Recent transactions */}
      <div className="bg-card border border-border rounded-lg p-4 sm:p-6">
        <div className="flex items-center justify-between mb-6">
          <h2 className="text-lg sm:text-xl text-card-foreground">Recent Transactions</h2>
          <a href="/transactions" className="text-sm text-primary hover:text-primary/80 transition-colors">
            View all →
          </a>
        </div>

        <div className="overflow-x-auto -mx-4 sm:mx-0">
          <div className="inline-block min-w-full align-middle">
            <table className="w-full">
              <thead>
                <tr className="border-b border-border">
                  {['Voucher', 'Phone', 'Amount', 'Site', 'Status', 'Date'].map((h) => (
                    <th key={h} className="text-left py-3 px-2 sm:px-4 text-xs sm:text-sm text-muted-foreground whitespace-nowrap">{h}</th>
                  ))}
                </tr>
              </thead>
              <tbody>
                {txs.length === 0 ? (
                  <tr><td colSpan={6} className="py-8 text-center text-muted-foreground text-sm">No transactions found.</td></tr>
                ) : txs.map((tx, i) => (
                  <tr key={`${tx.id}-${i}`} className="border-b border-border/50 hover:bg-muted/50 transition-colors">
                    <td className="py-3 px-2 sm:px-4 text-xs font-mono whitespace-nowrap">
                      {tx.voucher_code
                        ? <span className="text-primary font-semibold tracking-wider">{tx.voucher_code}</span>
                        : <span className="text-muted-foreground">—</span>}
                    </td>
                    <td className="py-3 px-2 sm:px-4 text-xs sm:text-sm text-card-foreground whitespace-nowrap">{tx.msisdn}</td>
                    <td className="py-3 px-2 sm:px-4 text-xs sm:text-sm text-card-foreground whitespace-nowrap font-semibold">
                      {fmt(parseFloat(tx.amount))}
                    </td>
                    <td className="py-3 px-2 sm:px-4 text-xs sm:text-sm text-muted-foreground whitespace-nowrap">{tx.origin_site}</td>
                    <td className="py-3 px-2 sm:px-4 whitespace-nowrap">
                      <span className={`inline-block px-2 py-1 rounded-full text-xs capitalize ${statusStyle(tx.status)}`}>
                        {tx.status}
                      </span>
                    </td>
                    <td className="py-3 px-2 sm:px-4 text-xs sm:text-sm text-muted-foreground whitespace-nowrap">
                      {new Date(tx.created_at).toLocaleString('en-GB', { day:'2-digit', month:'short', hour:'2-digit', minute:'2-digit' })}
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </div>
      </div>
    </div>
  );
}
