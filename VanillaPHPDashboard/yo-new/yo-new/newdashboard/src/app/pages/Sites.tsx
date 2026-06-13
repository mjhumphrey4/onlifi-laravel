import { useCallback, useEffect, useMemo, useState } from 'react';
import { Database, Globe2, MessageSquareText, Plus, RefreshCw, Save } from 'lucide-react';
import { apiSaveSite, apiSites } from '../utils/api';

interface SiteRecord {
  id?: number;
  slug: string;
  display_name: string;
  origin_site: string;
  db_host: string;
  db_port?: string | number | null;
  db_name: string;
  db_user: string;
  db_pass?: string;
  tenant_id?: string | null;
  onlifi_site_id?: string | null;
  default_profile?: string | null;
  api_key?: string | null;
  active: number | boolean;
  sms_enabled: number | boolean;
  sms_sender_id?: string | null;
  sms_message_category?: string | null;
  sms_brand_name?: string | null;
}

const blankSite: SiteRecord = {
  slug: '',
  display_name: '',
  origin_site: '',
  db_host: 'localhost',
  db_port: '',
  db_name: '',
  db_user: 'yo',
  db_pass: '',
  tenant_id: '',
  onlifi_site_id: '',
  default_profile: '',
  api_key: '',
  active: true,
  sms_enabled: false,
  sms_sender_id: 'ONLIFI',
  sms_message_category: 'customised',
  sms_brand_name: 'ONLIFI WiFi',
};

function inputClass() {
  return 'w-full px-3 py-2 bg-input-background border border-border rounded-lg text-sm text-foreground focus:outline-none focus:ring-2 focus:ring-ring';
}

function asBool(value: number | boolean | string | undefined) {
  return value === true || value === 1 || value === '1';
}

