import { Search, Filter, Wifi, WifiOff, Clock, ChevronRight, Users, TrendingUp } from "lucide-react";
import { BarChart, Bar, XAxis, YAxis, ResponsiveContainer, Tooltip } from "recharts";

const hourlyData = [
  { hour: "6am", users: 120 },
  { hour: "8am", users: 380 },
  { hour: "10am", users: 620 },
  { hour: "12pm", users: 890 },
  { hour: "2pm", users: 1050 },
  { hour: "4pm", users: 1284 },
  { hour: "6pm", users: 940 },
  { hour: "8pm", users: 680 },
];

const activeUsers = [
  { id: 1, name: "Chisomo Banda", ip: "102.64.12.45", device: "Android 14", duration: "2h 14m", status: "online" },
  { id: 2, name: "Takondwa Phiri", ip: "197.220.44.11", device: "Android 13", duration: "45m", status: "online" },
  { id: 3, name: "Mphatso Chirwa", ip: "41.70.123.88", device: "Android 12", duration: "1h 02m", status: "online" },
  { id: 4, name: "Fatuma Hassan", ip: "196.13.98.34", device: "iOS 17", duration: "3h 30m", status: "online" },
  { id: 5, name: "James Mwale", ip: "197.248.67.92", device: "Android 14", duration: "18m", status: "idle" },
  { id: 6, name: "Grace Nyirenda", ip: "102.89.45.23", device: "Android 11", duration: "5h 12m", status: "online" },
  { id: 7, name: "Samuel Tembo", ip: "41.175.23.56", device: "Android 13", duration: "28m", status: "idle" },
];

export function UsersScreen() {
  return (
    <div className="flex flex-col h-full bg-[#0A0F1E] overflow-y-auto">
      {/* Header */}
      <div className="px-5 pt-6 pb-4">
        <div className="flex items-center justify-between mb-4">
          <div>
            <p className="text-[#8A94A6] text-xs tracking-widest uppercase">Monitoring</p>
            <h1 className="text-white" style={{ fontSize: "20px", fontWeight: 700, lineHeight: "1.3" }}>Active Users</h1>
          </div>
          <div className="flex items-center gap-1.5 bg-[#00E5A0]/15 rounded-full px-3 py-1.5">
            <div className="w-2 h-2 rounded-full bg-[#00E5A0] animate-pulse" />
            <span className="text-[#00E5A0] text-xs font-medium">Live</span>
          </div>
        </div>

        {/* Search */}
        <div className="flex gap-2">
          <div className="flex-1 flex items-center gap-2 bg-[#151C30] rounded-xl px-3 py-2.5">
            <Search size={14} className="text-[#8A94A6]" />
            <input
              placeholder="Search users..."
              className="bg-transparent text-white text-sm outline-none w-full placeholder-[#8A94A6]"
            />
          </div>
          <button className="w-10 h-10 bg-[#151C30] rounded-xl flex items-center justify-center">
            <Filter size={14} className="text-[#8A94A6]" />
          </button>
        </div>
      </div>

      {/* Stats Row */}
      <div className="px-5 mb-5 grid grid-cols-3 gap-2">
        {[
          { label: "Online", value: "1,284", color: "#00E5A0", icon: Wifi },
          { label: "Idle", value: "147", color: "#FFB800", icon: Clock },
          { label: "Offline", value: "312", color: "#8A94A6", icon: WifiOff },
        ].map((stat) => (
          <div key={stat.label} className="bg-[#151C30] rounded-2xl p-3 text-center">
            <div className="w-8 h-8 rounded-xl mx-auto mb-2 flex items-center justify-center" style={{ background: `${stat.color}20` }}>
              <stat.icon size={14} style={{ color: stat.color }} />
            </div>
            <p className="text-white font-bold" style={{ fontSize: "16px", lineHeight: 1 }}>{stat.value}</p>
            <p className="text-[#8A94A6] text-xs mt-0.5">{stat.label}</p>
          </div>
        ))}
      </div>

      {/* Hourly Chart */}
      <div className="mx-5 mb-5 bg-[#151C30] rounded-2xl p-4">
        <div className="flex items-center justify-between mb-3">
          <div>
            <h3 className="text-white" style={{ fontSize: "14px", fontWeight: 600 }}>User Activity</h3>
            <p className="text-[#8A94A6] text-xs">Today, by hour</p>
          </div>
          <div className="flex items-center gap-1 text-[#00E5A0] text-xs">
            <TrendingUp size={12} />
            <span>Peak: 4pm</span>
          </div>
        </div>
        <ResponsiveContainer width="100%" height={100}>
          <BarChart data={hourlyData} margin={{ top: 0, right: 0, left: -25, bottom: 0 }} barSize={14}>
            <XAxis dataKey="hour" tick={{ fill: "#8A94A6", fontSize: 9 }} axisLine={false} tickLine={false} />
            <YAxis tick={{ fill: "#8A94A6", fontSize: 9 }} axisLine={false} tickLine={false} />
            <Tooltip
              contentStyle={{ background: "#0A0F1E", border: "1px solid #1E2A45", borderRadius: 8, fontSize: 11 }}
              labelStyle={{ color: "#8A94A6" }}
              itemStyle={{ color: "#00E5A0" }}
              cursor={{ fill: "rgba(0,230,160,0.05)" }}
            />
            <Bar dataKey="users" fill="#0066FF" radius={[4, 4, 0, 0]} />
          </BarChart>
        </ResponsiveContainer>
      </div>

      {/* User List */}
      <div className="mx-5 mb-5 bg-[#151C30] rounded-2xl p-4">
        <div className="flex items-center justify-between mb-3">
          <h3 className="text-white" style={{ fontSize: "14px", fontWeight: 600 }}>Connected Users</h3>
          <span className="text-[#8A94A6] text-xs">{activeUsers.length} shown</span>
        </div>
        <div className="space-y-1">
          {activeUsers.map((user) => (
            <div key={user.id} className="flex items-center gap-3 py-2.5 border-b border-[#0A0F1E] last:border-0">
              <div className="w-9 h-9 rounded-full bg-gradient-to-br from-[#0066FF] to-[#00E5A0] flex items-center justify-center flex-shrink-0 relative">
                <span className="text-white text-xs font-bold">{user.name.split(" ").map(n => n[0]).join("")}</span>
                <span className={`absolute -bottom-0.5 -right-0.5 w-2.5 h-2.5 rounded-full border border-[#151C30] ${
                  user.status === "online" ? "bg-[#00E5A0]" : "bg-[#FFB800]"
                }`} />
              </div>
              <div className="flex-1 min-w-0">
                <p className="text-white text-xs font-medium truncate">{user.name}</p>
                <p className="text-[#8A94A6] text-xs">{user.ip} · {user.device}</p>
              </div>
              <div className="text-right">
                <p className="text-[#8A94A6] text-xs">{user.duration}</p>
                <ChevronRight size={12} className="text-[#1E2A45] ml-auto mt-0.5" />
              </div>
            </div>
          ))}
        </div>
      </div>
    </div>
  );
}
