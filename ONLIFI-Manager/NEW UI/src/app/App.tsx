import { useState } from "react";
import { LayoutDashboard, Users, Ticket, Wallet, Settings } from "lucide-react";
import { DashboardScreen } from "./components/DashboardScreen";
import { UsersScreen } from "./components/UsersScreen";
import { VouchersScreen } from "./components/VouchersScreen";
import { BalanceScreen } from "./components/BalanceScreen";
import { SettingsScreen } from "./components/SettingsScreen";

const tabs = [
  { id: "dashboard", label: "Home", icon: LayoutDashboard },
  { id: "users", label: "Users", icon: Users },
  { id: "vouchers", label: "Vouchers", icon: Ticket },
  { id: "balance", label: "Balance", icon: Wallet },
  { id: "settings", label: "Settings", icon: Settings },
];

export default function App() {
  const [activeTab, setActiveTab] = useState("dashboard");

  return (
    <div className="min-h-screen bg-[#060A14] flex items-center justify-center p-6"
      style={{ fontFamily: "'Inter', -apple-system, BlinkMacSystemFont, sans-serif" }}
    >
      {/* Ambient glow */}
      <div className="fixed inset-0 overflow-hidden pointer-events-none">
        <div className="absolute top-1/4 left-1/4 w-96 h-96 bg-blue-600/10 rounded-full blur-3xl" />
        <div className="absolute bottom-1/4 right-1/4 w-80 h-80 bg-emerald-500/8 rounded-full blur-3xl" />
      </div>

      {/* Design context label */}
      <div className="hidden lg:flex flex-col gap-6 mr-12 max-w-xs">
        <div>
          <div className="flex items-center gap-2 mb-3">
            <div className="w-2 h-2 rounded-full bg-[#00E5A0]" />
            <span className="text-[#8A94A6] text-xs tracking-widest uppercase">App Design</span>
          </div>
          <h2 className="text-white mb-2" style={{ fontSize: "28px", fontWeight: 800, lineHeight: 1.2 }}>
            Hotspot Manager
          </h2>
          <p className="text-[#8A94A6] text-sm leading-relaxed">
            Android dashboard linked to your Laravel backend. Track users, monitor voucher sales, and manage mobile money balance.
          </p>
        </div>

        <div className="space-y-3">
          {[
            { color: "#0066FF", label: "Home", desc: "Revenue chart — 1H/1D/1W/1M/1Y" },
            { color: "#00E5A0", label: "Users", desc: "Live active user monitor" },
            { color: "#FF6B35", label: "Vouchers", desc: "Sales · Types · Templates" },
            { color: "#00A86B", label: "Balance", desc: "Mobile money & withdrawals" },
            { color: "#A855F7", label: "Settings", desc: "API, network, notifications" },
          ].map((item) => (
            <div key={item.label} className="flex items-center gap-3">
              <div className="w-2 h-2 rounded-full flex-shrink-0" style={{ background: item.color }} />
              <div>
                <span className="text-white text-sm font-medium">{item.label}</span>
                <span className="text-[#8A94A6] text-xs ml-2">{item.desc}</span>
              </div>
            </div>
          ))}
        </div>

        <div className="border border-[#1E2A45] rounded-xl p-4">
          <p className="text-[#8A94A6] text-xs mb-2">Laravel API Endpoints</p>
          <div className="space-y-1.5">
            {[
              "/api/stats/users/active",
              "/api/balance/mobile-money",
              "/api/vouchers/sold",
              "/api/withdraw",
            ].map((ep) => (
              <div key={ep} className="flex items-center gap-2">
                <div className="w-1.5 h-1.5 rounded-full bg-[#0066FF]/60" />
                <span className="text-[#3A5080] text-xs font-mono">{ep}</span>
              </div>
            ))}
          </div>
        </div>
      </div>

      {/* Phone Frame */}
      <div className="relative flex-shrink-0">
        {/* Outer phone shell */}
        <div
          className="relative rounded-[44px] p-[3px]"
          style={{
            background: "linear-gradient(180deg, #2A3550 0%, #0D1425 60%, #0A0F1E 100%)",
            boxShadow: "0 40px 80px rgba(0,0,0,0.8), 0 0 0 1px rgba(255,255,255,0.04), inset 0 1px 0 rgba(255,255,255,0.06)",
            width: 375,
          }}
        >
          {/* Inner bezel */}
          <div className="rounded-[42px] bg-[#0A0F1E] overflow-hidden relative" style={{ height: 780 }}>
            {/* Status bar */}
            <div className="flex items-center justify-between px-6 pt-3 pb-1 bg-[#0A0F1E]">
              <span className="text-white text-xs font-semibold">9:41</span>
              {/* Dynamic island */}
              <div className="w-24 h-7 bg-black rounded-full" />
              <div className="flex items-center gap-1">
                <div className="flex gap-0.5 items-end h-3">
                  {[3, 4, 5, 6].map((h, i) => (
                    <div key={i} className="w-1 bg-white rounded-sm" style={{ height: h * 2 }} />
                  ))}
                </div>
                <span className="text-white text-xs">100%</span>
              </div>
            </div>

            {/* Screen content */}
            <div className="flex-1 overflow-hidden" style={{ height: 695 }}>
              {activeTab === "dashboard" && <DashboardScreen />}
              {activeTab === "users" && <UsersScreen />}
              {activeTab === "vouchers" && <VouchersScreen />}
              {activeTab === "balance" && <BalanceScreen />}
              {activeTab === "settings" && <SettingsScreen />}
            </div>

            {/* Bottom Navigation */}
            <div
              className="absolute bottom-0 left-0 right-0 px-2 pb-2 pt-1"
              style={{ background: "linear-gradient(to top, #0A0F1E 70%, transparent)" }}
            >
              <div className="bg-[#151C30]/90 backdrop-blur rounded-2xl flex items-center px-2 py-2 border border-[#1E2A45]/50">
                {tabs.map((tab) => {
                  const isActive = activeTab === tab.id;
                  const colors: Record<string, string> = {
                    dashboard: "#0066FF",
                    users: "#00E5A0",
                    vouchers: "#FF6B35",
                    balance: "#00A86B",
                    settings: "#A855F7",
                  };
                  return (
                    <button
                      key={tab.id}
                      onClick={() => setActiveTab(tab.id)}
                      className="flex-1 flex flex-col items-center gap-1 py-1.5 rounded-xl transition-all"
                      style={{ background: isActive ? `${colors[tab.id]}15` : "transparent" }}
                    >
                      <tab.icon
                        size={18}
                        style={{ color: isActive ? colors[tab.id] : "#3A4A60" }}
                        strokeWidth={isActive ? 2.5 : 1.5}
                      />
                      <span
                        className="text-xs"
                        style={{
                          color: isActive ? colors[tab.id] : "#3A4A60",
                          fontWeight: isActive ? 600 : 400,
                          fontSize: "10px",
                        }}
                      >
                        {tab.label}
                      </span>
                    </button>
                  );
                })}
              </div>
              {/* Home indicator */}
              <div className="w-24 h-1 bg-white/20 rounded-full mx-auto mt-1.5" />
            </div>
          </div>
        </div>

        {/* Side buttons */}
        <div className="absolute -right-1 top-28 w-1 h-14 bg-[#1E2A45] rounded-r" />
        <div className="absolute -left-1 top-24 w-1 h-8 bg-[#1E2A45] rounded-l" />
        <div className="absolute -left-1 top-36 w-1 h-12 bg-[#1E2A45] rounded-l" />
        <div className="absolute -left-1 top-52 w-1 h-12 bg-[#1E2A45] rounded-l" />
      </div>
    </div>
  );
}
