import { useState } from "react";
import { Ticket, Plus, Search, Download, TrendingUp, CheckCircle, XCircle, Clock,
  Edit2, Trash2, ToggleLeft, ToggleRight, Wifi, Zap, Globe, Star, Shield, Flame, ChevronRight, X, Check } from "lucide-react";
import { PieChart, Pie, Cell, ResponsiveContainer, Tooltip } from "recharts";

/* ─── Types ─── */
type VoucherTab = "sales" | "types" | "templates";

interface VoucherType {
  id: string;
  name: string;
  price: number;
  duration: string;
  durationHours: number;
  speed: string;
  dataLimit: string;
  color: string;
  active: boolean;
  sales: number;
}

/* ─── Mock data ─── */
const pieData = [
  { name: "Used", value: 2840, color: "#00E5A0" },
  { name: "Active", value: 780, color: "#0066FF" },
  { name: "Expired", value: 227, color: "#FF4757" },
];

const recentSales = [
  { id: "V-4821", type: "Daily", amount: "MWK 5,000", sold: "2m ago", status: "used", buyer: "Chisomo B." },
  { id: "V-4820", type: "Weekly", amount: "MWK 10,000", sold: "12m ago", status: "active", buyer: "Takondwa P." },
  { id: "V-4819", type: "Daily", amount: "MWK 2,500", sold: "1h ago", status: "used", buyer: "Mphatso C." },
  { id: "V-4818", type: "Monthly", amount: "MWK 30,000", sold: "2h ago", status: "active", buyer: "Fatuma H." },
  { id: "V-4817", type: "Daily", amount: "MWK 5,000", sold: "3h ago", status: "expired", buyer: "James M." },
];

const defaultTypes: VoucherType[] = [
  { id: "t1", name: "Quick Browse", price: 2500, duration: "24 hrs", durationHours: 24, speed: "5 Mbps", dataLimit: "1 GB", color: "#00E5A0", active: true, sales: 1840 },
  { id: "t2", name: "Power Week", price: 10000, duration: "7 days", durationHours: 168, speed: "10 Mbps", dataLimit: "10 GB", color: "#0066FF", active: true, sales: 1240 },
  { id: "t3", name: "Monthly Pro", price: 30000, duration: "30 days", durationHours: 720, speed: "20 Mbps", dataLimit: "Unlimited", color: "#FF6B35", active: true, sales: 767 },
  { id: "t4", name: "Student Pack", price: 1500, duration: "12 hrs", durationHours: 12, speed: "3 Mbps", dataLimit: "500 MB", color: "#FFB800", active: false, sales: 312 },
];

/* ─── Templates ─── */
const templates = [
  {
    id: "tpl1",
    name: "Minimal Dark",
    preview: { bg: "#0A0F1E", accent: "#0066FF", text: "#ffffff", badge: "#1E2A45" },
    description: "Clean dark card with blue accent",
  },
  {
    id: "tpl2",
    name: "Gradient Glow",
    preview: { bg: "linear-gradient(135deg,#0066FF,#00E5A0)", accent: "#fff", text: "#ffffff", badge: "rgba(255,255,255,0.2)" },
    description: "Blue-to-green gradient energy look",
  },
  {
    id: "tpl3",
    name: "Premium Gold",
    preview: { bg: "linear-gradient(135deg,#1A1200,#3D2D00)", accent: "#FFB800", text: "#ffffff", badge: "rgba(255,184,0,0.15)" },
    description: "Dark gold luxury aesthetic",
  },
  {
    id: "tpl4",
    name: "Forest Green",
    preview: { bg: "linear-gradient(135deg,#002A14,#005229)", accent: "#00E5A0", text: "#ffffff", badge: "rgba(0,229,160,0.15)" },
    description: "Rich green for mobile money feel",
  },
  {
    id: "tpl5",
    name: "Coral Sunset",
    preview: { bg: "linear-gradient(135deg,#2A0A00,#4D1500)", accent: "#FF6B35", text: "#ffffff", badge: "rgba(255,107,53,0.15)" },
    description: "Warm orange for casual packages",
  },
  {
    id: "tpl6",
    name: "Arctic White",
    preview: { bg: "#F5F7FA", accent: "#0066FF", text: "#0A0F1E", badge: "#E8EDF5" },
    description: "Light clean card, print-friendly",
  },
];

