import { useState, useEffect, useCallback } from 'react';
import {
  BarChart, Bar, PieChart, Pie, Cell, LineChart, Line,
  XAxis, YAxis, CartesianGrid, Tooltip, ResponsiveContainer, Legend
} from 'recharts';
import { BarChart3, TrendingUp, DollarSign, ShoppingCart, RefreshCw } from 'lucide-react';
import { apiVoucherAnalytics } from '../utils/api';
import { useAuth } from '../context/AuthContext';

interface VoucherData {
  type: string;
  count: number;
  amount: number;
  fees: number;
  percentage: number;
}

interface TimeSeriesData {
  label: string;
  count: number;
  amount: number;
  date?: string;
}

interface Summary {
  totalAmount: number;
  totalTransactions: number;
  totalFees: number;
  netAmount: number;
}

const COLORS = ['#10B981', '#3B82F6', '#F59E0B', '#EF4444', '#8B5CF6', '#EC4899', '#14B8A6', '#F97316'];

function fmt(n: number) {
  return 'UGX ' + Math.round(n).toLocaleString();
}

function CustomTooltip({ active, payload, label }: { active?: boolean; payload?: { value: number; name: string; color: string }[]; label?: string }) {
  if (!active || !payload?.length) return null;
  return (
    <div className="bg-card border border-border rounded-lg p-3 shadow-lg">
      <p className="text-xs text-muted-foreground mb-2">{label}</p>
      {payload.map((p, i) => (
        <p key={i} className="text-sm font-semibold" style={{ color: p.color }}>
          {p.name}: {p.name === 'Sales' ? p.value : fmt(p.value)}
        </p>
      ))}
    </div>
  );
}

function PieTooltip({ active, payload }: { active?: boolean; payload?: { payload: VoucherData }[] }) {
  if (!active || !payload?.length) return null;
  const data = payload[0].payload;
  return (
    <div className="bg-card border border-border rounded-lg p-3 shadow-lg">
      <p className="text-sm font-semibold text-foreground mb-1">{data.type}</p>
      <p className="text-xs text-muted-foreground">{data.count} sales • {fmt(data.amount)}</p>
      <p className="text-xs text-primary font-semibold">{data.percentage}% of total</p>
    </div>
  );
}

