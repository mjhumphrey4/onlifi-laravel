import { useState } from "react";
import { Users, Wallet, Ticket, TrendingUp, TrendingDown, ArrowUpRight, RefreshCw, Bell, Zap } from "lucide-react";
import { AreaChart, Area, XAxis, YAxis, ResponsiveContainer, Tooltip } from "recharts";

const chartDatasets: Record<string, { label: string; sublabel: string; data: { x: string; value: number; prev: number }[] }> = {
  "1H": {
    label: "Last Hour",
    sublabel: "by minute",
    data: [
      { x: "0m", value: 820, prev: 700 },
      { x: "10m", value: 940, prev: 820 },
      { x: "20m", value: 1100, prev: 900 },
      { x: "30m", value: 980, prev: 860 },
      { x: "40m", value: 1240, prev: 1000 },
      { x: "50m", value: 1180, prev: 1050 },
      { x: "60m", value: 1320, prev: 1100 },
    ],
  },
  "1D": {
    label: "Today",
    sublabel: "by hour",
    data: [
      { x: "6am", value: 1200, prev: 980 },
      { x: "8am", value: 1800, prev: 1400 },
      { x: "10am", value: 2400, prev: 2000 },
      { x: "12pm", value: 3100, prev: 2600 },
      { x: "2pm", value: 2800, prev: 2400 },
      { x: "4pm", value: 3600, prev: 3000 },
      { x: "6pm", value: 2900, prev: 2500 },
    ],
  },
  "1W": {
    label: "This Week",
    sublabel: "last 7 days",
    data: [
      { x: "Mon", value: 12000, prev: 9800 },
      { x: "Tue", value: 18000, prev: 14200 },
      { x: "Wed", value: 14500, prev: 13000 },
      { x: "Thu", value: 22000, prev: 18000 },
      { x: "Fri", value: 19500, prev: 17000 },
      { x: "Sat", value: 28000, prev: 22000 },
      { x: "Sun", value: 24000, prev: 19500 },
    ],
  },
  "1M": {
    label: "This Month",
    sublabel: "last 30 days",
    data: [
      { x: "W1", value: 84000, prev: 72000 },
      { x: "W2", value: 96000, prev: 80000 },
      { x: "W3", value: 112000, prev: 90000 },
      { x: "W4", value: 128000, prev: 104000 },
    ],
  },
  "1Y": {
    label: "This Year",
    sublabel: "by month",
    data: [
      { x: "Jan", value: 320000, prev: 280000 },
      { x: "Feb", value: 290000, prev: 260000 },
      { x: "Mar", value: 410000, prev: 350000 },
      { x: "Apr", value: 380000, prev: 340000 },
      { x: "May", value: 460000, prev: 400000 },
      { x: "Jun", value: 520000, prev: 450000 },
    ],
  },
};

const recentActivity = [
  { id: 1, name: "Voucher #V-4821", type: "sold", amount: "+MWK 5,000", time: "2m ago", status: "success" },
  { id: 2, name: "User Login", type: "active", amount: "192.168.1.45", time: "5m ago", status: "info" },
  { id: 3, name: "Voucher #V-4820", type: "sold", amount: "+MWK 10,000", time: "12m ago", status: "success" },
  { id: 4, name: "MoMo Deposit", type: "deposit", amount: "+MWK 50,000", time: "1h ago", status: "success" },
  { id: 5, name: "Voucher #V-4819", type: "sold", amount: "+MWK 2,500", time: "1h ago", status: "success" },
];

const DURATIONS = ["1H", "1D", "1W", "1M", "1Y"] as const;
type Duration = typeof DURATIONS[number];

function formatValue(v: number, duration: Duration) {
  if (duration === "1Y" || duration === "1M") return `${(v / 1000).toFixed(0)}k`;
  return v.toLocaleString();
}

