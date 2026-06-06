import { type FormEvent, useEffect, useMemo, useState } from "react";
import {
  Activity,
  Database,
  KeyRound,
  LayoutDashboard,
  LogOut,
  Pencil,
  PlugZap,
  RefreshCw,
  Save,
  Search,
  Settings,
  ShieldCheck,
  Timer,
  Trash2,
  Wallet,
  Webhook,
  XCircle,
} from "lucide-react";
import {
  Area,
  AreaChart,
  CartesianGrid,
  ResponsiveContainer,
  Tooltip,
  XAxis,
  YAxis,
} from "recharts";
import { api, clearToken, getToken, setToken } from "./api";
import { Button } from "./components/ui/button";
import { Badge } from "./components/ui/badge";

type View = "overview" | "transactions" | "profiles" | "callbacks" | "settings";

const views: Array<{ id: View; label: string; icon: any }> = [
  { id: "overview", label: "Overview", icon: LayoutDashboard },
  { id: "transactions", label: "Transactions", icon: Wallet },
  { id: "profiles", label: "API Profiles", icon: PlugZap },
  { id: "callbacks", label: "Callbacks", icon: Webhook },
  { id: "settings", label: "Settings", icon: Settings },
];

const blankProfile = {
  name: "",
  code: "",
  status: "draft",
  environment: "production",
  auth_url: "https://id.iotec.io/connect/token",
  api_base_url: "https://pay.iotec.io/api",
  wallet_id: "",
  client_id: "",
  client_secret: "",
  callback_url: "/callback.php",
  default_currency: "UGX",
  default_category: "MobileMoney",
  settings: {
    collect_endpoint: "/collections/collect",
    status_endpoint_template: "/collections/status/{transactionId}",
    token_grant_type: "client_credentials",
  },
  notes: "",
};

const blankCallback = {
  name: "",
  event: "collection.status.changed",
  method: "POST",
  url: "/callback.php",
  expected_fields: [
    "id",
    "externalId",
    "status",
    "statusCode",
    "statusMessage",
    "amount",
    "payer",
    "vendor",
    "vendorTransactionId",
  ],
  headers: {},
  is_active: true,
  notes: "",
};

function money(value: number | string | null | undefined) {
  return new Intl.NumberFormat("en-UG", {
    style: "currency",
    currency: "UGX",
    maximumFractionDigits: 0,
  }).format(Number(value || 0));
}

function statusClass(status: string) {
  if (["success", "active"].includes(status)) return "bg-emerald-100 text-emerald-800 border-emerald-200";
  if (["failed", "inactive"].includes(status)) return "bg-rose-100 text-rose-800 border-rose-200";
  if (["pending", "draft"].includes(status)) return "bg-amber-100 text-amber-800 border-amber-200";
  return "bg-slate-100 text-slate-700 border-slate-200";
}

function LoginScreen({ onLogin }: { onLogin: () => void }) {
  const [token, setLocalToken] = useState("");
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState("");

  async function submit(event: FormEvent) {
    event.preventDefault();
    setLoading(true);
    setError("");
    try {
      const result = await api.login(token);
      setToken(result.token);
      onLogin();
    } catch (err) {
      setError(err instanceof Error ? err.message : "Login failed");
    } finally {
      setLoading(false);
    }
  }

  return (
    <main className="min-h-screen bg-slate-100 text-slate-950">
      <div className="mx-auto flex min-h-screen w-full max-w-md flex-col justify-center px-6">
        <form className="rounded-lg border border-slate-200 bg-white p-6 shadow-sm" onSubmit={submit}>
          <div className="mb-6 flex items-center gap-3">
            <div className="flex size-10 items-center justify-center rounded-lg bg-sky-600 text-white">
              <ShieldCheck className="size-5" />
            </div>
            <div>
              <h1 className="text-xl font-semibold">IOTEC Payments</h1>
              <p className="text-sm text-slate-500">Standalone admin console</p>
            </div>
          </div>
          <label className="grid gap-2 text-sm">
            <span className="font-medium">Admin token</span>
            <input
              className="h-10 rounded-lg border border-slate-200 px-3 outline-none focus:border-slate-500"
              type="password"
              value={token}
              onChange={(event) => setLocalToken(event.target.value)}
            />
          </label>
          {error ? <div className="mt-4 rounded-lg border border-rose-200 bg-rose-50 px-3 py-2 text-sm text-rose-700">{error}</div> : null}
          <Button className="mt-4 w-full" type="submit" disabled={loading}>
            {loading ? <RefreshCw className="size-4 animate-spin" /> : <KeyRound className="size-4" />}
            Sign in
          </Button>
        </form>
      </div>
    </main>
  );
}

