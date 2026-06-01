import { useEffect, useState } from 'react';
import { CheckCircle, Download, Image, Loader2, Paintbrush, Plus, Save, Trash2, Upload } from 'lucide-react';
import { API_BASE, activateCaptivePortalTemplate, getCaptivePortalTemplates, saveCaptivePortalTemplate } from '../utils/api';
import { useSite } from '../context/SiteContext';

interface BaseTemplate {
  theme: string;
  name: string;
  description: string;
  design: Record<string, any>;
}

interface PackageRow {
  duration: string;
  description: string;
  display_price: string;
  amount: string;
  package_type: string;
  package_name: string;
  is_family_package: boolean;
}

export function CaptivePortal() {
  const { selectedSite } = useSite();
  const [baseTemplates, setBaseTemplates] = useState<BaseTemplate[]>([]);
  const [savedTemplates, setSavedTemplates] = useState<any[]>([]);
  const [selected, setSelected] = useState<BaseTemplate | null>(null);
  const [design, setDesign] = useState<Record<string, any>>({});
  const [name, setName] = useState('');
  const [loading, setLoading] = useState(true);
  const [saving, setSaving] = useState(false);
  const [uploadingLogo, setUploadingLogo] = useState(false);
  const [previewHtml, setPreviewHtml] = useState('');
  const [previewLoading, setPreviewLoading] = useState(false);
  const [activeTab, setActiveTab] = useState<'design' | 'packages' | 'features'>('design');

  const cloneDesign = (value: Record<string, any>) => JSON.parse(JSON.stringify(value || {}));

  const fetchTemplates = async () => {
    setLoading(true);
    try {
      const data = await getCaptivePortalTemplates();
      setBaseTemplates(data.base_templates || []);
      setSavedTemplates(data.templates || []);
      const first = data.base_templates?.[0];
      const active = data.active_template;
      const activeBase = data.base_templates?.find((template: BaseTemplate) => template.theme === active?.theme) || first;
      setSelected(activeBase);
      setDesign(cloneDesign(active?.design || activeBase?.design || {}));
      setName(active?.name || first?.name || 'Captive Portal');
    } finally {
      setLoading(false);
    }
  };

  useEffect(() => {
    fetchTemplates();
  }, [selectedSite?.id]);

  useEffect(() => {
    if (!selected) return;

    const timer = window.setTimeout(async () => {
      setPreviewLoading(true);
      try {
        const token = localStorage.getItem('tenant_token');
        const siteId = localStorage.getItem('selected_site_id');
        const response = await fetch(`${API_BASE}/tenant/captive-portal/preview`, {
          method: 'POST',
          headers: {
            Authorization: `Bearer ${token}`,
            Accept: 'text/html',
            'Content-Type': 'application/json',
            ...(siteId ? { 'X-Site-ID': siteId } : {}),
          },
          body: JSON.stringify({
            name,
            theme: selected.theme,
            design,
          }),
        });

        setPreviewHtml(response.ok ? await response.text() : '<!doctype html><html><body><p>Preview unavailable.</p></body></html>');
      } catch (error) {
        setPreviewHtml('<!doctype html><html><body><p>Preview unavailable.</p></body></html>');
      } finally {
        setPreviewLoading(false);
      }
    }, 250);

    return () => window.clearTimeout(timer);
  }, [design, name, selected, selectedSite?.id]);

  const chooseTemplate = (template: BaseTemplate) => {
    setSelected(template);
    setDesign(cloneDesign(template.design));
    setName(template.name);
  };

  const packages: PackageRow[] = Array.isArray(design.packages) ? design.packages : [];
  const features = {
    show_logo: true,
    show_marquee: true,
    show_find_voucher: true,
    show_trial: true,
    show_footer: true,
    show_payment_modal: true,
    ...(design.features || {}),
  };

  const updatePackage = (index: number, field: keyof PackageRow, value: string | boolean) => {
    const next = [...packages];
    next[index] = { ...next[index], [field]: value };
    setDesign({ ...design, packages: next });
  };

  const addPackage = () => {
    setDesign({
      ...design,
      packages: [
        ...packages,
        {
          duration: 'New Package',
          description: '',
          display_price: 'UGX 1,000',
          amount: '1000',
          package_type: 'new_package',
          package_name: 'New Package',
          is_family_package: false,
        },
      ],
    });
  };

  const removePackage = (index: number) => {
    setDesign({ ...design, packages: packages.filter((_, itemIndex) => itemIndex !== index) });
  };

  const updateFeature = (field: keyof typeof features, value: boolean) => {
    setDesign({ ...design, features: { ...features, [field]: value } });
  };

  const uploadLogo = async (file?: File) => {
    if (!file) return;

    setUploadingLogo(true);
    try {
      const token = localStorage.getItem('tenant_token');
      const siteId = localStorage.getItem('selected_site_id');
      const formData = new FormData();
      formData.append('logo', file);

      const response = await fetch(`${API_BASE}/tenant/captive-portal/logo`, {
        method: 'POST',
        headers: {
          Authorization: `Bearer ${token}`,
          Accept: 'application/json',
          ...(siteId ? { 'X-Site-ID': siteId } : {}),
        },
        body: formData,
      });

      const data = await response.json();
      if (!response.ok) {
        throw new Error(data?.message || data?.error || 'Failed to upload logo');
      }

      setDesign({
        ...design,
        logo_url: data.logo_url,
        features: { ...features, show_logo: true },
      });
    } catch (error: any) {
      alert(error.message || 'Failed to upload logo');
    } finally {
      setUploadingLogo(false);
    }
  };

  const downloadGenerated = async () => {
    if (!selected) return;

    const token = localStorage.getItem('tenant_token');
    const siteId = localStorage.getItem('selected_site_id');
    const response = await fetch(`${API_BASE}/tenant/captive-portal/download`, {
      method: 'POST',
      headers: {
        Authorization: `Bearer ${token}`,
        Accept: 'text/html',
        'Content-Type': 'application/json',
        ...(siteId ? { 'X-Site-ID': siteId } : {}),
      },
      body: JSON.stringify({
        name,
        theme: selected.theme,
        design,
      }),
    });

    if (!response.ok) {
      alert('Failed to download captive page');
      return;
    }

    const blob = await response.blob();
    const url = window.URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = `${(selectedSite?.slug || selectedSite?.name || 'site').toString().toLowerCase().replace(/[^a-z0-9]+/g, '-')}-login.html`;
    document.body.appendChild(a);
    a.click();
    a.remove();
    window.URL.revokeObjectURL(url);
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
        <div className="flex flex-wrap items-center justify-between gap-3">
          <div>
            <h1 className="text-2xl font-semibold text-foreground">Captive Page</h1>
            <p className="text-muted-foreground mt-1">Choose the full hotspot page, adjust the site-specific details, and deploy it through router provisioning.</p>
          </div>
          <button onClick={downloadGenerated} className="inline-flex items-center gap-2 px-4 py-2 rounded-lg bg-primary text-primary-foreground hover:bg-primary/90">
            <Download className="w-4 h-4" />
            Download login.html
          </button>
        </div>
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
            <h2 className="font-semibold text-card-foreground">Template Editor</h2>
            <div className="grid grid-cols-3 gap-2 rounded-lg bg-muted p-1">
              {[
                ['design', 'Design'],
                ['packages', 'Packages'],
                ['features', 'Features'],
              ].map(([id, label]) => (
                <button
                  key={id}
                  onClick={() => setActiveTab(id as typeof activeTab)}
                  className={`px-3 py-2 rounded-md text-sm transition-colors ${activeTab === id ? 'bg-background text-foreground shadow-sm' : 'text-muted-foreground hover:text-foreground'}`}
                >
                  {label}
                </button>
              ))}
            </div>

            {activeTab === 'design' && (
              <div className="space-y-4">
                <label className="block text-sm">
                  <span className="text-muted-foreground">Template name</span>
                  <input value={name} onChange={(e) => setName(e.target.value)} className="mt-1 w-full px-3 py-2 rounded-lg bg-background border border-input" />
                </label>
                {[
                  ['site_display_name', 'Site display name'],
                  ['subtitle', 'Subtitle'],
                  ['pricing_title', 'Mobile money heading'],
                  ['support_contact', 'Support contact'],
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
                <div className="rounded-lg border border-border p-4 space-y-3">
                  <div className="flex items-center gap-2 text-sm font-medium text-card-foreground">
                    <Image className="w-4 h-4 text-primary" />
                    Logo
                  </div>
                  <div className="flex flex-col sm:flex-row sm:items-center gap-3">
                    <div className="w-20 h-20 rounded-lg border border-border bg-background grid place-items-center overflow-hidden">
                      {design.logo_url ? (
                        <img src={design.logo_url} alt="Uploaded logo" className="max-w-full max-h-full object-contain" />
                      ) : (
                        <Image className="w-8 h-8 text-muted-foreground" />
                      )}
                    </div>
                    <div className="flex-1 space-y-2">
                      <label className="inline-flex items-center gap-2 px-3 py-2 rounded-lg border border-border hover:bg-muted cursor-pointer text-sm">
                        {uploadingLogo ? <Loader2 className="w-4 h-4 animate-spin" /> : <Upload className="w-4 h-4" />}
                        Upload logo
                        <input
                          type="file"
                          accept="image/png,image/jpeg,image/webp,image/gif,image/svg+xml"
                          className="hidden"
                          disabled={uploadingLogo}
                          onChange={(event) => {
                            uploadLogo(event.target.files?.[0]);
                            event.currentTarget.value = '';
                          }}
                        />
                      </label>
                      {design.logo_url && (
                        <button onClick={() => setDesign({ ...design, logo_url: '' })} className="ml-2 text-sm text-destructive hover:underline">
                          Remove
                        </button>
                      )}
                      <p className="text-xs text-muted-foreground">PNG, JPG, WebP, GIF, or SVG up to 2MB.</p>
                    </div>
                  </div>
                </div>
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
              </div>
            )}

            {activeTab === 'packages' && (
              <div className="space-y-3">
                {packages.map((pkg, index) => (
                  <div key={index} className="rounded-lg border border-border p-3 space-y-3">
                    <div className="flex items-center justify-between gap-3">
                      <span className="text-sm font-medium text-card-foreground">Package {index + 1}</span>
                      <button onClick={() => removePackage(index)} className="p-2 rounded-md text-destructive hover:bg-destructive/10">
                        <Trash2 className="w-4 h-4" />
                      </button>
                    </div>
                    <div className="grid sm:grid-cols-2 gap-3">
                      <input value={pkg.duration} onChange={(e) => updatePackage(index, 'duration', e.target.value)} placeholder="Display name" className="px-3 py-2 rounded-lg bg-background border border-input text-sm" />
                      <input value={pkg.description} onChange={(e) => updatePackage(index, 'description', e.target.value)} placeholder="Description" className="px-3 py-2 rounded-lg bg-background border border-input text-sm" />
                      <input value={pkg.display_price} onChange={(e) => updatePackage(index, 'display_price', e.target.value)} placeholder="Display price" className="px-3 py-2 rounded-lg bg-background border border-input text-sm" />
                      <input value={pkg.amount} onChange={(e) => updatePackage(index, 'amount', e.target.value)} placeholder="Payment amount" className="px-3 py-2 rounded-lg bg-background border border-input text-sm" />
                      <input value={pkg.package_type} onChange={(e) => updatePackage(index, 'package_type', e.target.value)} placeholder="Package code" className="px-3 py-2 rounded-lg bg-background border border-input text-sm" />
                      <input value={pkg.package_name} onChange={(e) => updatePackage(index, 'package_name', e.target.value)} placeholder="Modal package name" className="px-3 py-2 rounded-lg bg-background border border-input text-sm" />
                    </div>
                    <label className="flex items-center gap-2 text-sm text-muted-foreground">
                      <input type="checkbox" checked={Boolean(pkg.is_family_package)} onChange={(e) => updatePackage(index, 'is_family_package', e.target.checked)} />
                      Family package modal
                    </label>
                  </div>
                ))}
                <button onClick={addPackage} className="inline-flex items-center gap-2 px-3 py-2 rounded-lg border border-border hover:bg-muted">
                  <Plus className="w-4 h-4" />
                  Add Package
                </button>
              </div>
            )}

            {activeTab === 'features' && (
              <div className="space-y-3">
                {[
                  ['show_logo', 'Show WiFi logo'],
                  ['show_marquee', 'Show moving marquee'],
                  ['show_find_voucher', 'Show Find Lost Voucher'],
                  ['show_trial', 'Show MikroTik trial button'],
                  ['show_footer', 'Show footer'],
                  ['show_payment_modal', 'Show Mobile Money packages'],
                ].map(([field, label]) => (
                  <label key={field} className="flex items-center justify-between gap-3 rounded-lg border border-border px-3 py-2 text-sm">
                    <span className="text-card-foreground">{label}</span>
                    <input type="checkbox" checked={Boolean(features[field as keyof typeof features])} onChange={(e) => updateFeature(field as keyof typeof features, e.target.checked)} />
                  </label>
                ))}
              </div>
            )}

            <button onClick={save} disabled={saving} className="inline-flex items-center gap-2 px-4 py-2 rounded-lg bg-primary text-primary-foreground hover:bg-primary/90 disabled:opacity-60">
              {saving ? <Loader2 className="w-4 h-4 animate-spin" /> : <Save className="w-4 h-4" />}
              Save and activate
            </button>
          </div>

          <div className="bg-card border border-border rounded-lg p-5">
            <h2 className="font-semibold text-card-foreground mb-4">Preview</h2>
            <div className="relative rounded-lg border border-border overflow-hidden bg-black/5">
              {previewLoading && (
                <div className="absolute inset-0 z-10 grid place-items-center bg-background/70">
                  <Loader2 className="w-6 h-6 animate-spin text-primary" />
                </div>
              )}
              <iframe
                title="Captive page preview"
                sandbox=""
                srcDoc={previewHtml}
                className="w-full h-[760px] bg-white"
              />
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