export function DashboardScreen() {
  const [duration, setDuration] = useState<Duration>("1W");
  const dataset = chartDatasets[duration];

  const total = dataset.data.reduce((s, d) => s + d.value, 0);
  const prevTotal = dataset.data.reduce((s, d) => s + d.prev, 0);
  const pct = (((total - prevTotal) / prevTotal) * 100).toFixed(1);
  const isUp = total >= prevTotal;

  return (
    <div className="flex flex-col h-full bg-[#0A0F1E] overflow-y-auto">
      {/* Header */}
      <div className="px-5 pt-6 pb-4">
        <div className="flex items-center justify-between mb-1">
          <div>
            <p className="text-[#8A94A6] text-xs tracking-widest uppercase">Welcome back</p>
            <h1 className="text-white" style={{ fontSize: "20px", fontWeight: 700, lineHeight: "1.3" }}>
              Admin Dashboard
            </h1>
          </div>
          <div className="flex items-center gap-3">
            <button className="w-9 h-9 rounded-full bg-[#151C30] flex items-center justify-center relative">
              <Bell size={16} className="text-[#8A94A6]" />
              <span className="absolute top-1.5 right-1.5 w-2 h-2 bg-[#00E5A0] rounded-full border border-[#0A0F1E]" />
            </button>
            <div className="w-9 h-9 rounded-full bg-gradient-to-br from-[#00E5A0] to-[#0066FF] flex items-center justify-center">
              <span className="text-white text-xs font-bold">AD</span>
            </div>
          </div>
        </div>
      </div>

      {/* Balance Card */}
      <div className="mx-5 mb-5 rounded-2xl overflow-hidden" style={{ background: "linear-gradient(135deg, #0066FF 0%, #0041A8 60%, #001F7A 100%)" }}>
        <div className="p-5">
          <div className="flex items-center justify-between mb-4">
            <div className="flex items-center gap-2">
              <div className="w-8 h-8 rounded-full bg-white/20 flex items-center justify-center">
                <Wallet size={14} className="text-white" />
              </div>
              <span className="text-white/80 text-xs">Mobile Money Balance</span>
            </div>
            <button className="w-7 h-7 rounded-full bg-white/20 flex items-center justify-center">
              <RefreshCw size={12} className="text-white" />
            </button>
          </div>
          <p className="text-white/60 text-xs mb-1">Available Balance</p>
          <h2 className="text-white mb-4" style={{ fontSize: "28px", fontWeight: 800, letterSpacing: "-0.5px", lineHeight: 1 }}>
            MWK 284,500
          </h2>
          <div className="flex items-center justify-between">
            <div className="flex items-center gap-1.5 bg-white/10 rounded-full px-3 py-1.5">
              <TrendingUp size={12} className="text-[#00E5A0]" />
              <span className="text-[#00E5A0] text-xs font-medium">+12.4% this week</span>
            </div>
            <div className="flex items-center gap-1 text-white/60 text-xs">
              <span>Airtel Money</span>
              <div className="w-1.5 h-1.5 rounded-full bg-[#00E5A0]" />
            </div>
          </div>
        </div>
        <div className="h-1 bg-gradient-to-r from-[#00E5A0]/40 via-[#00E5A0]/80 to-[#00E5A0]/40" />
      </div>

      {/* Stats Row */}
      <div className="px-5 mb-5 grid grid-cols-2 gap-3">
        <div className="bg-[#151C30] rounded-2xl p-4">
          <div className="flex items-center justify-between mb-3">
            <div className="w-9 h-9 rounded-xl bg-[#00E5A0]/15 flex items-center justify-center">
              <Users size={16} className="text-[#00E5A0]" />
            </div>
            <span className="text-[#00E5A0] text-xs flex items-center gap-0.5">
              <TrendingUp size={10} /> 8.2%
            </span>
          </div>
          <p className="text-white font-bold" style={{ fontSize: "22px", lineHeight: 1 }}>1,284</p>
          <p className="text-[#8A94A6] text-xs mt-1">Active Users</p>
        </div>
        <div className="bg-[#151C30] rounded-2xl p-4">
          <div className="flex items-center justify-between mb-3">
            <div className="w-9 h-9 rounded-xl bg-[#FF6B35]/15 flex items-center justify-center">
              <Ticket size={16} className="text-[#FF6B35]" />
            </div>
            <span className="text-[#FF6B35] text-xs flex items-center gap-0.5">
              <TrendingDown size={10} /> 2.1%
            </span>
          </div>
          <p className="text-white font-bold" style={{ fontSize: "22px", lineHeight: 1 }}>3,847</p>
          <p className="text-[#8A94A6] text-xs mt-1">Vouchers Sold</p>
        </div>
      </div>

      {/* Revenue Chart — switchable durations */}
      <div className="mx-5 mb-5 bg-[#151C30] rounded-2xl p-4">
        <div className="flex items-center justify-between mb-3">
          <div>
            <h3 className="text-white" style={{ fontSize: "14px", fontWeight: 600 }}>Revenue Overview</h3>
            <p className="text-[#8A94A6] text-xs">{dataset.label} · {dataset.sublabel}</p>
          </div>
          <div className={`flex items-center gap-1 text-xs font-medium ${isUp ? "text-[#00E5A0]" : "text-[#FF4757]"}`}>
            {isUp ? <TrendingUp size={12} /> : <TrendingDown size={12} />}
            {isUp ? "+" : ""}{pct}%
          </div>
        </div>

        {/* Duration pill switcher */}
        <div className="flex items-center gap-1 bg-[#0A0F1E] rounded-xl p-1 mb-3">
          {DURATIONS.map((d) => (
            <button
              key={d}
              onClick={() => setDuration(d)}
              className="flex-1 py-1.5 rounded-lg text-xs font-medium transition-all"
              style={{
                background: duration === d ? "#0066FF" : "transparent",
                color: duration === d ? "#fff" : "#8A94A6",
              }}
            >
              {d}
            </button>
          ))}
        </div>

        {/* Total for period */}
        <div className="flex items-center gap-3 mb-3">
          <div>
            <p className="text-white font-bold" style={{ fontSize: "20px", lineHeight: 1 }}>
              MWK {(total / 1000).toFixed(0)}k
            </p>
            <p className="text-[#8A94A6] text-xs mt-0.5">total {dataset.label.toLowerCase()}</p>
          </div>
          <div className="flex items-center gap-1.5 ml-auto">
            <div className="w-2 h-2 rounded-full bg-[#0066FF]" />
            <span className="text-[#8A94A6] text-xs">Current</span>
            <div className="w-2 h-2 rounded-full bg-[#1E2A45] ml-2" />
            <span className="text-[#8A94A6] text-xs">Prior</span>
          </div>
        </div>

        <ResponsiveContainer width="100%" height={100}>
          <AreaChart data={dataset.data} margin={{ top: 5, right: 0, left: -20, bottom: 0 }}>
            <defs>
              <linearGradient id="gradCurrent" x1="0" y1="0" x2="0" y2="1">
                <stop offset="5%" stopColor="#0066FF" stopOpacity={0.5} />
                <stop offset="95%" stopColor="#0066FF" stopOpacity={0} />
              </linearGradient>
              <linearGradient id="gradPrev" x1="0" y1="0" x2="0" y2="1">
                <stop offset="5%" stopColor="#8A94A6" stopOpacity={0.2} />
                <stop offset="95%" stopColor="#8A94A6" stopOpacity={0} />
              </linearGradient>
            </defs>
            <XAxis dataKey="x" tick={{ fill: "#8A94A6", fontSize: 10 }} axisLine={false} tickLine={false} />
            <YAxis tick={{ fill: "#8A94A6", fontSize: 9 }} axisLine={false} tickLine={false} tickFormatter={(v) => formatValue(v, duration)} />
            <Tooltip
              contentStyle={{ background: "#0A0F1E", border: "1px solid #1E2A45", borderRadius: 8, fontSize: 11 }}
              labelStyle={{ color: "#8A94A6" }}
              formatter={(val: number, name: string) => [
                `MWK ${val.toLocaleString()}`,
                name === "value" ? "Current" : "Previous"
              ]}
            />
            <Area type="monotone" dataKey="prev" stroke="#3A4560" strokeWidth={1.5} fill="url(#gradPrev)" dot={false} strokeDasharray="4 3" />
            <Area type="monotone" dataKey="value" stroke="#0066FF" strokeWidth={2} fill="url(#gradCurrent)" dot={false} />
          </AreaChart>
        </ResponsiveContainer>

        {/* Quick insight */}
        <div className="mt-3 flex items-center gap-2 bg-[#0A0F1E] rounded-xl px-3 py-2">
          <Zap size={12} className="text-[#FFB800] flex-shrink-0" />
          <p className="text-[#8A94A6] text-xs">
            {duration === "1H" && "Revenue picking up — best 10-min window was 40–50m"}
            {duration === "1D" && "4pm is your peak hour. Consider pushing promos at 3pm."}
            {duration === "1W" && "Saturday is your highest revenue day this week."}
            {duration === "1M" && "Week 4 is outperforming — month ending strong."}
            {duration === "1Y" && "June is on track to be best month of the year."}
          </p>
        </div>
      </div>

      {/* Recent Activity */}
      <div className="mx-5 mb-5 bg-[#151C30] rounded-2xl p-4">
        <div className="flex items-center justify-between mb-4">
          <h3 className="text-white" style={{ fontSize: "14px", fontWeight: 600 }}>Recent Activity</h3>
          <button className="text-[#0066FF] text-xs flex items-center gap-1">
            See all <ArrowUpRight size={12} />
          </button>
        </div>
        <div className="space-y-3">
          {recentActivity.map((item) => (
            <div key={item.id} className="flex items-center gap-3">
              <div className={`w-9 h-9 rounded-xl flex items-center justify-center flex-shrink-0 ${
                item.type === "sold" ? "bg-[#00E5A0]/15" :
                item.type === "deposit" ? "bg-[#0066FF]/15" : "bg-[#8A94A6]/15"
              }`}>
                {item.type === "sold" && <Ticket size={14} className="text-[#00E5A0]" />}
                {item.type === "deposit" && <Wallet size={14} className="text-[#0066FF]" />}
                {item.type === "active" && <Users size={14} className="text-[#8A94A6]" />}
              </div>
              <div className="flex-1 min-w-0">
                <p className="text-white text-xs font-medium truncate">{item.name}</p>
                <p className="text-[#8A94A6] text-xs">{item.time}</p>
              </div>
              <span className={`text-xs font-semibold ${item.status === "success" ? "text-[#00E5A0]" : "text-[#8A94A6]"}`}>
                {item.amount}
              </span>
            </div>
          ))}
        </div>
      </div>
    </div>
  );
}
