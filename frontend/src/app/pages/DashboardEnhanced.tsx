import { useState, useEffect, useCallback } from 'react';
import { DollarSign, TrendingUp, Wallet, RefreshCw, Users, Server, Ticket, Activity } from 'lucide-react';
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

interface VoucherStats {
  total_vouchers: number;
  unused: number;
  used: number;
  expired: number;
  total_revenue: number;
}

interface RouterTelemetry {
  router_name: string;
  cpu_load: number;
  memory_used_mb: number;
  memory_total_mb: number;
  uptime_seconds: number;
  bandwidth_download_kbps?: number;
  bandwidth_upload_kbps?: number;
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
  const [voucherStats, setVoucherStats] = useState<VoucherStats | null>(null);
  const [routerTelemetry, setRouterTelemetry] = useState<RouterTelemetry[]>([]);
  const [activeClients, setActiveClients] = useState(0);
  const [loading, setLoading] = useState(true);
  const [lastUpdated, setLastUpdated] = useState<Date | null>(null);

  const load = useCallback(async () => {
    try {
      const [statsRes, txRes, voucherRes, clientsRes, telemetryRes] = await Promise.all([
        apiStats(),
        apiTransactions({ limit: 10 }),
        fetch('/api/mikrotik_api.php?action=voucher_stats').then(r => r.json()).catch(() => ({ overall: null })),
        fetch('/api/mikrotik_api.php?action=clients').then(r => r.json()).catch(() => ({ count: 0 })),
        fetch('/api/mikrotik_api.php?action=router_telemetry').then(r => r.json()).catch(() => ({ telemetry: [] })),
      ]);
      
      setSites(statsRes.sites ?? {});
      setTxs(txRes.transactions ?? []);
      
      if (voucherRes.overall) {
        setVoucherStats(voucherRes.overall);
      }
      
      if (clientsRes.count !== undefined) {
        setActiveClients(clientsRes.count);
      }
      
      if (telemetryRes.telemetry) {
        setRouterTelemetry(telemetryRes.telemetry.slice(0, 3));
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

  const siteList = Object.entries(sites);
  const totalEarnings   = siteList.reduce((s, [, v]) => s + v.total_amount,  0);
  const todayEarnings   = siteList.reduce((s, [, v]) => s + v.today_amount,  0);
  const totalWithdrawn  = siteList.reduce((s, [, v]) => s + v.withdrawn,     0);

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
        <StatsCard title="Today's Earnings"  value={fmt(todayEarnings)}  icon={DollarSign} trend={{ value: 'Live data', isPositive: true }} />
        <StatsCard title="Total Earnings"    value={fmt(totalEarnings)}  icon={TrendingUp} />
        <StatsCard title="Total Withdrawals" value={fmt(totalWithdrawn)} icon={Wallet} />
        <StatsCard title="Active Clients" value={activeClients.toString()} icon={Users} trend={{ value: 'Real-time', isPositive: true }} />
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
                <p className="text-2xl font-bold text-card-foreground">{voucherStats.total_vouchers}</p>
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
                <p className="text-sm text-muted-foreground mb-1">Revenue</p>
                <p className="text-xl font-bold text-primary">{fmt(voucherStats.total_revenue)}</p>
              </div>
            </div>

            {/* Usage Bar */}
            <div className="mt-4">
              <div className="flex items-center justify-between mb-2">
                <span className="text-xs text-muted-foreground">Usage Rate</span>
                <span className="text-xs font-semibold text-card-foreground">
                  {voucherStats.total_vouchers > 0 
                    ? `${((voucherStats.used / voucherStats.total_vouchers) * 100).toFixed(1)}%`
                    : '0%'}
                </span>
              </div>
              <div className="w-full bg-muted rounded-full h-2">
                <div
                  className="bg-primary h-2 rounded-full transition-all"
                  style={{ 
                    width: voucherStats.total_vouchers > 0 
                      ? `${Math.min((voucherStats.used / voucherStats.total_vouchers) * 100, 100)}%`
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

          {routerTelemetry.length > 0 ? (
            <div className="space-y-4">
              {routerTelemetry.map((router, idx) => {
                const memPercent = (router.memory_used_mb / router.memory_total_mb) * 100;
                return (
                  <div key={idx} className="bg-muted/50 rounded-lg p-4">
                    <div className="flex items-center justify-between mb-3">
                      <p className="font-semibold text-card-foreground">{router.router_name}</p>
                      <Activity className="w-4 h-4 text-emerald-500" />
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
                          <span className="text-xs text-muted-foreground block mb-1">Uptime</span>
                          <span className="text-xs font-semibold text-card-foreground">
                            {Math.floor(router.uptime_seconds / 86400)}d {Math.floor((router.uptime_seconds % 86400) / 3600)}h
                          </span>
                        </div>
                        {router.bandwidth_download_kbps !== undefined && (
                          <div>
                            <span className="text-xs text-muted-foreground block mb-1">Speed</span>
                            <span className="text-xs font-semibold text-card-foreground">
                              ↓{(router.bandwidth_download_kbps / 1024).toFixed(1)} Mbps
                            </span>
                          </div>
                        )}
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
                  <div className="flex justify-between">
                    <span className="opacity-80">Withdrawn</span>
                    <span className="font-semibold text-red-200">{fmt(stat.withdrawn)}</span>
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