export default function App() {
  const [authenticated, setAuthenticated] = useState(Boolean(getToken()));
  const [activeView, setActiveView] = useState<View>("overview");
  const [dashboard, setDashboard] = useState<any>(null);
  const [transactions, setTransactions] = useState<any[]>([]);
  const [meta, setMeta] = useState<any>({});
  const [search, setSearch] = useState("");
  const [status, setStatus] = useState("all");
  const [loading, setLoading] = useState(false);
  const [notice, setNotice] = useState("");
  const [error, setError] = useState("");
  const [profileDraft, setProfileDraft] = useState<any | null>(null);
  const [callbackDraft, setCallbackDraft] = useState<any | null>(null);
  const [settingsDraft, setSettingsDraft] = useState<any[]>([]);

  async function load() {
    setLoading(true);
    setError("");
    try {
      const [dash, tx] = await Promise.all([
        api.dashboard(),
        api.transactions({ page: 1, per_page: 30, search, status }),
      ]);
      setDashboard(dash);
      setTransactions(tx.data || []);
      setMeta(tx.meta || {});
      setSettingsDraft(dash.settings || []);
    } catch (err) {
      const message = err instanceof Error ? err.message : "Unable to load IOTEC dashboard";
      setError(message);
      if (message.toLowerCase().includes("unauthorized")) {
        clearToken();
        setAuthenticated(false);
      }
    } finally {
      setLoading(false);
    }
  }

  useEffect(() => {
    if (authenticated) load();
  }, [authenticated, status]);

  useEffect(() => {
    if (!authenticated) return;
    const timer = window.setTimeout(load, 350);
    return () => window.clearTimeout(timer);
  }, [search]);

  const groupedSettings = useMemo(() => {
    return settingsDraft.reduce((groups: Record<string, any[]>, setting) => {
      groups[setting.group || "general"] ||= [];
      groups[setting.group || "general"].push(setting);
      return groups;
    }, {});
  }, [settingsDraft]);

  if (!authenticated) return <LoginScreen onLogin={() => setAuthenticated(true)} />;

  const summary = dashboard?.summary || {};
  const profiles = dashboard?.api_profiles || [];
  const callbacks = dashboard?.callbacks || [];

  return (
    <main className="min-h-screen bg-slate-100 text-slate-950">
      <aside className="fixed inset-y-0 left-0 hidden w-64 border-r border-slate-200 bg-white lg:block">
        <div className="flex h-16 items-center gap-3 border-b border-slate-200 px-5">
          <div className="flex size-9 items-center justify-center rounded-lg bg-sky-600 text-white">
            <PlugZap className="size-5" />
          </div>
          <div>
            <div className="font-semibold">IOTEC Payments</div>
            <div className="text-xs text-slate-500">Separate project</div>
          </div>
        </div>
        <nav className="grid gap-1 p-3">
          {views.map((view) => {
            const Icon = view.icon;
            return (
              <button
                key={view.id}
                className={`flex h-10 items-center gap-3 rounded-lg px-3 text-sm font-medium ${
                  activeView === view.id ? "bg-slate-900 text-white" : "text-slate-600 hover:bg-slate-100"
                }`}
                onClick={() => setActiveView(view.id)}
              >
                <Icon className="size-4" />
                {view.label}
              </button>
            );
          })}
        </nav>
      </aside>

      <section className="lg:pl-64">
        <header className="sticky top-0 z-10 flex min-h-16 items-center justify-between border-b border-slate-200 bg-white/95 px-4 backdrop-blur lg:px-8">
          <div>
            <h1 className="text-lg font-semibold capitalize">{activeView === "profiles" ? "API Profiles" : activeView}</h1>
            <p className="text-sm text-slate-500">{dashboard?.database?.message || "IOTEC payment administration"}</p>
          </div>
          <div className="flex items-center gap-2">
            <Button variant="outline" size="sm" onClick={load} disabled={loading}>
              <RefreshCw className={`size-4 ${loading ? "animate-spin" : ""}`} />
              Refresh
            </Button>
            <Button
              variant="ghost"
              size="icon"
              onClick={() => {
                clearToken();
                setAuthenticated(false);
              }}
            >
              <LogOut className="size-4" />
            </Button>
          </div>
        </header>

        <div className="flex gap-2 overflow-x-auto border-b border-slate-200 bg-white px-4 py-2 lg:hidden">
          {views.map((view) => (
            <button
              key={view.id}
              className={`h-9 shrink-0 rounded-lg px-3 text-sm font-medium ${activeView === view.id ? "bg-slate-900 text-white" : "bg-slate-100 text-slate-700"}`}
              onClick={() => setActiveView(view.id)}
            >
              {view.label}
            </button>
          ))}
        </div>

        <div className="mx-auto grid w-full max-w-7xl gap-5 p-4 lg:p-8">
          {notice ? <div className="rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800">{notice}</div> : null}
          {error ? <div className="rounded-lg border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-800">{error}</div> : null}

          {activeView === "overview" ? <Overview dashboard={dashboard} summary={summary} /> : null}
          {activeView === "transactions" ? (
            <Transactions transactions={transactions} meta={meta} search={search} setSearch={setSearch} status={status} setStatus={setStatus} />
          ) : null}
          {activeView === "profiles" ? (
            <Records
              title="IOTEC API Profiles"
              action="Add profile"
              onAdd={() => setProfileDraft({ ...blankProfile })}
              records={profiles}
              render={(profile: any) => (
                <RecordRow
                  key={profile.id}
                  icon={PlugZap}
                  title={profile.name}
                  subtitle={`${profile.environment} · ${profile.api_base_url} · wallet ${profile.wallet_id || "not set"}`}
                  status={profile.status}
                  onEdit={() => setProfileDraft(profile)}
                  onDelete={async () => {
                    if (profile.id) await api.deleteApiProfile(profile.id);
                    await load();
                  }}
                />
              )}
            />
          ) : null}
          {activeView === "callbacks" ? (
            <Records
              title="IOTEC Callback Endpoints"
              action="Add callback"
              onAdd={() => setCallbackDraft({ ...blankCallback })}
              records={callbacks}
              render={(callback: any) => (
                <RecordRow
                  key={callback.id}
                  icon={Webhook}
                  title={callback.name}
                  subtitle={`${callback.event} · ${callback.method} · ${callback.url}`}
                  status={callback.is_active ? "active" : "inactive"}
                  onEdit={() => setCallbackDraft(callback)}
                  onDelete={async () => {
                    if (callback.id) await api.deleteCallback(callback.id);
                    await load();
                  }}
                />
              )}
            />
          ) : null}
          {activeView === "settings" ? (
            <SettingsView
              groupedSettings={groupedSettings}
              settingsDraft={settingsDraft}
              setSettingsDraft={setSettingsDraft}
              onSave={async () => {
                await api.saveSettings(settingsDraft);
                setNotice("IOTEC settings saved.");
                await load();
              }}
              onTest={async () => {
                const result = await api.testLegacyDb();
                setNotice(result.message);
              }}
            />
          ) : null}
        </div>
      </section>

      {profileDraft ? (
        <ProfileEditor
          draft={profileDraft}
          setDraft={setProfileDraft}
          onSave={async (record: any) => {
            await api.saveApiProfile(record);
            setProfileDraft(null);
            setNotice("IOTEC API profile saved.");
            await load();
          }}
        />
      ) : null}
      {callbackDraft ? (
        <CallbackEditor
          draft={callbackDraft}
          setDraft={setCallbackDraft}
          onSave={async (record: any) => {
            await api.saveCallback(record);
            setCallbackDraft(null);
            setNotice("IOTEC callback saved.");
            await load();
          }}
        />
      ) : null}
    </main>
  );
}