/* ─── Edit Modal ─── */
function EditTypeModal({ type, onSave, onClose }: {
  type: VoucherType | null;
  onSave: (t: VoucherType) => void;
  onClose: () => void;
}) {
  const [form, setForm] = useState<VoucherType>(
    type ?? { id: `t${Date.now()}`, name: "", price: 0, duration: "", durationHours: 24, speed: "", dataLimit: "", color: "#00E5A0", active: true, sales: 0 }
  );

  const colors = ["#00E5A0", "#0066FF", "#FF6B35", "#FFB800", "#FF4757", "#A855F7"];

  return (
    <div className="absolute inset-0 bg-black/70 flex items-end z-50" style={{ backdropFilter: "blur(4px)" }}>
      <div className="w-full bg-[#151C30] rounded-t-3xl p-5 pb-8">
        <div className="flex items-center justify-between mb-5">
          <h3 className="text-white font-semibold" style={{ fontSize: "16px" }}>
            {type ? "Edit Voucher Type" : "New Voucher Type"}
          </h3>
          <button onClick={onClose} className="w-8 h-8 rounded-full bg-[#0A0F1E] flex items-center justify-center">
            <X size={14} className="text-[#8A94A6]" />
          </button>
        </div>

        <div className="space-y-3">
          {/* Name */}
          <div>
            <p className="text-[#8A94A6] text-xs mb-1.5">Package Name</p>
            <input
              value={form.name}
              onChange={(e) => setForm({ ...form, name: e.target.value })}
              placeholder="e.g. Power Week"
              className="w-full bg-[#0A0F1E] text-white text-sm rounded-xl px-3 py-2.5 outline-none placeholder-[#3A4560] border border-[#1E2A45]"
            />
          </div>

          {/* Price + Duration row */}
          <div className="grid grid-cols-2 gap-2">
            <div>
              <p className="text-[#8A94A6] text-xs mb-1.5">Price (MWK)</p>
              <input
                type="number"
                value={form.price}
                onChange={(e) => setForm({ ...form, price: Number(e.target.value) })}
                className="w-full bg-[#0A0F1E] text-white text-sm rounded-xl px-3 py-2.5 outline-none border border-[#1E2A45]"
              />
            </div>
            <div>
              <p className="text-[#8A94A6] text-xs mb-1.5">Duration</p>
              <input
                value={form.duration}
                onChange={(e) => setForm({ ...form, duration: e.target.value })}
                placeholder="7 days"
                className="w-full bg-[#0A0F1E] text-white text-sm rounded-xl px-3 py-2.5 outline-none border border-[#1E2A45]"
              />
            </div>
          </div>

          {/* Speed + Data */}
          <div className="grid grid-cols-2 gap-2">
            <div>
              <p className="text-[#8A94A6] text-xs mb-1.5">Speed Limit</p>
              <input
                value={form.speed}
                onChange={(e) => setForm({ ...form, speed: e.target.value })}
                placeholder="10 Mbps"
                className="w-full bg-[#0A0F1E] text-white text-sm rounded-xl px-3 py-2.5 outline-none border border-[#1E2A45]"
              />
            </div>
            <div>
              <p className="text-[#8A94A6] text-xs mb-1.5">Data Limit</p>
              <input
                value={form.dataLimit}
                onChange={(e) => setForm({ ...form, dataLimit: e.target.value })}
                placeholder="Unlimited"
                className="w-full bg-[#0A0F1E] text-white text-sm rounded-xl px-3 py-2.5 outline-none border border-[#1E2A45]"
              />
            </div>
          </div>

          {/* Color picker */}
          <div>
            <p className="text-[#8A94A6] text-xs mb-2">Accent Color</p>
            <div className="flex gap-2">
              {colors.map((c) => (
                <button
                  key={c}
                  onClick={() => setForm({ ...form, color: c })}
                  className="w-8 h-8 rounded-full flex items-center justify-center transition-transform"
                  style={{ background: c, transform: form.color === c ? "scale(1.2)" : "scale(1)" }}
                >
                  {form.color === c && <Check size={12} className="text-white" strokeWidth={3} />}
                </button>
              ))}
            </div>
          </div>
        </div>

        <button
          onClick={() => onSave(form)}
          className="w-full mt-5 py-3 rounded-xl bg-[#0066FF] text-white text-sm font-semibold"
        >
          Save Package
        </button>
      </div>
    </div>
  );
}

