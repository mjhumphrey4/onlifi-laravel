import { useState, useEffect, useCallback } from 'react';
import { DollarSign, TrendingUp, RefreshCw, Users, User, ArrowRight, Server, Activity, Cpu, HardDrive, ArrowUp, ArrowDown, CalendarDays, Smartphone, HelpCircle, X, CheckCircle2, Router, Paintbrush } from 'lucide-react';
import { Link } from 'react-router';
import { StatsCard } from '../components/StatsCard';
import { useAuth } from '../context/AuthContext';
import { useSite } from '../context/SiteContext';
import { API_BASE, apiStats, getPerformanceAnalytics, getTelemetryStats, getVoucherStatistics } from '../utils/api';

interface SiteStat {
  total_amount: number;
  today_amount: number;
  week_amount: number;
  withdrawn: number;
  pending_withdraw: number;
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
  hostname: string;
  ip_address: string;
  voucher_code: string;
  total_sessions: number;
  total_spent: number;
  last_seen: string;
  status: string;
}

interface TopSalesAgent {
  id: number;
  name: string;
  total_vouchers: number;
  used: number;
  in_use?: number;
  revenue: number;
  revenue_30_days?: number;
}

type DateFilter = 'today' | 'yesterday' | 'week' | 'month' | 'all';

const DATE_FILTERS: { id: DateFilter; label: string }[] = [
  { id: 'today', label: 'Today' },
  { id: 'yesterday', label: 'Yesterday' },
  { id: 'week', label: 'This Week' },
  { id: 'month', label: 'This Month' },
  { id: 'all', label: 'All' },
];

const TOUR_STORAGE_KEY = 'onlifi_dashboard_tour_completed';

