import { useState } from "react";
import { User, Bell, Shield, Wifi, Globe, Moon, Sun, ChevronRight, Server, Key, Smartphone, LogOut, ToggleLeft, ToggleRight, Check, Database, Zap } from "lucide-react";

interface ToggleRowProps {
  label: string;
  sublabel?: string;
  value: boolean;
  onChange: (v: boolean) => void;
  color?: string;
}
function ToggleRow({ label, sublabel, value, onChange, color = "#0066FF" }: ToggleRowProps) {
  return (
    <div className="flex items-center justify-between py-3 border-b border-[#0A0F1E] last:border-0">
      <div>
        <p className="text-white text-sm">{label}</p>
        {sublabel && <p className="text-[#8A94A6] text-xs mt-0.5">{sublabel}</p>}
      </div>
      <button onClick={() => onChange(!value)}>
        {value
          ? <ToggleRight size={24} style={{ color }} />
          : <ToggleLeft size={24} className="text-[#3A4560]" />}
      </button>
    </div>
  );
}

interface NavRowProps {
  icon: React.ElementType;
  label: string;
  sublabel?: string;
  badge?: string;
  badgeColor?: string;
  danger?: boolean;
  onClick?: () => void;
}
function NavRow({ icon: Icon, label, sublabel, badge, badgeColor = "#00E5A0", danger, onClick }: NavRowProps) {
  return (
    <button onClick={onClick} className="flex items-center gap-3 w-full py-3 border-b border-[#0A0F1E] last:border-0">
      <div className={`w-8 h-8 rounded-xl flex items-center justify-center flex-shrink-0 ${danger ? "bg-[#FF4757]/15" : "bg-[#0A0F1E]"}`}>
        <Icon size={14} className={danger ? "text-[#FF4757]" : "text-[#8A94A6]"} />
      </div>
      <div className="flex-1 text-left">
        <p className={`text-sm ${danger ? "text-[#FF4757]" : "text-white"}`}>{label}</p>
        {sublabel && <p className="text-[#8A94A6] text-xs mt-0.5">{sublabel}</p>}
      </div>
      {badge && (
        <span className="text-xs font-medium px-2 py-0.5 rounded-full" style={{ background: `${badgeColor}20`, color: badgeColor }}>
          {badge}
        </span>
      )}
      {!badge && <ChevronRight size={14} className="text-[#3A4560]" />}
    </button>
  );
}

type SettingsSection = "main" | "profile" | "api" | "notifications" | "network";