export function Sites() {
  const [sites, setSites] = useState<SiteRecord[]>([]);
  const [selectedId, setSelectedId] = useState<number | 'new'>('new');
  const [draft, setDraft] = useState<SiteRecord>(blankSite);
  const [loading, setLoading] = useState(true);
  const [saving, setSaving] = useState(false);
  const [message, setMessage] = useState('');

  const selected = useMemo(
    () => sites.find((site) => site.id === selectedId),
    [sites, selectedId],
  );

  const load = useCallback(async () => {
    setLoading(true);
    try {
      const res = await apiSites();
      const rows = res.sites ?? [];
      setSites(rows);
      if (selectedId !== 'new') {
        const current = rows.find((site: SiteRecord) => site.id === selectedId);
        if (current) setDraft({ ...current, db_pass: '' });
      }
    } finally {
      setLoading(false);
    }
  }, [selectedId]);

  useEffect(() => { load(); }, [load]);

  useEffect(() => {
    setDraft(selected ? { ...selected, db_pass: '' } : blankSite);
  }, [selected]);

  const update = (key: keyof SiteRecord, value: string | boolean) => {
    setDraft((current) => ({ ...current, [key]: value }));
  };

  const save = async () => {
    setSaving(true);
    setMessage('');
    try {
      const res = await apiSaveSite(draft as unknown as Record<string, unknown>);
      setSites(res.sites ?? []);
      setSelectedId((res.sites ?? []).find((site: SiteRecord) => site.slug === draft.slug)?.id ?? 'new');
      setMessage('Site saved.');
    } catch (e) {
      setMessage(e instanceof Error ? e.message : 'Save failed');
    } finally {
      setSaving(false);
    }
  };

  return (
    <div className="p-4 sm:p-6 lg:p-8">
      <div className="mb-6 sm:mb-8 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
        <div>
          <h1 className="text-2xl sm:text-3xl text-foreground mb-2">Sites</h1>
          <p className="text-sm sm:text-base text-muted-foreground">Manage reusable payment paths, tenant databases, and SMS behavior.</p>
        </div>
        <button
          onClick={() => { setSelectedId('new'); setDraft(blankSite); }}
          className="inline-flex items-center justify-center gap-2 px-4 py-2.5 bg-primary text-primary-foreground rounded-lg text-sm hover:bg-primary/90"
        >
          <Plus className="w-4 h-4" />
          New site
        </button>
      </div>

      {loading ? (
        <div className="flex items-center justify-center h-48">
          <RefreshCw className="w-6 h-6 text-primary animate-spin" />
        </div>
      ) : (
        <div className="grid grid-cols-1 xl:grid-cols-[minmax(0,1fr)_420px] gap-6">
          <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
            {sites.map((site) => (
              <button
                key={site.id}
                onClick={() => setSelectedId(site.id ?? 'new')}
                className={`text-left bg-card border rounded-lg p-5 hover:border-primary/60 transition-colors ${
                  selectedId === site.id ? 'border-primary ring-2 ring-primary/15' : 'border-border'
                }`}
              >
                <div className="flex items-start justify-between gap-3 mb-4">
                  <div>
                    <p className="text-lg font-semibold text-card-foreground">{site.display_name}</p>
                    <p className="text-xs text-muted-foreground">/{site.slug}/initiate.php</p>
                  </div>
                  <span className={`px-2 py-1 rounded-full text-xs ${asBool(site.active) ? 'bg-primary/10 text-primary' : 'bg-muted text-muted-foreground'}`}>
                    {asBool(site.active) ? 'Active' : 'Off'}
                  </span>
                </div>
                <div className="grid grid-cols-1 sm:grid-cols-3 gap-3 text-xs">
                  <div className="bg-muted/60 rounded-lg p-3">
                    <Globe2 className="w-4 h-4 text-primary mb-2" />
                    <p className="text-muted-foreground">Origin</p>
                    <p className="text-card-foreground font-medium truncate">{site.origin_site}</p>
                  </div>
                  <div className="bg-muted/60 rounded-lg p-3">
                    <Database className="w-4 h-4 text-primary mb-2" />
                    <p className="text-muted-foreground">Database</p>
                    <p className="text-card-foreground font-medium truncate">{site.db_name}</p>
                  </div>
                  <div className="bg-muted/60 rounded-lg p-3">
                    <MessageSquareText className="w-4 h-4 text-primary mb-2" />
                    <p className="text-muted-foreground">SMS</p>
                    <p className="text-card-foreground font-medium">{asBool(site.sms_enabled) ? 'ON' : 'OFF'}</p>
                  </div>
                </div>
              </button>
            ))}
          </div>

          <div className="bg-card border border-border rounded-lg p-5 h-fit">
            <div className="flex items-center justify-between mb-5">
              <h2 className="text-lg font-semibold text-card-foreground">{selectedId === 'new' ? 'New Site' : 'Site Settings'}</h2>
              <button
                onClick={save}
                disabled={saving}
                className="inline-flex items-center gap-2 px-4 py-2 bg-primary text-primary-foreground rounded-lg text-sm hover:bg-primary/90 disabled:opacity-60"
              >
                {saving ? <RefreshCw className="w-4 h-4 animate-spin" /> : <Save className="w-4 h-4" />}
                Save
              </button>
            </div>

            <div className="space-y-4">
              <div className="grid grid-cols-2 gap-3">
                <label className="space-y-1 text-xs text-muted-foreground">Site name<input className={inputClass()} value={draft.display_name} onChange={(e) => update('display_name', e.target.value)} /></label>
                <label className="space-y-1 text-xs text-muted-foreground">Path slug<input className={inputClass()} value={draft.slug} onChange={(e) => update('slug', e.target.value)} /></label>
              </div>
              <label className="space-y-1 text-xs text-muted-foreground">Origin site label<input className={inputClass()} value={draft.origin_site} onChange={(e) => update('origin_site', e.target.value)} /></label>

              <div className="grid grid-cols-2 gap-3">
                <label className="space-y-1 text-xs text-muted-foreground">DB host<input className={inputClass()} value={draft.db_host} onChange={(e) => update('db_host', e.target.value)} /></label>
                <label className="space-y-1 text-xs text-muted-foreground">DB name<input className={inputClass()} value={draft.db_name} onChange={(e) => update('db_name', e.target.value)} /></label>
                <label className="space-y-1 text-xs text-muted-foreground">DB user<input className={inputClass()} value={draft.db_user} onChange={(e) => update('db_user', e.target.value)} /></label>
                <label className="space-y-1 text-xs text-muted-foreground">DB password<input type="password" className={inputClass()} value={draft.db_pass ?? ''} onChange={(e) => update('db_pass', e.target.value)} placeholder={draft.id ? 'Leave unchanged' : ''} /></label>
              </div>

              <div className="grid grid-cols-2 gap-3">
                <label className="space-y-1 text-xs text-muted-foreground">Tenant ID<input className={inputClass()} value={draft.tenant_id ?? ''} onChange={(e) => update('tenant_id', e.target.value)} /></label>
                <label className="space-y-1 text-xs text-muted-foreground">Onlifi site ID<input className={inputClass()} value={draft.onlifi_site_id ?? ''} onChange={(e) => update('onlifi_site_id', e.target.value)} /></label>
              </div>

              <div className="grid grid-cols-2 gap-3">
                <label className="flex items-center justify-between gap-3 rounded-lg border border-border p-3 text-sm text-card-foreground">
                  Active
                  <input type="checkbox" checked={asBool(draft.active)} onChange={(e) => update('active', e.target.checked)} className="h-5 w-5 accent-primary" />
                </label>
                <label className="flex items-center justify-between gap-3 rounded-lg border border-border p-3 text-sm text-card-foreground">
                  SMS ON
                  <input type="checkbox" checked={asBool(draft.sms_enabled)} onChange={(e) => update('sms_enabled', e.target.checked)} className="h-5 w-5 accent-primary" />
                </label>
              </div>

              <div className="grid grid-cols-1 gap-3">
                <label className="space-y-1 text-xs text-muted-foreground">SMS sender ID<input className={inputClass()} value={draft.sms_sender_id ?? ''} onChange={(e) => update('sms_sender_id', e.target.value)} /></label>
                <label className="space-y-1 text-xs text-muted-foreground">SMS category<input className={inputClass()} value={draft.sms_message_category ?? ''} onChange={(e) => update('sms_message_category', e.target.value)} /></label>
                <label className="space-y-1 text-xs text-muted-foreground">SMS brand name<input className={inputClass()} value={draft.sms_brand_name ?? ''} onChange={(e) => update('sms_brand_name', e.target.value)} /></label>
              </div>

              {message && <p className="text-sm text-muted-foreground">{message}</p>}
            </div>
          </div>
        </div>
      )}
    </div>
  );
}