function Overview({ dashboard, summary }: any) {
  const cards = [
    { label: "Gross revenue", value: money(summary.gross_revenue), icon: Wallet },
    { label: "Net revenue", value: money(summary.net_revenue), icon: Activity },
    { label: "Telecom fees", value: money(summary.telecom_fees), icon: Timer },
    { label: "Pending poll queue", value: dashboard?.polling?.ready_pending || 0, icon: RefreshCw },
  ];

  return (
    <>
      <div className="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
        {cards.map((card) => {
          const Icon = card.icon;
          return (
            <div key={card.label} className="rounded-lg border border-slate-200 bg-white p-5 shadow-sm">
              <div className="mb-4 flex items-center justify-between">
                <span className="text-sm text-slate-500">{card.label}</span>
                <Icon className="size-4 text-slate-500" />
              </div>
              <div className="text-2xl font-semibold">{card.value}</div>
            </div>
          );
        })}
      </div>

      <div className="grid gap-5 xl:grid-cols-[1fr_360px]">
        <div className="rounded-lg border border-slate-200 bg-white p-5 shadow-sm">
          <div className="mb-4 flex items-center justify-between">
            <h2 className="font-semibold">IOTEC 30-day collections</h2>
            <Badge variant="outline">{dashboard?.database?.ok ? "connected" : "offline"}</Badge>
          </div>
          <div className="h-72">
            <ResponsiveContainer width="100%" height="100%">
              <AreaChart data={dashboard?.daily_revenue || []}>
                <CartesianGrid strokeDasharray="3 3" vertical={false} />
                <XAxis dataKey="label" tickLine={false} axisLine={false} tick={{ fontSize: 12 }} />
                <YAxis tickLine={false} axisLine={false} tick={{ fontSize: 12 }} />
                <Tooltip formatter={(value) => money(String(value))} />
                <Area type="monotone" dataKey="total" stroke="#0284c7" fill="#e0f2fe" strokeWidth={2} />
              </AreaChart>
            </ResponsiveContainer>
          </div>
        </div>
        <div className="grid gap-5">
          <StatePanel dashboard={dashboard} />
          <div className="rounded-lg border border-slate-200 bg-white p-5 shadow-sm">
            <h2 className="mb-4 font-semibold">Status mix</h2>
            <div className="grid gap-2">
              {(dashboard?.status_breakdown || []).map((row: any) => (
                <div key={row.status} className="flex items-center justify-between rounded-lg bg-slate-50 px-3 py-2 text-sm">
                  <span className={`rounded-full border px-2 py-0.5 text-xs ${statusClass(row.status)}`}>{row.status}</span>
                  <span className="font-medium">{row.count}</span>
                </div>
              ))}
            </div>
          </div>
        </div>
      </div>
    </>
  );
}

