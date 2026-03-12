import { useState, useEffect, useCallback, useMemo } from 'react';
import { BarChart, Bar, XAxis, YAxis, CartesianGrid, Tooltip, ResponsiveContainer } from 'recharts';
import { TrendingUp, Calendar, RefreshCw, Smartphone, Ticket } from 'lucide-react';
import { apiPerformance } from '../utils/api';
import { useAuth } from '../context/AuthContext';

interface DayData {
  date: string;
  day: string;
  day_num: number;
  amount: number;
  transactions: number;
}

function fmt(n: number) { return 'UGX ' + Math.round(n).toLocaleString(); }

function CustomTooltip({ active, payload, label }: { active?: boolean; payload?: { value: number }[]; label?: string }) {
  if (!active || !payload?.length) return null;
  return (
    <div className="bg-card border border-border rounded-lg p-3 shadow-lg">
      <p className="text-xs text-muted-foreground mb-1">{label}</p>
      <p className="text-sm font-semibold text-primary">{fmt(payload[0].value)}</p>
    </div>
  );
}

export function AnalyzePerformance() {
  const { userSites } = useAuth();
  const sites = userSites();

  const [activeTab, setActiveTab] = useState<'mobile_money' | 'vouchers'>('mobile_money');
  const [viewMode, setViewMode] = useState<'week' | 'month'>('week');
  const [selectedSite, setSelectedSite] = useState(sites[0] ?? '');
  const [data, setData] = useState<DayData[]>([]);
  const [loading, setLoading] = useState(true);

  const load = useCallback(async (site: string, mode: 'week' | 'month') => {
    if (!site) return;
    setLoading(true);
    try {
      const days = mode === 'week' ? 7 : 30;
      const res = await apiPerformance(site, days);
      setData(res.data ?? []);
    } catch (e) { console.error(e); }
    finally { setLoading(false); }
  }, []);

  useEffect(() => { load(selectedSite, viewMode); }, [selectedSite, viewMode, load]);

  const stats = useMemo(() => {
    const total = data.reduce((s, d) => s + d.amount, 0);
    const avg   = data.length ? total / data.length : 0;
    const best  = data.reduce((b, d) => d.amount > b.amount ? d : b, { date: '', day: '', amount: 0, transactions: 0, day_num: 0 });
    return { total, avg, best };
  }, [data]);

  const chartData = data.map((d) => ({
    name: viewMode === 'week' ? d.day : String(d.day_num),
    amount: d.amount,
    date: d.date,
  }));

  const mobileMoneyStats = useMemo(() => {
    const mobileMoneyData = data.filter((d) => d.transactions > 0);
    const total = mobileMoneyData.reduce((s, d) => s + d.amount, 0);
    const avg   = mobileMoneyData.length ? total / mobileMoneyData.length : 0;
    const best  = mobileMoneyData.reduce((b, d) => d.amount > b.amount ? d : b, { date: '', day: '', amount: 0, transactions: 0, day_num: 0 });
    return { total, avg, best };
  }, [data]);

  const voucherStats = useMemo(() => {
    const voucherData = data.filter((d) => d.transactions === 0);
    const total = voucherData.reduce((s, d) => s + d.amount, 0);
    const avg   = voucherData.length ? total / voucherData.length : 0;
    const best  = voucherData.reduce((b, d) => d.amount > b.amount ? d : b, { date: '', day: '', amount: 0, transactions: 0, day_num: 0 });
    return { total, avg, best };
  }, [data]);

  return (
    <div className="p-4 sm:p-6 lg:p-8">
      <div className="flex flex-col sm:flex-row justify-between items-start sm:items-center gap-4 mb-6">
        <div>
          <h1 className="text-2xl font-bold text-foreground">Analyze Performance</h1>
          <p className="text-sm text-muted-foreground mt-1">Track your revenue trends across different channels</p>
        </div>
      </div>

      {/* Controls */}
      <div className="flex flex-col sm:flex-row gap-3 mb-6">
        <div className="flex gap-2">
          {(['week', 'month'] as const).map((m) => (
            <button key={m} onClick={() => setViewMode(m)}
              className={`px-4 py-2 rounded-lg text-sm capitalize transition-colors ${viewMode === m ? 'bg-primary text-primary-foreground' : 'bg-muted text-muted-foreground hover:bg-muted/80'}`}>
              {m === 'week' ? 'This Week' : 'This Month'}
            </button>
          ))}
        </div>
        {sites.length > 1 && (
          <select value={selectedSite} onChange={(e) => setSelectedSite(e.target.value)}
            className="px-4 py-2 bg-input-background border border-border rounded-lg text-foreground focus:outline-none focus:ring-2 focus:ring-ring text-sm">
            {sites.map((s) => <option key={s} value={s}>{s}</option>)}
          </select>
        )}
      </div>

      {/* Tabs */}
      <div className="flex gap-2 mb-6 border-b border-border">
        <button
          onClick={() => setActiveTab('mobile_money')}
          className={`flex items-center gap-2 px-4 py-3 font-medium transition-colors relative ${
            activeTab === 'mobile_money'
              ? 'text-primary border-b-2 border-primary'
              : 'text-muted-foreground hover:text-foreground'
          }`}
        >
          <Smartphone className="w-4 h-4" />
          Mobile Money
        </button>
        <button
          onClick={() => setActiveTab('vouchers')}
          className={`flex items-center gap-2 px-4 py-3 font-medium transition-colors relative ${
            activeTab === 'vouchers'
              ? 'text-primary border-b-2 border-primary'
              : 'text-muted-foreground hover:text-foreground'
          }`}
        >
          <Ticket className="w-4 h-4" />
          Vouchers
        </button>
      </div>

      {/* Stats Cards */}
      <div className="grid grid-cols-1 sm:grid-cols-3 gap-4 sm:gap-6 mb-6">
        <div className="bg-card border border-border rounded-lg p-4 sm:p-6">
          <div className="flex items-center gap-3 mb-2">
            <div className="w-9 h-9 bg-primary/10 rounded-lg flex items-center justify-center">
              <TrendingUp className="w-5 h-5 text-primary" />
            </div>
            <p className="text-sm text-muted-foreground">Total ({viewMode === 'week' ? '7 days' : '30 days'})</p>
          </div>
          <h3 className="text-2xl font-semibold text-card-foreground">{fmt(stats.total)}</h3>
        </div>
        <div className="bg-card border border-border rounded-lg p-4 sm:p-6">
          <div className="flex items-center gap-3 mb-2">
            <div className="w-9 h-9 bg-primary/10 rounded-lg flex items-center justify-center">
              <Calendar className="w-5 h-5 text-primary" />
            </div>
            <p className="text-sm text-muted-foreground">Daily Average</p>
          </div>
          <h3 className="text-2xl font-semibold text-card-foreground">{fmt(stats.avg)}</h3>
        </div>
        <div className="bg-card border border-border rounded-lg p-4 sm:p-6">
          <div className="flex items-center gap-3 mb-2">
            <div className="w-9 h-9 bg-primary/10 rounded-lg flex items-center justify-center">
              <TrendingUp className="w-5 h-5 text-primary" />
            </div>
            <p className="text-sm text-muted-foreground">Best Day</p>
          </div>
          <h3 className="text-2xl font-semibold text-card-foreground">{fmt(stats.best.amount)}</h3>
          {stats.best.date && <p className="text-xs text-muted-foreground mt-1">{new Date(stats.best.date + 'T00:00:00').toLocaleDateString('en-GB', { weekday: 'short', day: '2-digit', month: 'short' })}</p>}
        </div>
      </div>

      {activeTab === 'mobile_money' && (
        <div className="grid grid-cols-1 sm:grid-cols-3 gap-4 sm:gap-6 mb-6">
          <div className="bg-card border border-border rounded-lg p-4 sm:p-6">
            <div className="flex items-center gap-3 mb-2">
              <div className="w-9 h-9 bg-primary/10 rounded-lg flex items-center justify-center">
                <Smartphone className="w-5 h-5 text-primary" />
              </div>
              <p className="text-sm text-muted-foreground">Mobile Money Total ({viewMode === 'week' ? '7 days' : '30 days'})</p>
            </div>
            <h3 className="text-2xl font-semibold text-card-foreground">{fmt(mobileMoneyStats.total)}</h3>
          </div>
          <div className="bg-card border border-border rounded-lg p-4 sm:p-6">
            <div className="flex items-center gap-3 mb-2">
              <div className="w-9 h-9 bg-primary/10 rounded-lg flex items-center justify-center">
                <Calendar className="w-5 h-5 text-primary" />
              </div>
              <p className="text-sm text-muted-foreground">Mobile Money Daily Average</p>
            </div>
            <h3 className="text-2xl font-semibold text-card-foreground">{fmt(mobileMoneyStats.avg)}</h3>
          </div>
          <div className="bg-card border border-border rounded-lg p-4 sm:p-6">
            <div className="flex items-center gap-3 mb-2">
              <div className="w-9 h-9 bg-primary/10 rounded-lg flex items-center justify-center">
                <TrendingUp className="w-5 h-5 text-primary" />
              </div>
              <p className="text-sm text-muted-foreground">Mobile Money Best Day</p>
            </div>
            <h3 className="text-2xl font-semibold text-card-foreground">{fmt(mobileMoneyStats.best.amount)}</h3>
            {mobileMoneyStats.best.date && <p className="text-xs text-muted-foreground mt-1">{new Date(mobileMoneyStats.best.date + 'T00:00:00').toLocaleDateString('en-GB', { weekday: 'short', day: '2-digit', month: 'short' })}</p>}
          </div>
        </div>
      )}

      {activeTab === 'vouchers' && (
        <div className="grid grid-cols-1 sm:grid-cols-3 gap-4 sm:gap-6 mb-6">
          <div className="bg-card border border-border rounded-lg p-4 sm:p-6">
            <div className="flex items-center gap-3 mb-2">
              <div className="w-9 h-9 bg-primary/10 rounded-lg flex items-center justify-center">
                <Ticket className="w-5 h-5 text-primary" />
              </div>
              <p className="text-sm text-muted-foreground">Voucher Total ({viewMode === 'week' ? '7 days' : '30 days'})</p>
            </div>
            <h3 className="text-2xl font-semibold text-card-foreground">{fmt(voucherStats.total)}</h3>
          </div>
          <div className="bg-card border border-border rounded-lg p-4 sm:p-6">
            <div className="flex items-center gap-3 mb-2">
              <div className="w-9 h-9 bg-primary/10 rounded-lg flex items-center justify-center">
                <Calendar className="w-5 h-5 text-primary" />
              </div>
              <p className="text-sm text-muted-foreground">Voucher Daily Average</p>
            </div>
            <h3 className="text-2xl font-semibold text-card-foreground">{fmt(voucherStats.avg)}</h3>
          </div>
          <div className="bg-card border border-border rounded-lg p-4 sm:p-6">
            <div className="flex items-center gap-3 mb-2">
              <div className="w-9 h-9 bg-primary/10 rounded-lg flex items-center justify-center">
                <TrendingUp className="w-5 h-5 text-primary" />
              </div>
              <p className="text-sm text-muted-foreground">Voucher Best Day</p>
            </div>
            <h3 className="text-2xl font-semibold text-card-foreground">{fmt(voucherStats.best.amount)}</h3>
            {voucherStats.best.date && <p className="text-xs text-muted-foreground mt-1">{new Date(voucherStats.best.date + 'T00:00:00').toLocaleDateString('en-GB', { weekday: 'short', day: '2-digit', month: 'short' })}</p>}
          </div>
        </div>
      )}

      {/* Chart */}
      <div className="bg-card border border-border rounded-lg p-4 sm:p-6 mb-6">
        <h2 className="text-lg text-card-foreground mb-6">
          {viewMode === 'week' ? 'Weekly' : 'Monthly'} Earnings — {selectedSite}
        </h2>
        {loading ? (
          <div className="flex items-center justify-center h-48">
            <RefreshCw className="w-6 h-6 text-primary animate-spin" />
          </div>
        ) : (
          <ResponsiveContainer width="100%" height={280}>
            <BarChart data={chartData} margin={{ top: 5, right: 10, left: 10, bottom: 5 }}>
              <CartesianGrid strokeDasharray="3 3" stroke="rgba(255,255,255,0.05)" />
              <XAxis dataKey="name" tick={{ fill: '#94A3B8', fontSize: 12 }} axisLine={false} tickLine={false} />
              <YAxis tick={{ fill: '#94A3B8', fontSize: 11 }} axisLine={false} tickLine={false}
                tickFormatter={(v) => v >= 1000 ? `${(v / 1000).toFixed(0)}k` : String(v)} />
              <Tooltip content={<CustomTooltip />} cursor={{ fill: 'rgba(16,185,129,0.05)' }} />
              <Bar dataKey="amount" fill="#10B981" radius={[4, 4, 0, 0]} maxBarSize={48} />
            </BarChart>
          </ResponsiveContainer>
        )}
      </div>

      {/* Calendar grid */}
      <div className="bg-card border border-border rounded-lg p-4 sm:p-6">
        <h2 className="text-lg text-card-foreground mb-4">Daily Breakdown</h2>
        <div className={`grid gap-2 ${viewMode === 'week' ? 'grid-cols-7' : 'grid-cols-7 sm:grid-cols-10'}`}>
          {data.map((d) => {
            const intensity = stats.total > 0 ? d.amount / (stats.total / data.length) : 0;
            const opacity = d.amount === 0 ? 0.05 : Math.min(1, 0.15 + intensity * 0.6);
            return (
              <div key={d.date} title={`${d.date}: ${fmt(d.amount)}`}
                className="aspect-square rounded-lg flex flex-col items-center justify-center cursor-default transition-transform hover:scale-105"
                style={{ backgroundColor: `rgba(16,185,129,${opacity})` }}>
                <span className="text-xs font-semibold text-foreground">{d.day_num}</span>
                {d.amount > 0 && (
                  <span className="text-[11px] font-bold text-primary mt-0.5 hidden sm:block leading-tight">
                    {d.amount >= 1000 ? `${(d.amount / 1000).toFixed(0)}k` : String(Math.round(d.amount))}
                  </span>
                )}
              </div>
            );
          })}
        </div>
        {data.length === 0 && !loading && (
          <p className="text-center text-muted-foreground text-sm py-8">No data available for this period.</p>
        )}
      </div>
    </div>
  );
}
