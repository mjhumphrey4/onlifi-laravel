import { useCallback, useEffect, useMemo, useState } from 'react';
import { Bar, BarChart, CartesianGrid, Cell, Legend, Pie, PieChart, ResponsiveContainer, Tooltip, XAxis, YAxis } from 'recharts';
import { Calendar, RefreshCw, Smartphone, Ticket, TrendingUp, Trophy, Users } from 'lucide-react';
import { getPerformanceAnalytics, getVoucherStatistics } from '../utils/api';
import { useSite } from '../context/SiteContext';

type Channel = 'mobile_money' | 'vouchers';
type Period = 'today' | 'yesterday' | 'week' | 'month' | 'three_months' | 'six_months';

interface BreakdownRow {
  key: string;
  label: string;
  mobile_money_total: number;
  mobile_money_transactions: number;
  voucher_total: number;
  vouchers_sold: number;
}

interface VoucherTypeRow {
  name: string;
  sold: number;
  revenue: number;
}

interface SalesPointStats {
  name: string;
  total_vouchers: number;
  used: number;
  in_use?: number;
  revenue: number;
}

const PERIODS: { id: Period; label: string }[] = [
  { id: 'today', label: 'Today' },
  { id: 'yesterday', label: 'Yesterday' },
  { id: 'week', label: 'This Week' },
  { id: 'month', label: 'This Month' },
  { id: 'three_months', label: 'Last 3 Months' },
  { id: 'six_months', label: 'Last 6 Months' },
];

const PIE_COLORS = ['#10b981', '#3b82f6', '#f59e0b', '#ef4444', '#8b5cf6', '#14b8a6', '#f97316', '#64748b'];

function fmt(n: number) {
  return 'UGX ' + Math.round(Number(n || 0)).toLocaleString();
}

function MetricCard({ icon: Icon, label, value }: { icon: any; label: string; value: string }) {
  return (
    <div className="bg-card border border-border rounded-lg p-4 sm:p-6">
      <div className="flex items-center gap-3 mb-2">
        <div className="w-9 h-9 bg-primary/10 rounded-lg flex items-center justify-center">
          <Icon className="w-5 h-5 text-primary" />
        </div>
        <p className="text-sm text-muted-foreground">{label}</p>
      </div>
      <h3 className="text-2xl font-semibold text-card-foreground">{value}</h3>
    </div>
  );
}