export function PerformanceGraphs() {
  const { userSites } = useAuth();
  const sites = userSites();

  const [period, setPeriod] = useState<'today' | 'week' | 'month'>('today');
  const [selectedSite, setSelectedSite] = useState('');
  const [vouchers, setVouchers] = useState<VoucherData[]>([]);
  const [timeSeries, setTimeSeries] = useState<TimeSeriesData[]>([]);
  const [summary, setSummary] = useState<Summary>({ totalAmount: 0, totalTransactions: 0, totalFees: 0, netAmount: 0 });
  const [loading, setLoading] = useState(true);

  const load = useCallback(async () => {
    setLoading(true);
    try {
      const res = await apiVoucherAnalytics({ site: selectedSite, period });
      setVouchers(res.vouchers ?? []);
      setTimeSeries(res.timeSeries ?? []);
      setSummary(res.summary ?? { totalAmount: 0, totalTransactions: 0, totalFees: 0, netAmount: 0 });
    } catch (e) {
      console.error(e);
    } finally {
      setLoading(false);
    }
  }, [selectedSite, period]);

  useEffect(() => {
    load();
  }, [load]);

  const periodLabel = period === 'today' ? "Today's" : period === 'week' ? 'This Week\'s' : 'This Month\'s';

  return (
    <div className="p-4 sm:p-6 lg:p-8">
      <div className="mb-6 sm:mb-8">
        <h1 className="text-2xl sm:text-3xl text-foreground mb-2">Performance Graphs</h1>
        <p className="text-sm sm:text-base text-muted-foreground">Analyze voucher sales and revenue trends</p>
      </div>

      {/* Period Tabs & Site Filter */}
      <div className="flex flex-col sm:flex-row gap-3 mb-6">
        <div className="flex gap-2">
          {(['today', 'week', 'month'] as const).map((p) => (
            <button
              key={p}
              onClick={() => setPeriod(p)}
              className={`px-4 py-2 rounded-lg text-sm capitalize transition-colors ${
                period === p
                  ? 'bg-primary text-primary-foreground'
                  : 'bg-muted text-muted-foreground hover:bg-muted/80'
              }`}
            >
              {p === 'today' ? 'Today' : p === 'week' ? 'This Week' : 'This Month'}
            </button>
          ))}
        </div>
        {sites.length > 1 && (
          <select
            value={selectedSite}
            onChange={(e) => setSelectedSite(e.target.value)}
            className="px-4 py-2 bg-input-background border border-border rounded-lg text-foreground focus:outline-none focus:ring-2 focus:ring-ring text-sm"
          >
            <option value="">All Sites</option>
            {sites.map((s) => (
              <option key={s} value={s}>{s}</option>
            ))}
          </select>
        )}
        <button
          onClick={load}
          disabled={loading}
          className="px-4 py-2 bg-muted text-muted-foreground hover:bg-muted/80 rounded-lg text-sm flex items-center gap-2 transition-colors"
        >
          <RefreshCw className={`w-4 h-4 ${loading ? 'animate-spin' : ''}`} />
          Refresh
        </button>
      </div>

      {/* Summary Cards */}
      <div className="grid grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
        <div className="bg-card border border-border rounded-lg p-4">
          <div className="flex items-center gap-2 mb-2">
            <div className="w-8 h-8 bg-primary/10 rounded-lg flex items-center justify-center">
              <DollarSign className="w-4 h-4 text-primary" />
            </div>
            <p className="text-xs text-muted-foreground">Total Revenue</p>
          </div>
          <h3 className="text-xl font-bold text-card-foreground">{fmt(summary.totalAmount)}</h3>
        </div>
        <div className="bg-card border border-border rounded-lg p-4">
          <div className="flex items-center gap-2 mb-2">
            <div className="w-8 h-8 bg-emerald-500/10 rounded-lg flex items-center justify-center">
              <TrendingUp className="w-4 h-4 text-emerald-500" />
            </div>
            <p className="text-xs text-muted-foreground">Net Revenue</p>
          </div>
          <h3 className="text-xl font-bold text-emerald-500">{fmt(summary.netAmount)}</h3>
        </div>
        <div className="bg-card border border-border rounded-lg p-4">
          <div className="flex items-center gap-2 mb-2">
            <div className="w-8 h-8 bg-blue-500/10 rounded-lg flex items-center justify-center">
              <ShoppingCart className="w-4 h-4 text-blue-500" />
            </div>
            <p className="text-xs text-muted-foreground">Total Sales</p>
          </div>
          <h3 className="text-xl font-bold text-blue-500">{summary.totalTransactions.toLocaleString()}</h3>
        </div>
        <div className="bg-card border border-border rounded-lg p-4">
          <div className="flex items-center gap-2 mb-2">
            <div className="w-8 h-8 bg-orange-500/10 rounded-lg flex items-center justify-center">
              <BarChart3 className="w-4 h-4 text-orange-500" />
            </div>
            <p className="text-xs text-muted-foreground">Telecom Charges</p>
          </div>
          <h3 className="text-xl font-bold text-orange-500">{fmt(summary.totalFees)}</h3>
        </div>
      </div>

      {loading ? (
        <div className="flex items-center justify-center h-64">
          <RefreshCw className="w-8 h-8 text-primary animate-spin" />
        </div>
      ) : (
        <>
          {/* Charts Row */}
          <div className="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-6">
            {/* Sales Over Time Chart */}
            <div className="bg-card border border-border rounded-lg p-4 sm:p-6">
              <h2 className="text-lg text-card-foreground mb-4">
                {periodLabel} Sales Trend
              </h2>
              {timeSeries.length > 0 ? (
                <ResponsiveContainer width="100%" height={280}>
                  <LineChart data={timeSeries} margin={{ top: 5, right: 10, left: 10, bottom: 5 }}>
                    <CartesianGrid strokeDasharray="3 3" stroke="rgba(255,255,255,0.05)" />
                    <XAxis
                      dataKey="label"
                      tick={{ fill: '#94A3B8', fontSize: 11 }}
                      axisLine={false}
                      tickLine={false}
                      interval={period === 'today' ? 3 : 'preserveStartEnd'}
                    />
                    <YAxis
                      yAxisId="left"
                      tick={{ fill: '#94A3B8', fontSize: 11 }}
                      axisLine={false}
                      tickLine={false}
                      tickFormatter={(v) => v >= 1000 ? `${(v / 1000).toFixed(0)}k` : String(v)}
                    />
                    <YAxis
                      yAxisId="right"
                      orientation="right"
                      tick={{ fill: '#94A3B8', fontSize: 11 }}
                      axisLine={false}
                      tickLine={false}
                    />
                    <Tooltip content={<CustomTooltip />} />
                    <Legend />
                    <Line
                      yAxisId="left"
                      type="monotone"
                      dataKey="amount"
                      name="Revenue"
                      stroke="#10B981"
                      strokeWidth={2}
                      dot={false}
                      activeDot={{ r: 4 }}
                    />
                    <Line
                      yAxisId="right"
                      type="monotone"
                      dataKey="count"
                      name="Sales"
                      stroke="#3B82F6"
                      strokeWidth={2}
                      dot={false}
                      activeDot={{ r: 4 }}
                    />
                  </LineChart>
                </ResponsiveContainer>
              ) : (
                <div className="flex items-center justify-center h-64 text-muted-foreground">
                  No data available for this period
                </div>
              )}
            </div>

            {/* Voucher Type Pie Chart */}
            <div className="bg-card border border-border rounded-lg p-4 sm:p-6">
              <h2 className="text-lg text-card-foreground mb-4">
                Voucher Type Distribution
              </h2>
              {vouchers.length > 0 ? (
                <ResponsiveContainer width="100%" height={280}>
                  <PieChart>
                    <Pie
                      data={vouchers}
                      cx="50%"
                      cy="50%"
                      innerRadius={60}
                      outerRadius={100}
                      paddingAngle={2}
                      dataKey="amount"
                      nameKey="type"
                    >
                      {vouchers.map((_, index) => (
                        <Cell key={`cell-${index}`} fill={COLORS[index % COLORS.length]} />
                      ))}
                    </Pie>
                    <Tooltip content={<PieTooltip />} />
                    <Legend
                      formatter={(value) => <span className="text-xs text-muted-foreground">{value}</span>}
                    />
                  </PieChart>
                </ResponsiveContainer>
              ) : (
                <div className="flex items-center justify-center h-64 text-muted-foreground">
                  No voucher data available
                </div>
              )}
            </div>
          </div>

          {/* Voucher Type Bar Chart */}
          <div className="bg-card border border-border rounded-lg p-4 sm:p-6 mb-6">
            <h2 className="text-lg text-card-foreground mb-4">
              Revenue by Voucher Type
            </h2>
            {vouchers.length > 0 ? (
              <ResponsiveContainer width="100%" height={300}>
                <BarChart data={vouchers} layout="vertical" margin={{ top: 5, right: 30, left: 80, bottom: 5 }}>
                  <CartesianGrid strokeDasharray="3 3" stroke="rgba(255,255,255,0.05)" />
                  <XAxis
                    type="number"
                    tick={{ fill: '#94A3B8', fontSize: 11 }}
                    axisLine={false}
                    tickLine={false}
                    tickFormatter={(v) => v >= 1000 ? `${(v / 1000).toFixed(0)}k` : String(v)}
                  />
                  <YAxis
                    type="category"
                    dataKey="type"
                    tick={{ fill: '#94A3B8', fontSize: 12 }}
                    axisLine={false}
                    tickLine={false}
                    width={70}
                  />
                  <Tooltip content={<CustomTooltip />} />
                  <Bar dataKey="amount" name="Revenue" fill="#10B981" radius={[0, 4, 4, 0]} maxBarSize={32} />
                </BarChart>
              </ResponsiveContainer>
            ) : (
              <div className="flex items-center justify-center h-64 text-muted-foreground">
                No voucher data available
              </div>
            )}
          </div>

          {/* Voucher Type Details Table */}
          <div className="bg-card border border-border rounded-lg p-4 sm:p-6">
            <h2 className="text-lg text-card-foreground mb-4">
              Voucher Type Breakdown
            </h2>
            <div className="overflow-x-auto">
              <table className="w-full">
                <thead>
                  <tr className="border-b border-border">
                    {['Voucher Type', 'Sales Count', 'Revenue', 'Platform Fees', 'Net Revenue', '% of Total'].map((h) => (
                      <th key={h} className="text-left py-3 px-4 text-xs text-muted-foreground whitespace-nowrap">
                        {h}
                      </th>
                    ))}
                  </tr>
                </thead>
                <tbody>
                  {vouchers.length === 0 ? (
                    <tr>
                      <td colSpan={6} className="py-8 text-center text-muted-foreground text-sm">
                        No voucher data available for this period
                      </td>
                    </tr>
                  ) : (
                    vouchers.map((v, i) => (
                      <tr key={v.type} className="border-b border-border/50 hover:bg-muted/50 transition-colors">
                        <td className="py-3 px-4">
                          <div className="flex items-center gap-2">
                            <div
                              className="w-3 h-3 rounded-full"
                              style={{ backgroundColor: COLORS[i % COLORS.length] }}
                            />
                            <span className="text-sm font-medium text-card-foreground">{v.type}</span>
                          </div>
                        </td>
                        <td className="py-3 px-4 text-sm text-card-foreground font-semibold">
                          {v.count.toLocaleString()}
                        </td>
                        <td className="py-3 px-4 text-sm text-card-foreground font-semibold">
                          {fmt(v.amount)}
                        </td>
                        <td className="py-3 px-4 text-sm text-orange-500">
                          -{fmt(v.fees)}
                        </td>
                        <td className="py-3 px-4 text-sm text-primary font-bold">
                          {fmt(v.amount - v.fees)}
                        </td>
                        <td className="py-3 px-4">
                          <div className="flex items-center gap-2">
                            <div className="flex-1 h-2 bg-muted rounded-full overflow-hidden max-w-[100px]">
                              <div
                                className="h-full rounded-full"
                                style={{
                                  width: `${v.percentage}%`,
                                  backgroundColor: COLORS[i % COLORS.length]
                                }}
                              />
                            </div>
                            <span className="text-xs text-muted-foreground w-12">{v.percentage}%</span>
                          </div>
                        </td>
                      </tr>
                    ))
                  )}
                </tbody>
              </table>
            </div>
          </div>
        </>
      )}
    </div>
  );
}
