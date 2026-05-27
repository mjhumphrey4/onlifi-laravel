import { useEffect, useState } from 'react';
import { Copy, Loader2, Plus, Router, ShieldCheck } from 'lucide-react';
import { useSite } from '../context/SiteContext';

interface NasEntry {
  id: number;
  shortname: string;
  description?: string;
  router_identifier: string;
  provisioning_token?: string;
}

export function Provisioning() {
  const { selectedSite } = useSite();
  const [entries, setEntries] = useState<NasEntry[]>([]);
  const [selected, setSelected] = useState<any>(null);
  const [name, setName] = useState('Main Router');
  const [loading, setLoading] = useState(true);
  const [creating, setCreating] = useState(false);
  const [copied, setCopied] = useState('');

  const headers = () => ({
    Authorization: `Bearer ${localStorage.getItem('tenant_token')}`,
    Accept: 'application/json',
    'Content-Type': 'application/json',
    ...(localStorage.getItem('selected_site_id') ? { 'X-Site-ID': localStorage.getItem('selected_site_id') as string } : {}),
  });

  const load = async () => {
    setLoading(true);
    try {
      const response = await fetch('/api/nas', { headers: headers() });
      const data = await response.json();
      setEntries(data.nas_entries || []);
    } finally {
      setLoading(false);
    }
  };

  useEffect(() => {
    if (selectedSite?.name) {
      setName(`${selectedSite.name} Router`);
    }
    load();
  }, [selectedSite?.id]);

  const createRouter = async () => {
    setCreating(true);
    try {
      const response = await fetch('/api/nas', {
        method: 'POST',
        headers: headers(),
        body: JSON.stringify({ name }),
      });
      const data = await response.json();
      if (!response.ok) throw new Error(data.message || data.error || 'Failed to create router');
      setSelected(data);
      await load();
    } catch (error: any) {
      alert(error.message || 'Failed to create router');
    } finally {
      setCreating(false);
    }
  };

  const openEntry = async (entry: NasEntry) => {
    const response = await fetch(`/api/nas/${entry.id}`, { headers: headers() });
    const data = await response.json();
    setSelected(data);
  };

  const copy = async (value: string, label: string) => {
    await navigator.clipboard.writeText(value);
    setCopied(label);
    window.setTimeout(() => setCopied(''), 1600);
  };

  if (loading) {
    return <div className="min-h-screen grid place-items-center"><Loader2 className="w-8 h-8 animate-spin text-primary" /></div>;
  }

  return (
    <div className="min-h-screen bg-background p-6 lg:p-8 space-y-6">
      <div>
        <h1 className="text-2xl font-semibold text-foreground">Router Provisioning</h1>
        <p className="text-muted-foreground mt-1">Create a unique provisioning endpoint and run one MikroTik command to configure RADIUS, hotspot, DHCP, firewall, telemetry, and captive pages.</p>
      </div>

      <div className="grid lg:grid-cols-[360px_1fr] gap-6">
        <div className="space-y-4">
          <div className="bg-card border border-border rounded-lg p-5 space-y-3">
            <h2 className="font-semibold">New router</h2>
            <input value={name} onChange={(e) => setName(e.target.value)} className="w-full px-3 py-2 rounded-lg bg-background border border-input" />
            <button onClick={createRouter} disabled={creating} className="w-full inline-flex items-center justify-center gap-2 px-4 py-2 rounded-lg bg-primary text-primary-foreground hover:bg-primary/90 disabled:opacity-60">
              {creating ? <Loader2 className="w-4 h-4 animate-spin" /> : <Plus className="w-4 h-4" />}
              Create provisioning endpoint
            </button>
          </div>

          <div className="bg-card border border-border rounded-lg p-5">
            <h2 className="font-semibold mb-3">Routers</h2>
            <div className="space-y-2">
              {entries.length === 0 ? (
                <p className="text-sm text-muted-foreground">No routers registered yet.</p>
              ) : entries.map((entry) => (
                <button key={entry.id} onClick={() => openEntry(entry)} className="w-full text-left p-3 rounded-lg border border-border hover:bg-muted">
                  <div className="flex items-center gap-2 font-medium"><Router className="w-4 h-4" /> {entry.shortname}</div>
                  <p className="text-xs text-muted-foreground mt-1">{entry.router_identifier}</p>
                </button>
              ))}
            </div>
          </div>
        </div>

        <div className="bg-card border border-border rounded-lg p-5 space-y-5">
          {!selected ? (
            <div className="text-center py-16">
              <ShieldCheck className="w-12 h-12 text-muted-foreground mx-auto mb-3" />
              <p className="text-muted-foreground">Create or select a router to view its provisioning script.</p>
            </div>
          ) : (
            <>
              <div>
                <h2 className="font-semibold">One-command install</h2>
                <p className="text-sm text-muted-foreground mt-1">Run this in MikroTik terminal. The script is unique to this tenant and router.</p>
              </div>
              <div className="rounded-lg bg-slate-950 text-green-300 p-4 text-sm font-mono overflow-x-auto">
                {selected.fetch_command}
              </div>
              <button onClick={() => copy(selected.fetch_command, 'command')} className="inline-flex items-center gap-2 px-4 py-2 rounded-lg border border-border hover:bg-muted">
                <Copy className="w-4 h-4" />
                {copied === 'command' ? 'Copied' : 'Copy command'}
              </button>

              <div>
                <h3 className="font-semibold mb-2">Provisioning URL</h3>
                <div className="rounded-lg bg-muted p-3 text-sm break-all">{selected.provisioning_url}</div>
              </div>

              <div>
                <h3 className="font-semibold mb-2">Full script preview</h3>
                <pre className="max-h-[520px] overflow-auto rounded-lg bg-slate-950 text-slate-100 p-4 text-xs whitespace-pre-wrap">{selected.mikrotik_script}</pre>
              </div>
            </>
          )}
        </div>
      </div>
    </div>
  );
}