function StatePanel({ dashboard }: any) {
  const rows = [
    { label: "Database", value: dashboard?.database?.ok ? "Connected" : "Needs setup", icon: Database },
    { label: "Active profiles", value: (dashboard?.api_profiles || []).filter((p: any) => p.status === "active").length, icon: PlugZap },
    { label: "Polling", value: dashboard?.polling?.enabled ? "Enabled" : "Disabled", icon: Timer },
    { label: "SMS", value: dashboard?.sms?.enabled ? "Enabled" : "Disabled", icon: XCircle },
  ];
  return (
    <div className="rounded-lg border border-slate-200 bg-white p-5 shadow-sm">
      <h2 className="mb-4 font-semibold">IOTEC state</h2>
      <div className="grid gap-3 text-sm">
        {rows.map((row) => {
          const Icon = row.icon;
          return (
            <div key={row.label} className="flex items-center justify-between rounded-lg bg-slate-50 px-3 py-2">
              <span className="flex items-center gap-2 text-slate-600">
                <Icon className="size-4" />
                {row.label}
              </span>
              <span className="font-medium">{row.value}</span>
            </div>
          );
        })}
      </div>
    </div>
  );
}

function Transactions({ transactions, meta, search, setSearch, status, setStatus }: any) {
  return (
    <div className="rounded-lg border border-slate-200 bg-white shadow-sm">
      <div className="flex flex-col gap-3 border-b border-slate-200 p-4 md:flex-row md:items-center md:justify-between">
        <div>
          <h2 className="font-semibold">IOTEC Transactions</h2>
          <p className="text-sm text-slate-500">{meta.total || 0} records</p>
        </div>
        <div className="flex flex-col gap-2 sm:flex-row">
          <label className="relative">
            <Search className="absolute left-3 top-2.5 size-4 text-slate-400" />
            <input
              className="h-10 w-full rounded-lg border border-slate-200 pl-9 pr-3 text-sm outline-none focus:border-slate-500 sm:w-72"
              value={search}
              onChange={(event) => setSearch(event.target.value)}
              placeholder="External ref, phone, voucher"
            />
          </label>
          <select className="h-10 rounded-lg border border-slate-200 px-3 text-sm outline-none" value={status} onChange={(event) => setStatus(event.target.value)}>
            <option value="all">All statuses</option>
            <option value="success">Success</option>
            <option value="pending">Pending</option>
            <option value="failed">Failed</option>
          </select>
        </div>
      </div>
      <div className="overflow-x-auto">
        <table className="w-full min-w-[1080px] text-left text-sm">
          <thead className="border-b border-slate-200 bg-slate-50 text-xs uppercase text-slate-500">
            <tr>
              <th className="px-4 py-3">External ID</th>
              <th className="px-4 py-3">IOTEC ID</th>
              <th className="px-4 py-3">Payer</th>
              <th className="px-4 py-3">Site</th>
              <th className="px-4 py-3">Amount</th>
              <th className="px-4 py-3">Fees</th>
              <th className="px-4 py-3">Status</th>
              <th className="px-4 py-3">Voucher</th>
              <th className="px-4 py-3">Created</th>
            </tr>
          </thead>
          <tbody>
            {transactions.map((tx: any) => (
              <tr key={tx.id || tx.external_ref} className="border-b border-slate-100">
                <td className="px-4 py-3 font-mono text-xs">{tx.external_ref}</td>
                <td className="px-4 py-3 font-mono text-xs">{tx.transaction_ref || "-"}</td>
                <td className="px-4 py-3">{tx.msisdn}</td>
                <td className="px-4 py-3">{tx.origin_site || "Unknown"}</td>
                <td className="px-4 py-3 font-medium">{money(tx.amount)}</td>
                <td className="px-4 py-3 text-xs text-slate-500">{money(tx.telecom_fee + tx.platform_fee)}</td>
                <td className="px-4 py-3">
                  <span className={`rounded-full border px-2 py-1 text-xs ${statusClass(tx.status)}`}>{tx.status}</span>
                </td>
                <td className="px-4 py-3">{tx.voucher_code || tx.voucher_type || "-"}</td>
                <td className="px-4 py-3 text-slate-500">{tx.created_at || "-"}</td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
    </div>
  );
}

function Records({ title, action, onAdd, records, render }: any) {
  return (
    <div className="rounded-lg border border-slate-200 bg-white shadow-sm">
      <div className="flex items-center justify-between border-b border-slate-200 p-4">
        <div>
          <h2 className="font-semibold">{title}</h2>
          <p className="text-sm text-slate-500">{records.length} configured</p>
        </div>
        <Button onClick={onAdd}>
          <PlugZap className="size-4" />
          {action}
        </Button>
      </div>
      <div className="grid gap-3 p-4">{records.map(render)}</div>
    </div>
  );
}

function RecordRow({ icon: Icon, title, subtitle, status, onEdit, onDelete }: any) {
  return (
    <div className="flex flex-col gap-3 rounded-lg border border-slate-200 p-4 md:flex-row md:items-center md:justify-between">
      <div className="flex min-w-0 items-start gap-3">
        <div className="flex size-10 shrink-0 items-center justify-center rounded-lg bg-slate-100">
          <Icon className="size-5 text-slate-600" />
        </div>
        <div className="min-w-0">
          <div className="flex flex-wrap items-center gap-2">
            <h3 className="font-medium">{title}</h3>
            <span className={`rounded-full border px-2 py-0.5 text-xs ${statusClass(status)}`}>{status}</span>
          </div>
          <p className="break-words text-sm text-slate-500">{subtitle}</p>
        </div>
      </div>
      <div className="flex gap-2">
        <Button variant="outline" size="icon" onClick={onEdit}>
          <Pencil className="size-4" />
        </Button>
        <Button variant="outline" size="icon" onClick={onDelete}>
          <Trash2 className="size-4" />
        </Button>
      </div>
    </div>
  );
}

function SettingsView({ groupedSettings, settingsDraft, setSettingsDraft, onSave, onTest }: any) {
  function updateSetting(key: string, value: unknown) {
    setSettingsDraft(settingsDraft.map((setting: any) => (setting.key === key ? { ...setting, value } : setting)));
  }

  return (
    <div className="grid gap-5">
      <div className="flex justify-end gap-2">
        <Button variant="outline" onClick={onTest}>
          <Database className="size-4" />
          Test IOTEC DB
        </Button>
        <Button onClick={onSave}>
          <Save className="size-4" />
          Save
        </Button>
      </div>
      {Object.entries(groupedSettings).map(([group, rows]: any) => (
        <div key={group} className="rounded-lg border border-slate-200 bg-white shadow-sm">
          <div className="border-b border-slate-200 p-4">
            <h2 className="font-semibold capitalize">{group.replaceAll("_", " ")}</h2>
          </div>
          <div className="grid gap-4 p-4 md:grid-cols-2">
            {rows.map((setting: any) => (
              <label key={setting.key} className="grid gap-2 text-sm">
                <span className="font-medium text-slate-700">{setting.label || setting.key}</span>
                {setting.type === "boolean" ? (
                  <select className="h-10 rounded-lg border border-slate-200 px-3 outline-none" value={String(setting.value)} onChange={(event) => updateSetting(setting.key, event.target.value === "true")}>
                    <option value="true">Enabled</option>
                    <option value="false">Disabled</option>
                  </select>
                ) : (
                  <input
                    className="h-10 rounded-lg border border-slate-200 px-3 outline-none focus:border-slate-500"
                    type={setting.is_sensitive ? "password" : setting.type === "integer" || setting.type === "float" ? "number" : "text"}
                    value={setting.value ?? ""}
                    onChange={(event) => updateSetting(setting.key, event.target.value)}
                  />
                )}
                <span className="text-xs text-slate-500">{setting.description}</span>
              </label>
            ))}
          </div>
        </div>
      ))}
    </div>
  );
}

function SideEditor({ title, onClose, children }: any) {
  return (
    <div className="fixed inset-0 z-40 bg-slate-950/30">
      <div className="absolute right-0 top-0 h-full w-full max-w-2xl overflow-y-auto bg-white shadow-xl">
        <div className="sticky top-0 flex items-center justify-between border-b border-slate-200 bg-white p-4">
          <h2 className="font-semibold">{title}</h2>
          <Button variant="ghost" size="icon" onClick={onClose}>
            <XCircle className="size-4" />
          </Button>
        </div>
        <div className="p-5">{children}</div>
      </div>
    </div>
  );
}

function ProfileEditor({ draft, setDraft, onSave }: any) {
  return (
    <SideEditor title={draft.id ? "Edit IOTEC API profile" : "Add IOTEC API profile"} onClose={() => setDraft(null)}>
      <FormGrid>
        <TextInput label="Name" value={draft.name} onChange={(name: string) => setDraft({ ...draft, name })} />
        <TextInput label="Code" value={draft.code} onChange={(code: string) => setDraft({ ...draft, code })} />
        <SelectInput label="Status" value={draft.status} options={["active", "inactive", "draft"]} onChange={(status: string) => setDraft({ ...draft, status })} />
        <SelectInput label="Environment" value={draft.environment} options={["production", "sandbox", "staging", "local"]} onChange={(environment: string) => setDraft({ ...draft, environment })} />
        <TextInput label="Auth URL" value={draft.auth_url} onChange={(auth_url: string) => setDraft({ ...draft, auth_url })} />
        <TextInput label="API base URL" value={draft.api_base_url} onChange={(api_base_url: string) => setDraft({ ...draft, api_base_url })} />
        <TextInput label="Wallet ID" value={draft.wallet_id || ""} onChange={(wallet_id: string) => setDraft({ ...draft, wallet_id })} />
        <TextInput label="Client ID" value={draft.client_id || ""} onChange={(client_id: string) => setDraft({ ...draft, client_id })} />
        <TextInput label="Client secret" value={draft.client_secret || ""} onChange={(client_secret: string) => setDraft({ ...draft, client_secret })} />
        <TextInput label="Callback URL" value={draft.callback_url || ""} onChange={(callback_url: string) => setDraft({ ...draft, callback_url })} />
        <TextInput label="Currency" value={draft.default_currency} onChange={(default_currency: string) => setDraft({ ...draft, default_currency })} />
        <TextInput label="Category" value={draft.default_category} onChange={(default_category: string) => setDraft({ ...draft, default_category })} />
        <JsonArea label="Endpoint settings" value={draft.settings || {}} onChange={(settings) => setDraft({ ...draft, settings })} />
        <TextArea label="Notes" value={draft.notes || ""} onChange={(notes: string) => setDraft({ ...draft, notes })} />
        <Button className="md:col-span-2" onClick={() => onSave(draft)}>
          <Save className="size-4" />
          Save profile
        </Button>
      </FormGrid>
    </SideEditor>
  );
}

function CallbackEditor({ draft, setDraft, onSave }: any) {
  return (
    <SideEditor title={draft.id ? "Edit IOTEC callback" : "Add IOTEC callback"} onClose={() => setDraft(null)}>
      <FormGrid>
        <TextInput label="Name" value={draft.name} onChange={(name: string) => setDraft({ ...draft, name })} />
        <TextInput label="Event" value={draft.event} onChange={(event: string) => setDraft({ ...draft, event })} />
        <SelectInput label="Method" value={draft.method} options={["POST", "GET", "PUT", "PATCH"]} onChange={(method: string) => setDraft({ ...draft, method })} />
        <SelectInput label="Status" value={draft.is_active ? "active" : "inactive"} options={["active", "inactive"]} onChange={(value: string) => setDraft({ ...draft, is_active: value === "active" })} />
        <TextInput label="URL" value={draft.url} onChange={(url: string) => setDraft({ ...draft, url })} />
        <JsonArea label="Expected fields" value={{ fields: draft.expected_fields || [] }} onChange={(value) => setDraft({ ...draft, expected_fields: Array.isArray(value.fields) ? value.fields : [] })} />
        <JsonArea label="Headers" value={draft.headers || {}} onChange={(headers) => setDraft({ ...draft, headers })} />
        <TextArea label="Notes" value={draft.notes || ""} onChange={(notes: string) => setDraft({ ...draft, notes })} />
        <Button className="md:col-span-2" onClick={() => onSave(draft)}>
          <Save className="size-4" />
          Save callback
        </Button>
      </FormGrid>
    </SideEditor>
  );
}

function FormGrid({ children }: any) {
  return <div className="grid gap-4 md:grid-cols-2">{children}</div>;
}

function TextInput({ label, value, onChange, type = "text" }: any) {
  return (
    <label className="grid gap-2 text-sm">
      <span className="font-medium text-slate-700">{label}</span>
      <input className="h-10 rounded-lg border border-slate-200 px-3 outline-none focus:border-slate-500" type={type} value={value} onChange={(event) => onChange(event.target.value)} />
    </label>
  );
}

function TextArea({ label, value, onChange }: any) {
  return (
    <label className="grid gap-2 text-sm md:col-span-2">
      <span className="font-medium text-slate-700">{label}</span>
      <textarea className="min-h-[96px] rounded-lg border border-slate-200 px-3 py-2 outline-none focus:border-slate-500" value={value} onChange={(event) => onChange(event.target.value)} />
    </label>
  );
}

function SelectInput({ label, value, options, onChange }: any) {
  return (
    <label className="grid gap-2 text-sm">
      <span className="font-medium text-slate-700">{label}</span>
      <select className="h-10 rounded-lg border border-slate-200 px-3 outline-none focus:border-slate-500" value={value} onChange={(event) => onChange(event.target.value)}>
        {options.map((option: string) => (
          <option key={option} value={option}>
            {option}
          </option>
        ))}
      </select>
    </label>
  );
}

function JsonArea({ label, value, onChange }: any) {
  const [text, setText] = useState(JSON.stringify(value || {}, null, 2));
  const [error, setError] = useState("");

  useEffect(() => {
    setText(JSON.stringify(value || {}, null, 2));
  }, [value]);

  return (
    <label className="grid gap-2 text-sm md:col-span-2">
      <span className="font-medium text-slate-700">{label}</span>
      <textarea
        className="min-h-[112px] rounded-lg border border-slate-200 bg-white px-3 py-2 font-mono text-xs outline-none focus:border-slate-500"
        value={text}
        onChange={(event) => {
          setText(event.target.value);
          try {
            onChange(event.target.value.trim() ? JSON.parse(event.target.value) : {});
            setError("");
          } catch {
            setError("Invalid JSON");
          }
        }}
      />
      {error ? <span className="text-xs text-rose-600">{error}</span> : null}
    </label>
  );
}
