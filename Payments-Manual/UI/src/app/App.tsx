import { type FormEvent, useEffect, useMemo, useState } from "react";
import {
  Activity,
  ArrowDownToLine,
  BellRing,
  CheckCircle2,
  CreditCard,
  Database,
  KeyRound,
  LayoutDashboard,
  LogOut,
  Pencil,
  Plug,
  Plus,
  RefreshCw,
  Save,
  Search,
  Settings,
  ShieldCheck,
  Trash2,
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

type View = "overview" | "transactions" | "providers" | "callbacks" | "withdrawals" | "settings";

type Provider = {
  id?: number;
  name: string;
  code: string;
  provider_type: string;
  status: string;
  priority: number;
  base_url: string;
  callback_url: string;
  credentials: Record<string, string>;
  settings: Record<string, unknown>;
  notes: string;
};

type CallbackEndpoint = {
  id?: number;
  name: string;
  event: string;
  method: string;
  url: string;
  headers: Record<string, string>;
  signing_secret: string;
  is_active: boolean;
  notes: string;
};

type WithdrawalApi = {
  id?: number;
  name: string;
  provider_code: string;
  status: string;
  base_url: string;
  credentials: Record<string, string>;
  settings: Record<string, unknown>;
  daily_limit: number;
  minimum_amount: number;
  notes: string;
};

const views: Array<{ id: View; label: string; icon: any }> = [
  { id: "overview", label: "Overview", icon: LayoutDashboard },
  { id: "transactions", label: "Transactions", icon: CreditCard },
  { id: "providers", label: "Providers", icon: Plug },
  { id: "callbacks", label: "Callbacks", icon: Webhook },
  { id: "withdrawals", label: "Withdrawals", icon: ArrowDownToLine },
  { id: "settings", label: "Settings", icon: Settings },
];

const blankProvider: Provider = {
  name: "",
  code: "",
  provider_type: "collection",
  status: "draft",
  priority: 100,
  base_url: "",
  callback_url: "",
  credentials: {},
  settings: {},
  notes: "",
};

const blankCallback: CallbackEndpoint = {
  name: "",
  event: "payment.success",
  method: "POST",
  url: "",
  headers: {},
  signing_secret: "",
  is_active: true,
  notes: "",
};

const blankWithdrawal: WithdrawalApi = {
  name: "",
  provider_code: "",
  status: "draft",
  base_url: "",
  credentials: {},
  settings: {},
  daily_limit: 0,
  minimum_amount: 0,
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

function parseJson(value: string, fallback: Record<string, unknown> = {}) {
  if (!value.trim()) return fallback;
  return JSON.parse(value);
}

function JsonArea({
  label,
  value,
  onChange,
}: {
  label: string;
  value: Record<string, unknown>;
  onChange: (value: Record<string, unknown>) => void;
}) {
  const [text, setText] = useState(JSON.stringify(value || {}, null, 2));
  const [error, setError] = useState("");

  useEffect(() => {
    setText(JSON.stringify(value || {}, null, 2));
  }, [value]);

  return (
    <label className="grid gap-2 text-sm">
      <span className="font-medium text-slate-700">{label}</span>
      <textarea
        className="min-h-[112px] rounded-lg border border-slate-200 bg-white px-3 py-2 font-mono text-xs outline-none focus:border-slate-500"
        value={text}
        onChange={(event) => {
          setText(event.target.value);
          try {
            onChange(parseJson(event.target.value));
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

function LoginScreen({ onLogin }: { onLogin: () => void }) {
  const [token, setLocalToken] = useState("");
  const [error, setError] = useState("");
  const [loading, setLoading] = useState(false);

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
        <div className="rounded-lg border border-slate-200 bg-white p-6 shadow-sm">
          <div className="mb-6 flex items-center gap-3">
            <div className="flex size-10 items-center justify-center rounded-lg bg-emerald-600 text-white">
              <ShieldCheck className="size-5" />
            </div>
            <div>
              <h1 className="text-xl font-semibold">Payments Manual</h1>
              <p className="text-sm text-slate-500">Admin console</p>
            </div>
          </div>
          <form className="grid gap-4" onSubmit={submit}>
            <label className="grid gap-2 text-sm">
              <span className="font-medium">Admin token</span>
              <input
                className="h-10 rounded-lg border border-slate-200 px-3 outline-none focus:border-slate-500"
                type="password"
                value={token}
                onChange={(event) => setLocalToken(event.target.value)}
              />
            </label>
            {error ? <div className="rounded-lg border border-rose-200 bg-rose-50 px-3 py-2 text-sm text-rose-700">{error}</div> : null}
            <Button type="submit" disabled={loading}>
              {loading ? <RefreshCw className="size-4 animate-spin" /> : <KeyRound className="size-4" />}
              Sign in
            </Button>
          </form>
        </div>
      </div>
    </main>
  );
}

export default function App() {
  const [authenticated, setAuthenticated] = useState(Boolean(getToken()));
  const [activeView, setActiveView] = useState<View>("overview");
  const [dashboard, setDashboard] = useState<any>(null);
  const [transactions, setTransactions] = useState<any[]>([]);
  const [transactionMeta, setTransactionMeta] = useState<any>({});
  const [search, setSearch] = useState("");
  const [status, setStatus] = useState("all");
  const [loading, setLoading] = useState(false);
  const [notice, setNotice] = useState("");
  const [error, setError] = useState("");
  const [providerDraft, setProviderDraft] = useState<Provider | null>(null);
  const [callbackDraft, setCallbackDraft] = useState<CallbackEndpoint | null>(null);
  const [withdrawalDraft, setWithdrawalDraft] = useState<WithdrawalApi | null>(null);
  const [settingsDraft, setSettingsDraft] = useState<any[]>([]);

  async function load() {
    setLoading(true);
    setError("");
    try {
      const [dashboardResult, transactionResult] = await Promise.all([
        api.dashboard(),
        api.transactions({ page: 1, per_page: 30, status, search }),
      ]);
      setDashboard(dashboardResult);
      setTransactions(transactionResult.data || []);
      setTransactionMeta(transactionResult.meta || {});
      setSettingsDraft(dashboardResult.settings || dashboardResult?.settings?.data || []);
    } catch (err) {
      const message = err instanceof Error ? err.message : "Unable to load dashboard";
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

  const summary = dashboard?.summary || {};
  const providers = dashboard?.providers || [];
  const callbacks = dashboard?.callbacks || [];
  const withdrawalApis = dashboard?.withdrawal_apis || [];

  const groupedSettings = useMemo(() => {
    const rows = Array.isArray(settingsDraft) ? settingsDraft : [];
    return rows.reduce((groups: Record<string, any[]>, setting) => {
      groups[setting.group || "general"] ||= [];
      groups[setting.group || "general"].push(setting);
      return groups;
    }, {});
  }, [settingsDraft]);

  if (!authenticated) return <LoginScreen onLogin={() => setAuthenticated(true)} />;

  return (
    <main className="min-h-screen bg-slate-100 text-slate-950">
      <aside className="fixed inset-y-0 left-0 hidden w-64 border-r border-slate-200 bg-white lg:block">
        <div className="flex h-16 items-center gap-3 border-b border-slate-200 px-5">
          <div className="flex size-9 items-center justify-center rounded-lg bg-emerald-600 text-white">
            <ShieldCheck className="size-5" />
          </div>
          <div>
            <div className="font-semibold">Payments Manual</div>
            <div className="text-xs text-slate-500">Standalone system</div>
          </div>
        </div>
        <nav className="grid gap-1 p-3">
          {views.map((view) => {
            const Icon = view.icon;
            return (
              <button
                key={view.id}
                className={`flex h-10 items-center gap-3 rounded-lg px-3 text-left text-sm font-medium ${
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
            <h1 className="text-lg font-semibold capitalize">{activeView}</h1>
            <p className="text-sm text-slate-500">{dashboard?.database?.message || "Manual payment administration"}</p>
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
              title="Sign out"
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

          {activeView === "overview" ? (
            <Overview summary={summary} dashboard={dashboard} setActiveView={setActiveView} />
          ) : null}

          {activeView === "transactions" ? (
            <TransactionsView
              transactions={transactions}
              meta={transactionMeta}
              search={search}
              setSearch={setSearch}
              status={status}
              setStatus={setStatus}
            />
          ) : null}

          {activeView === "providers" ? (
            <ManagementView
              title="Payment Providers"
              actionLabel="Add provider"
              onAdd={() => setProviderDraft({ ...blankProvider })}
              records={providers}
              renderRecord={(provider: Provider) => (
                <RecordRow
                  key={provider.id}
                  icon={Plug}
                  title={provider.name}
                  subtitle={`${provider.code} · ${provider.provider_type}`}
                  status={provider.status}
                  onEdit={() => setProviderDraft(provider)}
                  onDelete={async () => {
                    if (provider.id) await api.deleteProvider(provider.id);
                    await load();
                  }}
                />
              )}
            />
          ) : null}

          {activeView === "callbacks" ? (
            <ManagementView
              title="Callback Endpoints"
              actionLabel="Add callback"
              onAdd={() => setCallbackDraft({ ...blankCallback })}
              records={callbacks}
              renderRecord={(callback: CallbackEndpoint) => (
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

          {activeView === "withdrawals" ? (
            <ManagementView
              title="Withdrawal APIs"
              actionLabel="Add API"
              onAdd={() => setWithdrawalDraft({ ...blankWithdrawal })}
              records={withdrawalApis}
              renderRecord={(apiRecord: WithdrawalApi) => (
                <RecordRow
                  key={apiRecord.id}
                  icon={ArrowDownToLine}
                  title={apiRecord.name}
                  subtitle={`${apiRecord.provider_code} · min ${money(apiRecord.minimum_amount)} · daily ${money(apiRecord.daily_limit)}`}
                  status={apiRecord.status}
                  onEdit={() => setWithdrawalDraft(apiRecord)}
                  onDelete={async () => {
                    if (apiRecord.id) await api.deleteWithdrawalApi(apiRecord.id);
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
                setNotice("Settings saved.");
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

      {providerDraft ? (
        <ProviderEditor
          draft={providerDraft}
          setDraft={setProviderDraft}
          onSave={async (record) => {
            await api.saveProvider(record);
            setProviderDraft(null);
            setNotice("Provider saved.");
            await load();
          }}
        />
      ) : null}

      {callbackDraft ? (
        <CallbackEditor
          draft={callbackDraft}
          setDraft={setCallbackDraft}
          onSave={async (record) => {
            await api.saveCallback(record);
            setCallbackDraft(null);
            setNotice("Callback saved.");
            await load();
          }}
        />
      ) : null}

      {withdrawalDraft ? (
        <WithdrawalEditor
          draft={withdrawalDraft}
          setDraft={setWithdrawalDraft}
          onSave={async (record) => {
            await api.saveWithdrawalApi(record);
            setWithdrawalDraft(null);
            setNotice("Withdrawal API saved.");
            await load();
          }}
        />
      ) : null}
    </main>
  );
}

function Overview({ summary, dashboard, setActiveView }: any) {
  const cards = [
    { label: "Gross revenue", value: money(summary.gross_revenue), icon: CreditCard },
    { label: "Today revenue", value: money(summary.today_revenue), icon: Activity },
    { label: "Successful", value: summary.successful_transactions || 0, icon: CheckCircle2 },
    { label: "Failed", value: summary.failed_transactions || 0, icon: XCircle },
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
            <h2 className="font-semibold">30-day revenue</h2>
            <Badge variant="outline">{dashboard?.database?.ok ? "connected" : "offline"}</Badge>
          </div>
          <div className="h-72">
            <ResponsiveContainer width="100%" height="100%">
              <AreaChart data={dashboard?.daily_revenue || []}>
                <CartesianGrid strokeDasharray="3 3" vertical={false} />
                <XAxis dataKey="label" tickLine={false} axisLine={false} tick={{ fontSize: 12 }} />
                <YAxis tickLine={false} axisLine={false} tick={{ fontSize: 12 }} />
                <Tooltip formatter={(value) => money(String(value))} />
                <Area type="monotone" dataKey="total" stroke="#059669" fill="#d1fae5" strokeWidth={2} />
              </AreaChart>
            </ResponsiveContainer>
          </div>
        </div>

        <div className="grid gap-5">
          <div className="rounded-lg border border-slate-200 bg-white p-5 shadow-sm">
            <h2 className="mb-4 font-semibold">System state</h2>
            <div className="grid gap-3 text-sm">
              <StateLine icon={Database} label="Legacy database" value={dashboard?.database?.ok ? "Connected" : "Needs setup"} />
              <StateLine icon={Plug} label="Active providers" value={(dashboard?.providers || []).filter((p: Provider) => p.status === "active").length} />
              <StateLine icon={Webhook} label="Callbacks" value={(dashboard?.callbacks || []).length} />
              <StateLine icon={BellRing} label="SMS" value={dashboard?.sms?.enabled ? "Enabled" : "Disabled"} />
            </div>
          </div>
          <div className="rounded-lg border border-slate-200 bg-white p-5 shadow-sm">
            <h2 className="mb-4 font-semibold">Top sites</h2>
            <div className="grid gap-3">
              {(dashboard?.top_sites || []).map((site: any) => (
                <button key={site.site} className="grid gap-1 rounded-lg bg-slate-50 p-3 text-left" onClick={() => setActiveView("transactions")}>
                  <div className="flex justify-between text-sm font-medium">
                    <span>{site.site}</span>
                    <span>{money(site.total)}</span>
                  </div>
                  <div className="text-xs text-slate-500">{site.count} transactions</div>
                </button>
              ))}
            </div>
          </div>
        </div>
      </div>
    </>
  );
}

function StateLine({ icon: Icon, label, value }: any) {
  return (
    <div className="flex items-center justify-between rounded-lg bg-slate-50 px-3 py-2">
      <span className="flex items-center gap-2 text-slate-600">
        <Icon className="size-4" />
        {label}
      </span>
      <span className="font-medium">{value}</span>
    </div>
  );
}

function TransactionsView({ transactions, meta, search, setSearch, status, setStatus }: any) {
  return (
    <div className="rounded-lg border border-slate-200 bg-white shadow-sm">
      <div className="flex flex-col gap-3 border-b border-slate-200 p-4 md:flex-row md:items-center md:justify-between">
        <div>
          <h2 className="font-semibold">Transactions</h2>
          <p className="text-sm text-slate-500">{meta.total || 0} records</p>
        </div>
        <div className="flex flex-col gap-2 sm:flex-row">
          <label className="relative">
            <Search className="absolute left-3 top-2.5 size-4 text-slate-400" />
            <input
              className="h-10 w-full rounded-lg border border-slate-200 pl-9 pr-3 text-sm outline-none focus:border-slate-500 sm:w-72"
              value={search}
              onChange={(event) => setSearch(event.target.value)}
              placeholder="Search"
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
        <table className="w-full min-w-[980px] text-left text-sm">
          <thead className="border-b border-slate-200 bg-slate-50 text-xs uppercase text-slate-500">
            <tr>
              <th className="px-4 py-3">Reference</th>
              <th className="px-4 py-3">Customer</th>
              <th className="px-4 py-3">Site</th>
              <th className="px-4 py-3">Voucher</th>
              <th className="px-4 py-3">Amount</th>
              <th className="px-4 py-3">Status</th>
              <th className="px-4 py-3">Created</th>
            </tr>
          </thead>
          <tbody>
            {transactions.map((tx: any) => (
              <tr key={tx.id || tx.external_ref} className="border-b border-slate-100">
                <td className="px-4 py-3 font-mono text-xs">{tx.external_ref}</td>
                <td className="px-4 py-3">{tx.msisdn}</td>
                <td className="px-4 py-3">{tx.origin_site || "Unknown"}</td>
                <td className="px-4 py-3">{tx.voucher_code || tx.voucher_type || "-"}</td>
                <td className="px-4 py-3 font-medium">{money(tx.amount)}</td>
                <td className="px-4 py-3">
                  <span className={`rounded-full border px-2 py-1 text-xs ${statusClass(tx.status)}`}>{tx.status}</span>
                </td>
                <td className="px-4 py-3 text-slate-500">{tx.created_at || "-"}</td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
    </div>
  );
}

function ManagementView({ title, actionLabel, onAdd, records, renderRecord }: any) {
  return (
    <div className="rounded-lg border border-slate-200 bg-white shadow-sm">
      <div className="flex items-center justify-between border-b border-slate-200 p-4">
        <div>
          <h2 className="font-semibold">{title}</h2>
          <p className="text-sm text-slate-500">{records.length} configured</p>
        </div>
        <Button onClick={onAdd}>
          <Plus className="size-4" />
          {actionLabel}
        </Button>
      </div>
      <div className="grid gap-3 p-4">{records.map(renderRecord)}</div>
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
        <Button variant="outline" size="icon" onClick={onEdit} title="Edit">
          <Pencil className="size-4" />
        </Button>
        <Button variant="outline" size="icon" onClick={onDelete} title="Delete">
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
          Test DB
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
                  <select
                    className="h-10 rounded-lg border border-slate-200 px-3 outline-none"
                    value={String(setting.value)}
                    onChange={(event) => updateSetting(setting.key, event.target.value === "true")}
                  >
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

function ProviderEditor({ draft, setDraft, onSave }: any) {
  return (
    <SideEditor title={draft.id ? "Edit provider" : "Add provider"} onClose={() => setDraft(null)}>
      <FormGrid>
        <TextInput label="Name" value={draft.name} onChange={(name: string) => setDraft({ ...draft, name })} />
        <TextInput label="Code" value={draft.code} onChange={(code: string) => setDraft({ ...draft, code })} />
        <SelectInput label="Type" value={draft.provider_type} options={["collection", "fallback", "verification", "other"]} onChange={(provider_type: string) => setDraft({ ...draft, provider_type })} />
        <SelectInput label="Status" value={draft.status} options={["active", "inactive", "draft"]} onChange={(status: string) => setDraft({ ...draft, status })} />
        <TextInput label="Priority" type="number" value={draft.priority} onChange={(priority: string) => setDraft({ ...draft, priority: Number(priority) })} />
        <TextInput label="Base URL" value={draft.base_url || ""} onChange={(base_url: string) => setDraft({ ...draft, base_url })} />
        <TextInput label="Callback URL" value={draft.callback_url || ""} onChange={(callback_url: string) => setDraft({ ...draft, callback_url })} />
        <JsonArea label="Credentials" value={draft.credentials || {}} onChange={(credentials) => setDraft({ ...draft, credentials })} />
        <JsonArea label="Settings" value={draft.settings || {}} onChange={(settings) => setDraft({ ...draft, settings })} />
        <TextArea label="Notes" value={draft.notes || ""} onChange={(notes: string) => setDraft({ ...draft, notes })} />
        <Button className="md:col-span-2" onClick={() => onSave(draft)}>
          <Save className="size-4" />
          Save provider
        </Button>
      </FormGrid>
    </SideEditor>
  );
}

function CallbackEditor({ draft, setDraft, onSave }: any) {
  return (
    <SideEditor title={draft.id ? "Edit callback" : "Add callback"} onClose={() => setDraft(null)}>
      <FormGrid>
        <TextInput label="Name" value={draft.name} onChange={(name: string) => setDraft({ ...draft, name })} />
        <TextInput label="Event" value={draft.event} onChange={(event: string) => setDraft({ ...draft, event })} />
        <SelectInput label="Method" value={draft.method} options={["POST", "GET", "PUT", "PATCH"]} onChange={(method: string) => setDraft({ ...draft, method })} />
        <SelectInput label="Status" value={draft.is_active ? "active" : "inactive"} options={["active", "inactive"]} onChange={(value: string) => setDraft({ ...draft, is_active: value === "active" })} />
        <TextInput label="URL" value={draft.url} onChange={(url: string) => setDraft({ ...draft, url })} />
        <TextInput label="Signing secret" value={draft.signing_secret || ""} onChange={(signing_secret: string) => setDraft({ ...draft, signing_secret })} />
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

function WithdrawalEditor({ draft, setDraft, onSave }: any) {
  return (
    <SideEditor title={draft.id ? "Edit withdrawal API" : "Add withdrawal API"} onClose={() => setDraft(null)}>
      <FormGrid>
        <TextInput label="Name" value={draft.name} onChange={(name: string) => setDraft({ ...draft, name })} />
        <TextInput label="Provider code" value={draft.provider_code} onChange={(provider_code: string) => setDraft({ ...draft, provider_code })} />
        <SelectInput label="Status" value={draft.status} options={["active", "inactive", "draft"]} onChange={(status: string) => setDraft({ ...draft, status })} />
        <TextInput label="Base URL" value={draft.base_url || ""} onChange={(base_url: string) => setDraft({ ...draft, base_url })} />
        <TextInput label="Daily limit" type="number" value={draft.daily_limit} onChange={(daily_limit: string) => setDraft({ ...draft, daily_limit: Number(daily_limit) })} />
        <TextInput label="Minimum amount" type="number" value={draft.minimum_amount} onChange={(minimum_amount: string) => setDraft({ ...draft, minimum_amount: Number(minimum_amount) })} />
        <JsonArea label="Credentials" value={draft.credentials || {}} onChange={(credentials) => setDraft({ ...draft, credentials })} />
        <JsonArea label="Settings" value={draft.settings || {}} onChange={(settings) => setDraft({ ...draft, settings })} />
        <TextArea label="Notes" value={draft.notes || ""} onChange={(notes: string) => setDraft({ ...draft, notes })} />
        <Button className="md:col-span-2" onClick={() => onSave(draft)}>
          <Save className="size-4" />
          Save withdrawal API
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