export function AnalyzePerformance() {
  const { selectedSite } = useSite();
  const [activeTab, setActiveTab] = useState<Channel>('mobile_money');
  const [period, setPeriod] = useState<Period>('today');
  const [loading, setLoading] = useState(true);
  const [breakdown, setBreakdown] = useState<BreakdownRow[]>([]);
  const [summary, setSummary] = useState({
    mobile_money_total: 0,
    mobile_money_transactions: 0,
    voucher_total: 0,
    vouchers_sold: 0,
    combined_total: 0,
  });
  const [topVoucherTypes, setTopVoucherTypes] = useState<VoucherTypeRow[]>([]);
  const [topSalesPoints, setTopSalesPoints] = useState<SalesPointStats[]>([]);

  const load = useCallback(async () => {
    setLoading(true);
    try {
      const [analytics, voucherStats] = await Promise.all([
        getPerformanceAnalytics(period),
        getVoucherStatistics(),
      ]);
      setSummary(analytics.summary || {});
      setBreakdown(analytics.breakdown || []);
      setTopVoucherTypes(analytics.top_voucher_types || []);
      setTopSalesPoints([...(voucherStats.by_sales_point || [])]
        .sort((a: SalesPointStats, b: SalesPointStats) => Number(b.revenue || 0) - Number(a.revenue || 0))
        .slice(0, 5));
    } catch (error) {
      console.error('Failed to load performance analytics:', error);
      setBreakdown([]);
      setTopVoucherTypes([]);
      setTopSalesPoints([]);
    } finally {
      setLoading(false);
    }
  }, [period, selectedSite?.id]);

  useEffect(() => {
    load();
  }, [load]);

  const chartData = useMemo(() => breakdown.map((row) => ({
    label: row.label,
    amount: activeTab === 'mobile_money' ? row.mobile_money_total : row.voucher_total,
    count: activeTab === 'mobile_money' ? row.mobile_money_transactions : row.vouchers_sold,
  })), [breakdown, activeTab]);

  const channelStats = activeTab === 'mobile_money'
    ? {
        totalLabel: 'Mobile Money Total',
        total: summary.mobile_money_total,
        countLabel: 'Successful Transactions',
        count: summary.mobile_money_transactions,
        avgLabel: 'Average Transaction',
        avg: summary.mobile_money_transactions ? summary.mobile_money_total / summary.mobile_money_transactions : 0,
        icon: Smartphone,
      }
    : {
        totalLabel: 'Voucher Revenue',
        total: summary.voucher_total,
        countLabel: 'Vouchers Sold',
        count: summary.vouchers_sold,
        avgLabel: 'Average Voucher',
        avg: summary.vouchers_sold ? summary.voucher_total / summary.vouchers_sold : 0,
        icon: Ticket,
      };

  const breakdownTitle = period === 'today' || period === 'yesterday'
    ? 'Hourly Breakdown'
    : 'Period Breakdown';

  return (
    <div className="p-4 sm:p-6 lg:p-8">
      <div className="flex flex-col sm:flex-row justify-between items-start sm:items-center gap-4 mb-6">
        <div>
          <h1 className="text-2xl font-bold text-foreground">Analyze Performance</h1>
          <p className="text-sm text-muted-foreground mt-1">Track mobile money and physical voucher performance for {selectedSite?.name || 'the active site'}.</p>
        </div>
        <button onClick={load} className="inline-flex items-center gap-2 px-4 py-2 rounded-lg border border-border hover:bg-muted">
          <RefreshCw className={`w-4 h-4 ${loading ? 'animate-spin' : ''}`} />
          Refresh
        </button>
      </div>

      <div className="flex flex-wrap gap-2 mb-6">
        {PERIODS.map((item) => (
          <button
            key={item.id}
            onClick={() => setPeriod(item.id)}
            className={`px-4 py-2 rounded-lg text-sm transition-colors ${
              period === item.id ? 'bg-primary text-primary-foreground' : 'bg-muted text-muted-foreground hover:bg-muted/80'
            }`}
          >
            {item.label}
          </button>
        ))}
      </div>

      <div className="flex gap-2 mb-6 border-b border-border">
        <button
          onClick={() => setActiveTab('mobile_money')}
          className={`flex items-center gap-2 px-4 py-3 font-medium transition-colors ${
            activeTab === 'mobile_money' ? 'text-primary border-b-2 border-primary' : 'text-muted-foreground hover:text-foreground'
          }`}
        >
          <Smartphone className="w-4 h-4" />
          Mobile Money
        </button>
        <button
          onClick={() => setActiveTab('vouchers')}
          className={`flex items-center gap-2 px-4 py-3 font-medium transition-colors ${
            activeTab === 'vouchers' ? 'text-primary border-b-2 border-primary' : 'text-muted-foreground hover:text-foreground'
          }`}
        >
          <Ticket className="w-4 h-4" />
          Vouchers
        </button>
      </div>

      <div className="grid grid-cols-1 sm:grid-cols-3 gap-4 sm:gap-6 mb-6">
        <MetricCard icon={channelStats.icon} label={channelStats.totalLabel} value={fmt(channelStats.total)} />
        <MetricCard icon={Users} label={channelStats.countLabel} value={channelStats.count.toLocaleString()} />
        <MetricCard icon={TrendingUp} label={channelStats.avgLabel} value={fmt(channelStats.avg)} />
      </div>

      <div className="grid xl:grid-cols-[1.4fr_0.8fr] gap-6 mb-6">
        <div className="bg-card border border-border rounded-lg p-4 sm:p-6">
          <div className="flex items-center gap-2 mb-6">
            <Calendar className="w-5 h-5 text-primary" />
            <h2 className="text-lg text-card-foreground">{breakdownTitle}</h2>
          </div>
          {loading ? (
            <div className="flex items-center justify-center h-64">
              <RefreshCw className="w-6 h-6 text-primary animate-spin" />
            </div>
          ) : (
            <ResponsiveContainer width="100%" height={300}>
              <BarChart data={chartData} margin={{ top: 5, right: 10, left: 10, bottom: 5 }}>
                <CartesianGrid strokeDasharray="3 3" stroke="rgba(148,163,184,0.18)" />
                <XAxis dataKey="label" tick={{ fill: '#94A3B8', fontSize: 12 }} axisLine={false} tickLine={false} />
                <YAxis tick={{ fill: '#94A3B8', fontSize: 11 }} axisLine={false} tickLine={false} tickFormatter={(v) => v >= 1000 ? `${(v / 1000).toFixed(0)}k` : String(v)} />
                <Tooltip formatter={(value: number) => fmt(value)} cursor={{ fill: 'rgba(16,185,129,0.05)' }} />
                <Bar dataKey="amount" fill={activeTab === 'mobile_money' ? '#3b82f6' : '#10b981'} radius={[4, 4, 0, 0]} maxBarSize={48} />
              </BarChart>
            </ResponsiveContainer>
          )}
        </div>

        <div className="bg-card border border-border rounded-lg p-4 sm:p-6">
          <h2 className="text-lg text-card-foreground mb-4">Most Selling Voucher Types</h2>
          {topVoucherTypes.length === 0 ? (
            <div className="h-[300px] grid place-items-center text-sm text-muted-foreground">No voucher sales yet.</div>
          ) : (
            <ResponsiveContainer width="100%" height={300}>
              <PieChart>
                <Pie data={topVoucherTypes} dataKey="sold" nameKey="name" outerRadius={95} label>
                  {topVoucherTypes.map((_, index) => (
                    <Cell key={index} fill={PIE_COLORS[index % PIE_COLORS.length]} />
                  ))}
                </Pie>
                <Tooltip formatter={(value: number, _name, item: any) => [`${value} sold`, item?.payload?.name]} />
                <Legend />
              </PieChart>
            </ResponsiveContainer>
          )}
        </div>
      </div>

      {activeTab === 'vouchers' && topSalesPoints.length > 0 && (
        <div className="bg-card border border-border rounded-lg p-4 sm:p-6">
          <div className="flex items-center gap-3 mb-4">
            <div className="w-9 h-9 bg-yellow-500/10 rounded-lg flex items-center justify-center">
              <Trophy className="w-5 h-5 text-yellow-500" />
            </div>
            <div>
              <h2 className="text-lg font-semibold text-card-foreground">Top Sales Agents</h2>
              <p className="text-xs text-muted-foreground">Shown only under physical vouchers.</p>
            </div>
          </div>
          <div className="grid md:grid-cols-2 xl:grid-cols-5 gap-3">
            {topSalesPoints.map((point, index) => (
              <div key={point.name} className="rounded-lg border border-border bg-muted/20 p-4">
                <div className="flex items-center justify-between gap-2 mb-3">
                  <p className="font-semibold text-card-foreground truncate">{point.name}</p>
                  <span className="text-xs rounded-full bg-primary/10 text-primary px-2 py-1">#{index + 1}</span>
                </div>
                <p className="text-xl font-bold text-card-foreground">{fmt(point.revenue)}</p>
                <p className="text-xs text-muted-foreground mt-2">{Number((point.used || 0) + (point.in_use || 0)).toLocaleString()} sold from {Number(point.total_vouchers || 0).toLocaleString()} vouchers</p>
              </div>
            ))}
          </div>
        </div>
      )}
    </div>
  );
}