export function SettingsScreen() {
  const [section, setSection] = useState<SettingsSection>("main");
  const [darkMode, setDarkMode] = useState(true);
  const [pushNotifs, setPushNotifs] = useState(true);
  const [salesAlerts, setSalesAlerts] = useState(true);
  const [lowBalanceAlert, setLowBalanceAlert] = useState(true);
  const [userLoginAlert, setUserLoginAlert] = useState(false);
  const [autoWithdraw, setAutoWithdraw] = useState(false);
  const [apiLogging, setApiLogging] = useState(true);
  const [rateLimiting, setRateLimiting] = useState(true);
  const [guestPortal, setGuestPortal] = useState(true);
  const [savedApiUrl, setSavedApiUrl] = useState("https://myapp.test/api");
  const [apiUrl, setApiUrl] = useState(savedApiUrl);
  const [apiKey, setApiKey] = useState("sk-live-••••••••••••••••");
  const [threshold, setThreshold] = useState("50000");
  const [saved, setSaved] = useState(false);

  const handleSave = () => {
    setSavedApiUrl(apiUrl);
    setSaved(true);
    setTimeout(() => setSaved(false), 2000);
  };

  if (section === "profile") {
    return (
      <div className="flex flex-col h-full bg-[#0A0F1E] overflow-y-auto">
        <div className="px-5 pt-6 pb-4 flex items-center gap-3">
          <button onClick={() => setSection("main")} className="w-8 h-8 rounded-xl bg-[#151C30] flex items-center justify-center">
            <ChevronRight size={14} className="text-[#8A94A6] rotate-180" />
          </button>
          <h1 className="text-white" style={{ fontSize: "18px", fontWeight: 700 }}>Profile</h1>
        </div>
        <div className="px-5">
          <div className="flex flex-col items-center py-6 mb-4">
            <div className="w-20 h-20 rounded-full bg-gradient-to-br from-[#00E5A0] to-[#0066FF] flex items-center justify-center mb-3">
              <span className="text-white font-bold" style={{ fontSize: "28px" }}>AD</span>
            </div>
            <p className="text-white font-semibold" style={{ fontSize: "16px" }}>Admin User</p>
            <p className="text-[#8A94A6] text-sm">admin@hotspot.mw</p>
            <span className="mt-2 px-3 py-1 rounded-full bg-[#0066FF]/15 text-[#0066FF] text-xs font-medium">Super Admin</span>
          </div>
          <div className="bg-[#151C30] rounded-2xl p-4 space-y-0">
            {[
              { label: "Full Name", value: "Admin User" },
              { label: "Email", value: "admin@hotspot.mw" },
              { label: "Phone", value: "+265 888 123 456" },
              { label: "Role", value: "Super Admin" },
            ].map((f) => (
              <div key={f.label} className="py-3 border-b border-[#0A0F1E] last:border-0">
                <p className="text-[#8A94A6] text-xs mb-1">{f.label}</p>
                <input
                  defaultValue={f.value}
                  className="bg-transparent text-white text-sm outline-none w-full"
                />
              </div>
            ))}
          </div>
          <button className="w-full mt-4 py-3 rounded-xl bg-[#0066FF] text-white text-sm font-semibold">
            Save Changes
          </button>
        </div>
      </div>
    );
  }

  if (section === "api") {
    return (
      <div className="flex flex-col h-full bg-[#0A0F1E] overflow-y-auto">
        <div className="px-5 pt-6 pb-4 flex items-center gap-3">
          <button onClick={() => setSection("main")} className="w-8 h-8 rounded-xl bg-[#151C30] flex items-center justify-center">
            <ChevronRight size={14} className="text-[#8A94A6] rotate-180" />
          </button>
          <h1 className="text-white" style={{ fontSize: "18px", fontWeight: 700 }}>API & Laravel</h1>
        </div>
        <div className="px-5 space-y-4">
          <div className="bg-[#151C30] rounded-2xl p-4">
            <div className="flex items-center gap-2 mb-4">
              <Server size={14} className="text-[#0066FF]" />
              <h3 className="text-white" style={{ fontSize: "14px", fontWeight: 600 }}>Laravel Backend</h3>
            </div>
            <div className="space-y-3">
              <div>
                <p className="text-[#8A94A6] text-xs mb-1.5">Base API URL</p>
                <input
                  value={apiUrl}
                  onChange={(e) => setApiUrl(e.target.value)}
                  className="w-full bg-[#0A0F1E] text-white text-sm rounded-xl px-3 py-2.5 outline-none border border-[#1E2A45] font-mono"
                />
              </div>
              <div>
                <p className="text-[#8A94A6] text-xs mb-1.5">API Key / Token</p>
                <input
                  value={apiKey}
                  onChange={(e) => setApiKey(e.target.value)}
                  className="w-full bg-[#0A0F1E] text-white text-sm rounded-xl px-3 py-2.5 outline-none border border-[#1E2A45] font-mono"
                />
              </div>
            </div>
            <button onClick={handleSave} className="w-full mt-4 py-2.5 rounded-xl bg-[#0066FF] text-white text-sm font-semibold flex items-center justify-center gap-2">
              {saved ? <><Check size={14} /> Saved!</> : "Save Connection"}
            </button>
          </div>

          <div className="bg-[#151C30] rounded-2xl p-4">
            <div className="flex items-center gap-2 mb-3">
              <Database size={14} className="text-[#00E5A0]" />
              <h3 className="text-white" style={{ fontSize: "14px", fontWeight: 600 }}>API Behaviour</h3>
            </div>
            <ToggleRow label="Request Logging" sublabel="Log all API calls locally" value={apiLogging} onChange={setApiLogging} color="#00E5A0" />
            <ToggleRow label="Rate Limiting" sublabel="Limit to 100 req/min" value={rateLimiting} onChange={setRateLimiting} />
          </div>

          <div className="bg-[#151C30] rounded-2xl p-4">
            <p className="text-[#8A94A6] text-xs mb-2">Endpoints used</p>
            {[
              { path: "/api/stats/users/active", method: "GET" },
              { path: "/api/balance/mobile-money", method: "GET" },
              { path: "/api/vouchers/sold", method: "GET" },
              { path: "/api/vouchers/types", method: "GET/POST" },
              { path: "/api/withdraw", method: "POST" },
            ].map((ep) => (
              <div key={ep.path} className="flex items-center gap-2 py-1.5 border-b border-[#0A0F1E] last:border-0">
                <span className="text-xs font-medium px-1.5 py-0.5 rounded bg-[#0066FF]/20 text-[#0066FF] flex-shrink-0" style={{ fontSize: "10px" }}>
                  {ep.method}
                </span>
                <span className="text-[#8A94A6] text-xs font-mono truncate">{ep.path}</span>
              </div>
            ))}
          </div>
        </div>
      </div>
    );
  }

  if (section === "notifications") {
    return (
      <div className="flex flex-col h-full bg-[#0A0F1E] overflow-y-auto">
        <div className="px-5 pt-6 pb-4 flex items-center gap-3">
          <button onClick={() => setSection("main")} className="w-8 h-8 rounded-xl bg-[#151C30] flex items-center justify-center">
            <ChevronRight size={14} className="text-[#8A94A6] rotate-180" />
          </button>
          <h1 className="text-white" style={{ fontSize: "18px", fontWeight: 700 }}>Notifications</h1>
        </div>
        <div className="px-5 space-y-4">
          <div className="bg-[#151C30] rounded-2xl p-4">
            <ToggleRow label="Push Notifications" sublabel="Receive alerts on this device" value={pushNotifs} onChange={setPushNotifs} />
            <ToggleRow label="Voucher Sale Alerts" sublabel="Notify on every sale" value={salesAlerts} onChange={setSalesAlerts} color="#00E5A0" />
            <ToggleRow label="Low Balance Alert" sublabel="When MoMo balance drops below threshold" value={lowBalanceAlert} onChange={setLowBalanceAlert} color="#FFB800" />
            <ToggleRow label="User Login Alerts" sublabel="New device connects to hotspot" value={userLoginAlert} onChange={setUserLoginAlert} />
            <ToggleRow label="Auto-Withdraw" sublabel="Auto-withdraw when balance exceeds limit" value={autoWithdraw} onChange={setAutoWithdraw} color="#FF6B35" />
          </div>
          {lowBalanceAlert && (
            <div className="bg-[#151C30] rounded-2xl p-4">
              <p className="text-[#8A94A6] text-xs mb-1.5">Alert Threshold (MWK)</p>
              <input
                value={threshold}
                onChange={(e) => setThreshold(e.target.value)}
                className="w-full bg-[#0A0F1E] text-white text-sm rounded-xl px-3 py-2.5 outline-none border border-[#1E2A45]"
                type="number"
              />
            </div>
          )}
        </div>
      </div>
    );
  }

  if (section === "network") {
    return (
      <div className="flex flex-col h-full bg-[#0A0F1E] overflow-y-auto">
        <div className="px-5 pt-6 pb-4 flex items-center gap-3">
          <button onClick={() => setSection("main")} className="w-8 h-8 rounded-xl bg-[#151C30] flex items-center justify-center">
            <ChevronRight size={14} className="text-[#8A94A6] rotate-180" />
          </button>
          <h1 className="text-white" style={{ fontSize: "18px", fontWeight: 700 }}>Network & Hotspot</h1>
        </div>
        <div className="px-5 space-y-4">
          <div className="bg-[#151C30] rounded-2xl p-4">
            <div className="flex items-center gap-2 mb-3">
              <Wifi size={14} className="text-[#00E5A0]" />
              <h3 className="text-white" style={{ fontSize: "14px", fontWeight: 600 }}>Hotspot Config</h3>
            </div>
            {[
              { label: "SSID", value: "MyHotspot_5G" },
              { label: "NAS IP", value: "192.168.1.1" },
              { label: "RADIUS Port", value: "1812" },
              { label: "Shared Secret", value: "••••••••" },
            ].map((f) => (
              <div key={f.label} className="py-3 border-b border-[#0A0F1E] last:border-0">
                <p className="text-[#8A94A6] text-xs mb-1">{f.label}</p>
                <input
                  defaultValue={f.value}
                  className="bg-transparent text-white text-sm outline-none w-full font-mono"
                />
              </div>
            ))}
          </div>
          <div className="bg-[#151C30] rounded-2xl p-4">
            <div className="flex items-center gap-2 mb-3">
              <Zap size={14} className="text-[#FFB800]" />
              <h3 className="text-white" style={{ fontSize: "14px", fontWeight: 600 }}>Bandwidth Defaults</h3>
            </div>
            <ToggleRow label="Guest Portal" sublabel="Show login page before access" value={guestPortal} onChange={setGuestPortal} color="#00E5A0" />
            {[
              { label: "Default Download", value: "10 Mbps" },
              { label: "Default Upload", value: "5 Mbps" },
              { label: "Session Timeout", value: "8 hours" },
            ].map((f) => (
              <div key={f.label} className="py-3 border-b border-[#0A0F1E] last:border-0 flex items-center justify-between">
                <p className="text-white text-sm">{f.label}</p>
                <input
                  defaultValue={f.value}
                  className="bg-[#0A0F1E] text-white text-sm rounded-lg px-2 py-1 outline-none border border-[#1E2A45] text-right w-24"
                />
              </div>
            ))}
          </div>
          <button className="w-full py-3 rounded-xl bg-[#0066FF] text-white text-sm font-semibold">Save Network Settings</button>
        </div>
      </div>
    );
  }

  /* ── MAIN settings ── */
  return (
    <div className="flex flex-col h-full bg-[#0A0F1E] overflow-y-auto">
      <div className="px-5 pt-6 pb-4">
        <p className="text-[#8A94A6] text-xs tracking-widest uppercase">Preferences</p>
        <h1 className="text-white" style={{ fontSize: "20px", fontWeight: 700, lineHeight: "1.3" }}>Settings</h1>
      </div>

      {/* Profile card */}
      <button onClick={() => setSection("profile")} className="mx-5 mb-5 bg-[#151C30] rounded-2xl p-4 flex items-center gap-3">
        <div className="w-12 h-12 rounded-full bg-gradient-to-br from-[#00E5A0] to-[#0066FF] flex items-center justify-center flex-shrink-0">
          <span className="text-white font-bold">AD</span>
        </div>
        <div className="flex-1 text-left">
          <p className="text-white font-semibold">Admin User</p>
          <p className="text-[#8A94A6] text-xs">admin@hotspot.mw · Super Admin</p>
        </div>
        <ChevronRight size={16} className="text-[#3A4560]" />
      </button>

      {/* Section groups */}
      <div className="px-5 space-y-4">
        <div>
          <p className="text-[#8A94A6] text-xs mb-2 tracking-widest uppercase">System</p>
          <div className="bg-[#151C30] rounded-2xl px-4">
            <NavRow icon={Server} label="API & Laravel" sublabel={savedApiUrl} badge="Connected" badgeColor="#00E5A0" onClick={() => setSection("api")} />
            <NavRow icon={Wifi} label="Network & Hotspot" sublabel="SSID, RADIUS, bandwidth" onClick={() => setSection("network")} />
            <NavRow icon={Key} label="API Keys" sublabel="Manage access tokens" />
            <NavRow icon={Database} label="Data Sync" sublabel="Last synced: 2 min ago" badge="Live" badgeColor="#0066FF" />
          </div>
        </div>

        <div>
          <p className="text-[#8A94A6] text-xs mb-2 tracking-widest uppercase">Alerts</p>
          <div className="bg-[#151C30] rounded-2xl px-4">
            <NavRow icon={Bell} label="Notifications" sublabel="Sales, balance, user alerts" onClick={() => setSection("notifications")} />
          </div>
        </div>

        <div>
          <p className="text-[#8A94A6] text-xs mb-2 tracking-widest uppercase">Appearance</p>
          <div className="bg-[#151C30] rounded-2xl px-4">
            <div className="flex items-center justify-between py-3">
              <div className="flex items-center gap-2.5">
                <div className="w-8 h-8 rounded-xl bg-[#0A0F1E] flex items-center justify-center">
                  {darkMode ? <Moon size={14} className="text-[#8A94A6]" /> : <Sun size={14} className="text-[#FFB800]" />}
                </div>
                <div>
                  <p className="text-white text-sm">Dark Mode</p>
                  <p className="text-[#8A94A6] text-xs">Current: {darkMode ? "Dark" : "Light"}</p>
                </div>
              </div>
              <button onClick={() => setDarkMode(!darkMode)}>
                {darkMode ? <ToggleRight size={24} className="text-[#0066FF]" /> : <ToggleLeft size={24} className="text-[#3A4560]" />}
              </button>
            </div>
            <NavRow icon={Globe} label="Language" sublabel="English (UK)" />
            <NavRow icon={Smartphone} label="App Version" sublabel="v1.0.0 · Up to date" badge="v1.0.0" badgeColor="#8A94A6" />
          </div>
        </div>

        <div>
          <p className="text-[#8A94A6] text-xs mb-2 tracking-widest uppercase">Account</p>
          <div className="bg-[#151C30] rounded-2xl px-4">
            <NavRow icon={Shield} label="Security & 2FA" sublabel="Manage login security" />
            <NavRow icon={LogOut} label="Sign Out" danger />
          </div>
        </div>
      </div>

      <div className="h-8" />
    </div>
  );
}