const TOUR_STEPS = [
  {
    icon: Users,
    title: 'Create vouchers',
    body: 'Open Manage Vouchers, choose voucher types, then create a batch or manual voucher for the active site.',
    action: 'Create Vouchers',
    path: '/vouchers',
  },
  {
    icon: Smartphone,
    title: 'Track mobile money',
    body: 'Mobile money purchases land in Transactions with the phone number, voucher code, amount, and payment status.',
    action: 'View Transactions',
    path: '/transactions',
  },
  {
    icon: Paintbrush,
    title: 'Customize captive page',
    body: 'Use Captive Page under Manage Router to download or adjust the hotspot files your customers see at login.',
    action: 'Customize Page',
    path: '/captive-portal',
  },
  {
    icon: Router,
    title: 'Onboard the router',
    body: 'Use Provisioning to generate the MikroTik setup, then Monitor Router and Clients to confirm live data is flowing.',
    action: 'Open Provisioning',
    path: '/provisioning',
  },
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

export function Dashboard() {
  const { user, isAdmin } = useAuth();
  const { selectedSite } = useSite();
  const [sites, setSites] = useState<Record<string, SiteStat>>({});
  const [txs, setTxs] = useState<TxRow[]>([]);
  const [clients, setClients] = useState<Client[]>([]);
  const [topSalesAgents, setTopSalesAgents] = useState<TopSalesAgent[]>([]);
  const [summary, setSummary] = useState({
    totalEarnings: 0,
    voucherAmount: 0,
    mobileMoneyAmount: 0,
  });
  const [loading, setLoading] = useState(true);
  const [dateFilter, setDateFilter] = useState<DateFilter>('today');
  const [lastUpdated, setLastUpdated] = useState<Date | null>(null);
  const [deviceStats, setDeviceStats] = useState<DeviceStats>({ total_routers: 0, online_routers: 0, total_clients: 0, active_connections: 0, avg_cpu: 0, avg_memory: 0, bandwidth_up: 0, bandwidth_down: 0 });
  const [showTour, setShowTour] = useState(false);
  const [tourStep, setTourStep] = useState(0);

  const getAuthHeaders = (): HeadersInit => {
    const token = localStorage.getItem('tenant_token');
    const headers: HeadersInit = {
      'Content-Type': 'application/json',
      'Accept': 'application/json',
    };
    if (token) headers['Authorization'] = `Bearer ${token}`;
    if (selectedSite?.id) headers['X-Site-ID'] = String(selectedSite.id);
    return headers;
  };

  const canUse = (permission: string) => user?.role !== 'sub_user' || Boolean(user.permissions?.includes(permission));

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
      const params = new URLSearchParams({ per_page: '10', status: 'success', ...range });
      const performancePeriod = dateFilter === 'all'
        ? 'six_months'
        : dateFilter === 'week'
          ? 'week'
          : dateFilter === 'month'
            ? 'month'
            : dateFilter;
      const canViewClients = canUse('view_clients');
      const canViewRouters = canUse('view_routers');
      const canViewTransactions = canUse('view_transactions');
      const canManageVouchers = canUse('manage_vouchers');
      const canViewFinancials = canViewTransactions || canManageVouchers;

      // Fetch stats and filtered transactions
      const [statsRes, txResponse, performanceStats, voucherStats] = await Promise.all([
        apiStats(),
        canViewTransactions ? fetch(`${API_BASE}/transactions?${params.toString()}`, { headers }) : Promise.resolve(null),
        canViewFinancials ? getPerformanceAnalytics(performancePeriod) : Promise.resolve({ summary: {} }),
        canManageVouchers ? getVoucherStatistics() : Promise.resolve({ by_sales_point: [] }),
      ]);
      const txRes = txResponse?.ok ? await txResponse.json() : { data: [] };
      const transactions = txRes.transactions ?? txRes.data ?? [];
      setTxs(transactions);
      const agents = [...(voucherStats.by_sales_point || [])]
        .sort((a: TopSalesAgent, b: TopSalesAgent) => Number(b.revenue || 0) - Number(a.revenue || 0))
        .slice(0, 5);
      setTopSalesAgents(agents);

      const voucherAmount = dateFilter === 'all'
        ? Number(voucherStats.total_revenue ?? 0)
        : Number(performanceStats.summary?.voucher_total ?? 0);
      const mobileMoneyAmount = dateFilter === 'all'
        ? Number(statsRes.total_revenue ?? 0)
        : Number(performanceStats.summary?.mobile_money_total ?? 0);
      const totalEarnings = dateFilter === 'all'
        ? voucherAmount + mobileMoneyAmount
        : Number(performanceStats.summary?.combined_total ?? 0);

      setSummary({
        totalEarnings: canViewFinancials ? totalEarnings : 0,
        voucherAmount: canManageVouchers ? voucherAmount : 0,
        mobileMoneyAmount: canViewTransactions ? mobileMoneyAmount : 0,
      });

      const groupedSites = transactions.reduce((acc: Record<string, SiteStat>, tx: TxRow) => {
        const site = tx.origin_site || 'Default Site';
        const amount = tx.status === 'success' ? parseFloat(tx.amount || '0') : 0;
        if (!acc[site]) {
          acc[site] = { total_amount: 0, today_amount: 0, week_amount: 0, withdrawn: 0, pending_withdraw: 0, total_sales: 0 };
        }
        acc[site].total_amount += amount;
        acc[site].today_amount += amount;
        acc[site].total_sales += tx.status === 'success' ? 1 : 0;
        return acc;
      }, {});
      setSites(Object.keys(groupedSites).length ? groupedSites : (statsRes.sites ?? {}));

      // Fetch active clients from the router snapshot path.
      let activeClientCount = 0;
      try {
        if (!canViewClients) {
          setClients([]);
        } else {
          const clientsRes = await fetch(`${API_BASE}/clients?limit=10`, { headers });
        
          if (clientsRes.ok) {
            const clientsData = await clientsRes.json();

            const activeClients = (clientsData.clients || clientsData.data || []).map((user: any) => ({
              id: user.id,
              mac_address: user.mac_address,
              username: user.username,
              hostname: user.hostname || '',
              ip_address: user.ip_address,
              voucher_code: user.voucher_code || user.username || '',
              total_sessions: user.total_sessions || 0,
              total_spent: user.total_spent || 0,
              last_seen: user.last_seen,
              status: user.status || 'active'
            }));
          
            activeClientCount = Number(clientsData.total ?? activeClients.length);
            setClients(activeClients);
          } else {
            setClients([]);
          }
        }
      } catch (err) {
        console.error('Active clients fetch error:', err);
        setClients([]);
      }

      // Fetch device stats from telemetry endpoint
      try {
        if (!canViewRouters) {
          setDeviceStats({ total_routers: 0, online_routers: 0, total_clients: activeClientCount, active_connections: 0, avg_cpu: 0, avg_memory: 0, bandwidth_up: 0, bandwidth_down: 0 });
        } else {
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
        }
      } catch (err) {
        console.error('Telemetry fetch error:', err);
      }

      setLastUpdated(new Date());
    } catch (e) {
      console.error(e);
    } finally {
      setLoading(false);
    }
  }, [dateFilter, selectedSite?.id, user?.role, user?.permissions]);

  useEffect(() => {
    load();
    const iv = setInterval(load, 300000); // Refresh every 5 minutes
    return () => clearInterval(iv);
  }, [load]);

  useEffect(() => {
    const completed = localStorage.getItem(TOUR_STORAGE_KEY);
    if (!completed) {
      setShowTour(true);
    }
  }, []);

  const closeTour = () => {
    localStorage.setItem(TOUR_STORAGE_KEY, '1');
    setShowTour(false);
  };

  const siteList = Object.entries(sites);
  const periodLabel = DATE_FILTERS.find((f) => f.id === dateFilter)?.label || 'Period';

  if (loading) {
    return (
      <div className="flex items-center justify-center h-64">
        <RefreshCw className="w-6 h-6 text-primary animate-spin" />
      </div>
    );
  }

  return (
    <div className="p-4 sm:p-6 lg:p-8">
      <div className="mb-4 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <div className="flex flex-wrap items-center gap-2">
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
        <div className="flex items-center gap-2 text-xs text-muted-foreground">
          <button
            onClick={() => {
              setTourStep(0);
              setShowTour(true);
            }}
            className="inline-flex items-center gap-1 rounded-lg border border-border px-2.5 py-1.5 text-xs text-card-foreground hover:bg-muted transition-colors"
          >
            <HelpCircle className="w-3.5 h-3.5" />
            Tour
          </button>
          <RefreshCw className="w-3 h-3" />
          {lastUpdated ? `Updated ${lastUpdated.toLocaleTimeString()}` : 'Loading...'}
        </div>
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
      <div className="grid grid-cols-1 sm:grid-cols-3 gap-4 sm:gap-6 mb-6">
        <StatsCard title="Total Earnings" value={fmt(summary.totalEarnings)} icon={DollarSign} trend={{ value: periodLabel, isPositive: true }} action={{ label: 'View Statistics', to: '/performance' }} />
        <StatsCard title="Vouchers" value={fmt(summary.voucherAmount)} icon={Users} trend={{ value: periodLabel, isPositive: true }} action={{ label: 'Create Vouchers', to: '/vouchers' }} />
        <StatsCard title="Mobile Money" value={fmt(summary.mobileMoneyAmount)} icon={TrendingUp} trend={{ value: periodLabel, isPositive: true }} action={{ label: 'View Transactions', to: '/transactions' }} />
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
                    <span className="opacity-80">{periodLabel}</span>
                    <span className="font-semibold">{fmt(stat.today_amount)}</span>
                  </div>
                </div>
              </div>
            ))}
          </div>
        </div>
      )}

      {/* Clients and Recent Transactions - Side by Side */}
      <div className="grid grid-cols-1 lg:grid-cols-2 gap-6 items-stretch">
        {/* Active Clients */}
        <div className="bg-card border border-border rounded-lg p-4 sm:p-6 flex min-h-[520px] flex-col">
          <div className="flex items-center justify-between mb-4">
            <div className="flex items-center gap-2">
              <User className="w-5 h-5 text-primary" />
              <div>
                <h2 className="text-lg font-semibold text-card-foreground">Active Clients ({deviceStats.total_clients})</h2>
                <p className="text-xs text-muted-foreground">
                  {lastUpdated ? `Last updated ${lastUpdated.toLocaleTimeString()}; refreshes every 5 minutes` : 'Refreshing every 5 minutes'}
                </p>
              </div>
            </div>
          </div>

          <div className="flex-1">
            {clients.length === 0 ? (
              <div className="py-8 text-center">
                <User className="w-10 h-10 text-muted-foreground mx-auto mb-2" />
                <p className="text-sm text-muted-foreground">No clients yet</p>
              </div>
            ) : (
              <div className="min-w-0">
                <div className="grid grid-cols-[1.2fr_1fr_1fr] gap-3 border-b border-border px-3 pb-2 text-[11px] font-semibold uppercase text-muted-foreground">
                  <span>Device</span>
                  <span>Voucher</span>
                  <span>IP Address</span>
                </div>
                <div className="divide-y divide-border/60">
                  {clients.slice(0, 10).map((client, i) => {
                    const voucher = String(client.voucher_code || client.username || '').trim();

                    return (
                      <div
                        key={client.id || i}
                        className="grid grid-cols-[1.2fr_1fr_1fr] gap-3 px-3 py-3 hover:bg-muted/40 transition-colors"
                      >
                        <div className="flex min-w-0 items-center gap-2">
                          <span className="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-primary/10">
                            <User className="w-4 h-4 text-primary" />
                          </span>
                          <span className="min-w-0 truncate text-sm font-medium text-card-foreground">
                            {client.hostname || ''}
                          </span>
                        </div>
                        <span className="truncate text-sm text-primary">{voucher.toLowerCase() === 'default' ? '' : voucher}</span>
                        <span className="truncate font-mono text-xs text-muted-foreground">{client.ip_address || ''}</span>
                      </div>
                    );
                  })}
                </div>
              </div>
            )}
          </div>
          <Link to="/clients" className="mt-4 flex items-center justify-center gap-1 rounded-lg border border-border px-3 py-2 text-sm text-primary hover:bg-muted transition-colors">
            View more <ArrowRight className="w-4 h-4" />
          </Link>
        </div>

        {/* Recent Transactions */}
        <div className="bg-card border border-border rounded-lg p-4 sm:p-6 flex min-h-[520px] flex-col">
          <div className="flex items-center justify-between mb-4">
            <div className="flex items-center gap-2">
              <Smartphone className="w-5 h-5 text-primary" />
              <h2 className="text-lg font-semibold text-card-foreground">Recent Transactions</h2>
            </div>
          </div>

          <div className="flex-1">
            {txs.length === 0 ? (
              <div className="py-8 text-center">
                <DollarSign className="w-10 h-10 text-muted-foreground mx-auto mb-2" />
                <p className="text-sm text-muted-foreground">No transactions found</p>
              </div>
            ) : (
              <div className="min-w-0">
                <div className="grid grid-cols-[2.2rem_1.2fr_1fr_1fr] gap-3 border-b border-border px-3 pb-2 text-[11px] font-semibold uppercase text-muted-foreground">
                  <span />
                  <span>Number</span>
                  <span>Voucher Code</span>
                  <span className="text-right">Amount</span>
                </div>
                <div className="divide-y divide-border/60">
                  {txs.slice(0, 10).map((tx, i) => (
                    <div key={`${tx.id}-${i}`} className="grid grid-cols-[2.2rem_1.2fr_1fr_1fr] gap-3 px-3 py-3 hover:bg-muted/40 transition-colors">
                      <span className={`flex h-8 w-8 items-center justify-center rounded-full ${
                        tx.status === 'success' ? 'bg-emerald-500/10 text-emerald-500' :
                        tx.status === 'pending' ? 'bg-yellow-500/10 text-yellow-500' : 'bg-destructive/10 text-destructive'
                      }`}>
                        <Smartphone className="w-4 h-4" />
                      </span>
                      <div className="min-w-0">
                        <p className="truncate text-sm font-medium text-card-foreground">{tx.msisdn}</p>
                        <p className="text-xs text-muted-foreground">{new Date(tx.created_at).toLocaleString('en-GB', { day:'2-digit', month:'short', hour:'2-digit', minute:'2-digit' })}</p>
                      </div>
                      <span className="truncate text-sm text-primary">{tx.voucher_code || ''}</span>
                      <span className="text-right text-sm font-semibold text-card-foreground">{fmt(parseFloat(tx.amount || '0'))}</span>
                    </div>
                  ))}
                </div>
              </div>
            )}
          </div>
          <Link to="/transactions" className="mt-4 flex items-center justify-center gap-1 rounded-lg border border-border px-3 py-2 text-sm text-primary hover:bg-muted transition-colors">
            View more <ArrowRight className="w-4 h-4" />
          </Link>
        </div>
      </div>

      {showTour && (
        <div className="fixed inset-0 z-50 flex items-end justify-center bg-black/55 p-3 sm:items-center">
          <div className="w-full max-w-lg rounded-lg border border-border bg-card shadow-2xl">
            <div className="flex items-center justify-between border-b border-border p-4">
              <div className="flex items-center gap-2">
                <span className="flex h-9 w-9 items-center justify-center rounded-lg bg-primary/10">
                  {(() => {
                    const Icon = TOUR_STEPS[tourStep].icon;
                    return <Icon className="h-5 w-5 text-primary" />;
                  })()}
                </span>
                <div>
                  <p className="text-xs text-muted-foreground">Getting started</p>
                  <h3 className="font-semibold text-card-foreground">{TOUR_STEPS[tourStep].title}</h3>
                </div>
              </div>
              <button onClick={closeTour} className="rounded-lg p-2 text-muted-foreground hover:bg-muted" title="Close tour">
                <X className="h-4 w-4" />
              </button>
            </div>

            <div className="p-4 sm:p-5">
              <p className="text-sm leading-6 text-muted-foreground">{TOUR_STEPS[tourStep].body}</p>

              <div className="mt-5 grid grid-cols-4 gap-2">
                {TOUR_STEPS.map((step, index) => (
                  <button
                    key={step.title}
                    onClick={() => setTourStep(index)}
                    className={`h-1.5 rounded-full transition-colors ${index <= tourStep ? 'bg-primary' : 'bg-muted'}`}
                    aria-label={`Go to tour step ${index + 1}`}
                  />
                ))}
              </div>

              <div className="mt-5 flex flex-col-reverse gap-2 sm:flex-row sm:items-center sm:justify-between">
                <button onClick={closeTour} className="rounded-lg px-3 py-2 text-sm text-muted-foreground hover:bg-muted">
                  Skip
                </button>
                <div className="flex gap-2">
                  <button
                    onClick={() => setTourStep((step) => Math.max(0, step - 1))}
                    disabled={tourStep === 0}
                    className="flex-1 rounded-lg border border-border px-3 py-2 text-sm text-card-foreground hover:bg-muted disabled:opacity-50 sm:flex-none"
                  >
                    Back
                  </button>
                  {tourStep < TOUR_STEPS.length - 1 ? (
                    <button
                      onClick={() => setTourStep((step) => Math.min(TOUR_STEPS.length - 1, step + 1))}
                      className="flex-1 rounded-lg bg-primary px-3 py-2 text-sm text-primary-foreground hover:bg-primary/90 sm:flex-none"
                    >
                      Next
                    </button>
                  ) : (
                    <button
                      onClick={closeTour}
                      className="flex flex-1 items-center justify-center gap-2 rounded-lg bg-primary px-3 py-2 text-sm text-primary-foreground hover:bg-primary/90 sm:flex-none"
                    >
                      <CheckCircle2 className="h-4 w-4" />
                      Done
                    </button>
                  )}
                </div>
              </div>

              <Link
                to={TOUR_STEPS[tourStep].path}
                onClick={closeTour}
                className="mt-3 flex items-center justify-center gap-2 rounded-lg border border-border px-3 py-2 text-sm text-primary hover:bg-muted"
              >
                {TOUR_STEPS[tourStep].action}
                <ArrowRight className="h-4 w-4" />
              </Link>
            </div>
          </div>
        </div>
      )}

      {topSalesAgents.length > 0 && (
        <div className="bg-card border border-border rounded-lg p-4 sm:p-6 mt-6">
          <div className="flex items-center justify-between gap-3 mb-4">
            <div>
              <h2 className="text-lg font-semibold text-card-foreground">Sales Points</h2>
              <p className="text-sm text-muted-foreground">Physical voucher performance from the active site's sales agents.</p>
            </div>
            <Link to="/vouchers" className="flex items-center gap-1 text-sm text-primary hover:text-primary/80 transition-colors">
              Manage vouchers <ArrowRight className="w-4 h-4" />
            </Link>
          </div>
          <div className="grid md:grid-cols-2 xl:grid-cols-5 gap-3">
            {topSalesAgents.map((agent, index) => (
              <div key={agent.id || agent.name} className="rounded-lg border border-border bg-muted/20 p-4">
                <div className="flex items-center justify-between gap-2 mb-3">
                  <p className="font-semibold text-card-foreground truncate">{agent.name}</p>
                  <span className="text-xs rounded-full bg-primary/10 text-primary px-2 py-1">#{index + 1}</span>
                </div>
                <p className="text-xl font-bold text-card-foreground">{fmt(Number(agent.revenue || 0))}</p>
                <div className="mt-3 text-xs text-muted-foreground space-y-1">
                  <div className="flex justify-between"><span>Sold</span><span className="text-card-foreground">{Number((agent.used || 0) + (agent.in_use || 0)).toLocaleString()}</span></div>
                  <div className="flex justify-between"><span>Stock</span><span className="text-card-foreground">{Number(agent.total_vouchers || 0).toLocaleString()}</span></div>
                </div>
              </div>
            ))}
          </div>
        </div>
      )}
    </div>
  );
}