/* ─── Voucher card preview ─── */
function VoucherCardPreview({ template, type }: { template: typeof templates[0]; type: VoucherType }) {
  const bg = template.preview.bg;
  const accent = template.preview.accent;
  const text = template.preview.text;
  const badge = template.preview.badge;

  return (
    <div
      className="rounded-xl p-3 relative overflow-hidden"
      style={{ background: bg, minHeight: 80 }}
    >
      {/* Decorative circle */}
      <div className="absolute -right-4 -top-4 w-16 h-16 rounded-full opacity-20" style={{ background: accent }} />
      <div className="relative">
        <div className="flex items-center justify-between mb-2">
          <span className="text-xs font-bold" style={{ color: text }}>{type.name}</span>
          <div className="rounded-full px-2 py-0.5 text-xs font-medium" style={{ background: badge, color: accent }}>
            {type.duration}
          </div>
        </div>
        <p className="font-bold" style={{ color: text, fontSize: "16px" }}>
          MWK {type.price.toLocaleString()}
        </p>
        <div className="flex items-center gap-2 mt-1.5">
          <span className="text-xs opacity-70" style={{ color: text }}>{type.speed}</span>
          <span className="text-xs opacity-40" style={{ color: text }}>·</span>
          <span className="text-xs opacity-70" style={{ color: text }}>{type.dataLimit}</span>
        </div>
      </div>
    </div>
  );
}

