import { useEffect, useMemo, useState } from 'react';
import { CheckCircle, Loader2, Paintbrush, Save } from 'lucide-react';
import { activateCaptivePortalTemplate, getCaptivePortalTemplates, saveCaptivePortalTemplate } from '../utils/api';
import { useSite } from '../context/SiteContext';

interface BaseTemplate {
  theme: string;
  name: string;
  description: string;
  design: Record<string, string>;
}

export function CaptivePortal() {
  const { selectedSite } = useSite();
  const [baseTemplates, setBaseTemplates] = useState<BaseTemplate[]>([]);
  const [savedTemplates, setSavedTemplates] = useState<any[]>([]);
  const [selected, setSelected] = useState<BaseTemplate | null>(null);
  const [design, setDesign] = useState<Record<string, string>>({});
  const [name, setName] = useState('');
  const [loading, setLoading] = useState(true);
  const [saving, setSaving] = useState(false);

  const previewStyle = useMemo(() => ({
    background: `linear-gradient(135deg, ${design.secondary_color || '#1e3c72'} 0%, ${design.primary_color || '#2a5298'} 50%, ${design.accent_color || '#ff6b35'} 100%)`,
    color: '#0f172a',
  }), [design]);

  const fetchTemplates = async () => {
    setLoading(true);
    try {
      const data = await getCaptivePortalTemplates();
      setBaseTemplates(data.base_templates || []);
      setSavedTemplates(data.templates || []);
      const first = data.base_templates?.[0];
      const active = data.active_template;
      setSelected(first);
      setDesign(active?.design || first?.design || {});
      setName(active?.name || first?.name || 'Captive Portal');
    } finally {
      setLoading(false);
    }
  };

  useEffect(() => {
    fetchTemplates();
  }, [selectedSite?.id]);

  const chooseTemplate = (template: BaseTemplate) => {
    setSelected(template);
    setDesign(template.design);
    setName(template.name);
  };

  const save = async () => {
    if (!selected) return;
    setSaving(true);
    try {
      await saveCaptivePortalTemplate({
        name,
        theme: selected.theme,
        design,
        is_active: true,
      });
      await fetchTemplates();
      alert('Captive portal template saved and activated');
    } catch (error: any) {
      alert(error.message || 'Failed to save template');
    } finally {
      setSaving(false);
    }
  };

  const activate = async (id: number) => {
    await activateCaptivePortalTemplate(id);
    await fetchTemplates();
  };

  if (loading) {
    return <div className="min-h-screen grid place-items-center"><Loader2 className="w-8 h-8 animate-spin text-primary" /></div>;
  }

  return (
    <div className="min-h-screen bg-background p-6 lg:p-8 space-y-6">
      <div>
        <h1 className="text-2xl font-semibold text-foreground">Captive Page</h1>
        <p className="text-muted-foreground mt-1">Choose the full hotspot page, adjust the site-specific details, and deploy it through router provisioning.</p>
      </div>

      <div className="grid lg:grid-cols-[280px_1fr] gap-6">
        <div className="space-y-3">
          {baseTemplates.map((template) => (
            <button
              key={template.theme}
              onClick={() => chooseTemplate(template)}
              className={`w-full text-left p-4 rounded-lg border transition-colors ${selected?.theme === template.theme ? 'border-primary bg-primary/10' : 'border-border bg-card hover:bg-muted'}`}
            >
              <div className="flex items-center gap-2 font-medium text-card-foreground">
                <Paintbrush className="w-4 h-4" />
                {template.name}
              </div>
              <p className="text-sm text-muted-foreground mt-1">{template.description}</p>
            </button>
          ))}
        </div>

        <div className="grid xl:grid-cols-2 gap-6">
          <div className="bg-card border border-border rounded-lg p-5 space-y-4">
            <h2 className="font-semibold text-card-foreground">Visual Editor</h2>
            <label className="block text-sm">
              <span className="text-muted-foreground">Template name</span>
              <input value={name} onChange={(e) => setName(e.target.value)} className="mt-1 w-full px-3 py-2 rounded-lg bg-background border border-input" />
            </label>
            {[
              ['site_display_name', 'Site display name'],
              ['subtitle', 'Subtitle'],
              ['support_contact', 'Support contact'],
              ['powered_by', 'Footer brand'],
            ].map(([field, label]) => (
              <label key={field} className="block text-sm">
                <span className="text-muted-foreground">{label}</span>
                <input value={design[field] || ''} onChange={(e) => setDesign({ ...design, [field]: e.target.value })} className="mt-1 w-full px-3 py-2 rounded-lg bg-background border border-input" />
              </label>
            ))}
            <label className="block text-sm">
              <span className="text-muted-foreground">Marquee text</span>
              <textarea value={design.marquee_text || ''} onChange={(e) => setDesign({ ...design, marquee_text: e.target.value })} rows={3} className="mt-1 w-full px-3 py-2 rounded-lg bg-background border border-input" />
            </label>
            {[
              ['primary_color', 'Primary color'],
              ['secondary_color', 'Secondary color'],
              ['accent_color', 'Accent color'],
            ].map(([field, label]) => (
              <label key={field} className="flex items-center justify-between gap-3 text-sm">
                <span className="text-muted-foreground">{label}</span>
                <input type="color" value={design[field] || '#2563eb'} onChange={(e) => setDesign({ ...design, [field]: e.target.value })} className="h-10 w-16 rounded border border-input bg-background" />
              </label>
            ))}
            <button onClick={save} disabled={saving} className="inline-flex items-center gap-2 px-4 py-2 rounded-lg bg-primary text-primary-foreground hover:bg-primary/90 disabled:opacity-60">
              {saving ? <Loader2 className="w-4 h-4 animate-spin" /> : <Save className="w-4 h-4" />}
              Save and activate
            </button>
          </div>

          <div className="bg-card border border-border rounded-lg p-5">
            <h2 className="font-semibold text-card-foreground mb-4">Preview</h2>
            <div className="rounded-lg border border-border p-6 min-h-[360px] grid place-items-center" style={previewStyle}>
              <div className="w-full max-w-sm bg-white text-slate-900 rounded-[24px] shadow-xl overflow-hidden">
                <div className="p-5 text-center text-white" style={{ background: `linear-gradient(135deg, ${design.primary_color || '#2a5298'} 0%, ${design.secondary_color || '#1e3c72'} 100%)` }}>
                  <h3 className="text-2xl font-bold">{design.site_display_name || selectedSite?.name || 'Site Name'}</h3>
                  <p className="text-sm opacity-90 mt-1">{design.subtitle}</p>
                </div>
                <div className="p-5 space-y-3">
                  <input placeholder="Enter Voucher Code" className="w-full border rounded-xl px-3 py-3" />
                  <button style={{ background: `linear-gradient(135deg, ${design.accent_color || '#ff6b35'} 0%, #f7931e 100%)` }} className="w-full rounded-xl px-3 py-3 text-white font-semibold">Connect Now</button>
                  <div className="rounded-xl border p-3 text-sm">
                    <div className="flex justify-between"><span>24 Hours</span><strong>UGX 1,000</strong></div>
                  </div>
                  <p className="text-xs text-center text-slate-500">Need help? Contact: {design.support_contact}</p>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>

      {savedTemplates.length > 0 && (
        <div className="bg-card border border-border rounded-lg p-5">
          <h2 className="font-semibold text-card-foreground mb-3">Saved Templates</h2>
          <div className="grid md:grid-cols-2 lg:grid-cols-3 gap-3">
            {savedTemplates.map((template) => (
              <button key={template.id} onClick={() => activate(template.id)} className="text-left p-4 rounded-lg border border-border hover:bg-muted">
                <div className="flex items-center justify-between">
                  <span className="font-medium">{template.name}</span>
                  {template.is_active && <CheckCircle className="w-4 h-4 text-green-500" />}
                </div>
                <p className="text-sm text-muted-foreground">{template.theme}</p>
              </button>
            ))}
          </div>
        </div>
      )}
    </div>
  );
}