/* ─── Main Component ─── */
export function VouchersScreen() {
  const [tab, setTab] = useState<VoucherTab>("sales");
  const [types, setTypes] = useState<VoucherType[]>(defaultTypes);
  const [editingType, setEditingType] = useState<VoucherType | null | undefined>(undefined); // undefined = closed
  const [selectedTemplate, setSelectedTemplate] = useState("tpl2");
  const [previewType, setPreviewType] = useState(defaultTypes[1]);

  const handleSave = (t: VoucherType) => {
    setTypes((prev) => {
      const exists = prev.find((x) => x.id === t.id);
      return exists ? prev.map((x) => (x.id === t.id ? t : x)) : [...prev, t];
    });
    setEditingType(undefined);
  };

  const handleDelete = (id: string) => setTypes((prev) => prev.filter((x) => x.id !== id));
  const handleToggle = (id: string) => setTypes((prev) => prev.map((x) => x.id === id ? { ...x, active: !x.active } : x));

  const activeTemplate = templates.find((t) => t.id === selectedTemplate)!;

  return (
    <div className="flex flex-col h-full bg-[#0A0F1E] overflow-y-auto relative">
      {/* Header */}
      <div className="px-5 pt-6 pb-3">
        <div className="flex items-center justify-between mb-4">
          <div>
            <p className="text-[#8A94A6] text-xs tracking-widest uppercase">Sales & Config</p>
            <h1 className="text-white" style={{ fontSize: "20px", fontWeight: 700, lineHeight: "1.3" }}>Vouchers</h1>
          </div>
          {tab === "types" && (
            <button onClick={() => setEditingType(null)} className="flex items-center gap-1.5 bg-[#0066FF] rounded-xl px-3 py-2">
              <Plus size={14} className="text-white" />
              <span className="text-white text-xs font-medium">New Type</span>
            </button>
          )}
          {tab === "sales" && (
            <button className="flex items-center gap-1.5 bg-[#0066FF] rounded-xl px-3 py-2">
              <Plus size={14} className="text-white" />
              <span className="text-white text-xs font-medium">Generate</span>
            </button>
          )}
        </div>

        {/* Tab switcher */}
        <div className="flex bg-[#151C30] rounded-xl p-1 gap-1">
          {(["sales", "types", "templates"] as VoucherTab[]).map((t) => (
            <button
              key={t}
              onClick={() => setTab(t)}
              className="flex-1 py-2 rounded-lg text-xs font-medium capitalize transition-all"
              style={{
                background: tab === t ? "#0066FF" : "transparent",
                color: tab === t ? "#fff" : "#8A94A6",
              }}
            >
              {t === "sales" ? "Sales" : t === "types" ? "Types" : "Templates"}
            </button>
          ))}
        </div>
      </div>

      {/* ── SALES TAB ── */}
      {tab === "sales" && (
        <>
          <div className="px-5 mb-3">
            <div className="flex items-center gap-2 bg-[#151C30] rounded-xl px-3 py-2.5">
              <Search size={14} className="text-[#8A94A6]" />
              <input placeholder="Search voucher ID..." className="bg-transparent text-white text-sm outline-none w-full placeholder-[#8A94A6]" />
            </div>
          </div>

          <div className="mx-5 mb-4 bg-[#151C30] rounded-2xl p-4">
            <div className="flex items-center justify-between mb-2">
              <div>
                <p className="text-[#8A94A6] text-xs">Total Vouchers Sold</p>
                <p className="text-white font-bold mt-0.5" style={{ fontSize: "26px", lineHeight: 1 }}>3,847</p>
                <div className="flex items-center gap-1 mt-1.5">
                  <TrendingUp size={12} className="text-[#00E5A0]" />
                  <span className="text-[#00E5A0] text-xs">+18.3% vs last month</span>
                </div>
              </div>
              <ResponsiveContainer width={110} height={110}>
                <PieChart>
                  <Pie data={pieData} cx="50%" cy="50%" innerRadius={30} outerRadius={48} dataKey="value" strokeWidth={0}>
                    {pieData.map((entry, index) => <Cell key={index} fill={entry.color} />)}
                  </Pie>
                  <Tooltip contentStyle={{ background: "#0A0F1E", border: "1px solid #1E2A45", borderRadius: 8, fontSize: 10 }} itemStyle={{ color: "#fff" }} />
                </PieChart>
              </ResponsiveContainer>
            </div>
            <div className="flex items-center gap-4 mt-1">
              {pieData.map((d) => (
                <div key={d.name} className="flex items-center gap-1.5">
                  <div className="w-2 h-2 rounded-full" style={{ background: d.color }} />
                  <span className="text-[#8A94A6] text-xs">{d.name}: {d.value}</span>
                </div>
              ))}
            </div>
          </div>

          <div className="mx-5 mb-5 bg-[#151C30] rounded-2xl p-4">
            <div className="flex items-center justify-between mb-3">
              <h3 className="text-white" style={{ fontSize: "14px", fontWeight: 600 }}>Recent Sales</h3>
              <button className="flex items-center gap-1 text-[#8A94A6] text-xs"><Download size={11} /> Export</button>
            </div>
            <div className="space-y-1">
              {recentSales.map((v) => (
                <div key={v.id} className="flex items-center gap-3 py-2.5 border-b border-[#0A0F1E] last:border-0">
                  <div className={`w-8 h-8 rounded-lg flex items-center justify-center flex-shrink-0 ${
                    v.status === "used" ? "bg-[#00E5A0]/15" : v.status === "active" ? "bg-[#0066FF]/15" : "bg-[#FF4757]/15"
                  }`}>
                    {v.status === "used" && <CheckCircle size={14} className="text-[#00E5A0]" />}
                    {v.status === "active" && <Clock size={14} className="text-[#0066FF]" />}
                    {v.status === "expired" && <XCircle size={14} className="text-[#FF4757]" />}
                  </div>
                  <div className="flex-1 min-w-0">
                    <div className="flex items-center gap-2">
                      <span className="text-white text-xs font-medium">#{v.id}</span>
                      <span className="text-[#8A94A6] text-xs bg-[#0A0F1E] rounded px-1.5 py-0.5">{v.type}</span>
                    </div>
                    <p className="text-[#8A94A6] text-xs mt-0.5">{v.buyer} · {v.sold}</p>
                  </div>
                  <span className="text-[#00E5A0] text-xs font-semibold">{v.amount}</span>
                </div>
              ))}
            </div>
          </div>
        </>
      )}

      {/* ── TYPES TAB ── */}
      {tab === "types" && (
        <div className="px-5 pb-5 space-y-3">
          <p className="text-[#8A94A6] text-xs">
            Configure your voucher packages — pricing, speed, data, and duration.
          </p>
          {types.map((vt) => (
            <div key={vt.id} className="bg-[#151C30] rounded-2xl p-4">
              <div className="flex items-start justify-between mb-3">
                <div className="flex items-center gap-2.5">
                  <div className="w-10 h-10 rounded-xl flex items-center justify-center flex-shrink-0" style={{ background: `${vt.color}20` }}>
                    <Ticket size={16} style={{ color: vt.color }} />
                  </div>
                  <div>
                    <p className="text-white text-sm font-semibold">{vt.name}</p>
                    <p className="text-[#8A94A6] text-xs">{vt.sales.toLocaleString()} sold</p>
                  </div>
                </div>
                <div className="flex items-center gap-2">
                  <button onClick={() => setEditingType(vt)} className="w-7 h-7 rounded-lg bg-[#0A0F1E] flex items-center justify-center">
                    <Edit2 size={11} className="text-[#8A94A6]" />
                  </button>
                  <button onClick={() => handleDelete(vt.id)} className="w-7 h-7 rounded-lg bg-[#FF4757]/10 flex items-center justify-center">
                    <Trash2 size={11} className="text-[#FF4757]" />
                  </button>
                </div>
              </div>

              {/* Details grid */}
              <div className="grid grid-cols-3 gap-2 mb-3">
                {[
                  { icon: Zap, label: "Speed", value: vt.speed },
                  { icon: Globe, label: "Data", value: vt.dataLimit },
                  { icon: Clock, label: "Duration", value: vt.duration },
                ].map((d) => (
                  <div key={d.label} className="bg-[#0A0F1E] rounded-xl p-2 text-center">
                    <d.icon size={12} className="mx-auto mb-1" style={{ color: vt.color }} />
                    <p className="text-white text-xs font-medium">{d.value}</p>
                    <p className="text-[#8A94A6]" style={{ fontSize: "10px" }}>{d.label}</p>
                  </div>
                ))}
              </div>

              <div className="flex items-center justify-between">
                <span className="text-white font-bold" style={{ fontSize: "16px" }}>MWK {vt.price.toLocaleString()}</span>
                <button onClick={() => handleToggle(vt.id)} className="flex items-center gap-1.5">
                  {vt.active
                    ? <ToggleRight size={22} style={{ color: vt.color }} />
                    : <ToggleLeft size={22} className="text-[#3A4560]" />}
                  <span className="text-xs" style={{ color: vt.active ? vt.color : "#3A4560" }}>
                    {vt.active ? "Active" : "Inactive"}
                  </span>
                </button>
              </div>

              {/* Progress bar */}
              <div className="mt-3 h-1 bg-[#0A0F1E] rounded-full overflow-hidden">
                <div className="h-full rounded-full transition-all" style={{ background: vt.color, width: `${Math.min((vt.sales / 1840) * 100, 100)}%` }} />
              </div>
            </div>
          ))}
        </div>
      )}

      {/* ── TEMPLATES TAB ── */}
      {tab === "templates" && (
        <div className="px-5 pb-5">
          {/* Live preview */}
          <div className="mb-4">
            <p className="text-[#8A94A6] text-xs mb-2">Preview with package</p>
            <div className="flex gap-2 mb-3 overflow-x-auto pb-1">
              {types.filter((t) => t.active).map((t) => (
                <button
                  key={t.id}
                  onClick={() => setPreviewType(t)}
                  className="flex-shrink-0 px-3 py-1.5 rounded-xl text-xs font-medium border transition-all"
                  style={{
                    borderColor: previewType.id === t.id ? t.color : "#1E2A45",
                    background: previewType.id === t.id ? `${t.color}15` : "transparent",
                    color: previewType.id === t.id ? t.color : "#8A94A6",
                  }}
                >
                  {t.name}
                </button>
              ))}
            </div>
            <VoucherCardPreview template={activeTemplate} type={previewType} />
          </div>

          {/* Template grid */}
          <p className="text-[#8A94A6] text-xs mb-3">Choose a template style</p>
          <div className="grid grid-cols-2 gap-3">
            {templates.map((tpl) => {
              const isSelected = selectedTemplate === tpl.id;
              return (
                <button
                  key={tpl.id}
                  onClick={() => setSelectedTemplate(tpl.id)}
                  className="relative rounded-2xl overflow-hidden text-left transition-all"
                  style={{
                    border: `2px solid ${isSelected ? "#0066FF" : "#1E2A45"}`,
                  }}
                >
                  {/* Mini card preview */}
                  <div className="p-3" style={{ background: tpl.preview.bg }}>
                    <div className="flex items-center justify-between mb-1.5">
                      <span className="text-xs font-bold truncate" style={{ color: tpl.preview.text, maxWidth: 60 }}>
                        {previewType.name}
                      </span>
                      <div className="rounded-full px-1.5 py-0.5" style={{ background: tpl.preview.badge }}>
                        <span style={{ fontSize: "9px", color: tpl.preview.accent }}>7d</span>
                      </div>
                    </div>
                    <p className="font-bold" style={{ color: tpl.preview.text, fontSize: "13px" }}>
                      MWK {previewType.price.toLocaleString()}
                    </p>
                    <div className="w-full h-0.5 rounded-full mt-2" style={{ background: tpl.preview.accent, opacity: 0.5 }} />
                  </div>
                  {/* Label */}
                  <div className="bg-[#151C30] px-3 py-2">
                    <p className="text-white" style={{ fontSize: "11px", fontWeight: 600 }}>{tpl.name}</p>
                    <p className="text-[#8A94A6]" style={{ fontSize: "10px" }}>{tpl.description}</p>
                  </div>
                  {/* Selected badge */}
                  {isSelected && (
                    <div className="absolute top-2 right-2 w-5 h-5 bg-[#0066FF] rounded-full flex items-center justify-center">
                      <Check size={10} className="text-white" strokeWidth={3} />
                    </div>
                  )}
                </button>
              );
            })}
          </div>

          {/* Apply button */}
          <button className="w-full mt-4 py-3 rounded-xl bg-[#0066FF] text-white text-sm font-semibold flex items-center justify-center gap-2">
            <Star size={14} />
            Apply "{activeTemplate.name}" Template
          </button>
        </div>
      )}

      {/* Edit modal overlay */}
      {editingType !== undefined && (
        <EditTypeModal type={editingType} onSave={handleSave} onClose={() => setEditingType(undefined)} />
      )}
    </div>
  );
}
