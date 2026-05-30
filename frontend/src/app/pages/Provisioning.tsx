import { useEffect, useState } from 'react';
import { Copy, Loader2, Router, ShieldCheck } from 'lucide-react';
import { useSite } from '../context/SiteContext';
import { API_BASE } from '../utils/api';

interface ProvisioningDetails {
  nas?: {
    id: number;
    shortname: string;
    router_identifier: string;
  };
  fetch_command?: string;
  provisioning_url?: string;
  mikrotik_script?: string;
}

export function Provisioning() {
  const { selectedSite } = useSite();
  const [details, setDetails] = useState<ProvisioningDetails | null>(null);
  const [loading, setLoading] = useState(true);
  const [copied, setCopied] = useState('');

  const headers = () => ({
    Authorization: `Bearer ${localStorage.getItem('tenant_token')}`,
    Accept: 'application/json',
    'Content-Type': 'application/json',
    ...(selectedSite?.id ? { 'X-Site-ID': String(selectedSite.id) } : {}),
  });

  const load = async () => {
    if (!selectedSite?.id) {
      setDetails(null);
      setLoading(false);
      return;
    }

    setLoading(true);
    try {
      const listResponse = await fetch(`${API_BASE}/nas`, { headers: headers() });
      const listData = await listResponse.json();
      const siteRouter = listData.nas_entries?.[0];

      if (!siteRouter) {
        const createResponse = await fetch(`${API_BASE}/nas`, {
          method: 'POST',
          headers: headers(),
          body: JSON.stringify({ name: selectedSite.name }),
        });
        const createData = await createResponse.json();
        if (!createResponse.ok) throw new Error(createData.message || createData.error || 'Failed to prepare router provisioning');
        setDetails(createData);
        return;
      }

      const detailResponse = await fetch(`${API_BASE}/nas/${siteRouter.id}`, { headers: headers() });
      const detailData = await detailResponse.json();
      setDetails(detailData);
    } catch (error: any) {
      alert(error.message || 'Failed to load router provisioning');
      setDetails(null);
    } finally {
      setLoading(false);
    }
  };

  useEffect(() => {
    load();
  }, [selectedSite?.id]);

  const copy = async (value: string | undefined, label: string) => {
    if (!value) return;
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
        <p className="text-muted-foreground mt-1">Each site has one router. Paste the one-command install into MikroTik Terminal so it downloads and immediately imports the setup script.</p>
      </div>

      {!details ? (
        <div className="bg-card border border-border rounded-lg p-12 text-center">
          <ShieldCheck className="w-12 h-12 text-muted-foreground mx-auto mb-3" />
          <p className="text-muted-foreground">Select a site to view its router provisioning script.</p>
        </div>
      ) : (
        <div className="grid lg:grid-cols-[320px_1fr] gap-6">
          <div className="bg-card border border-border rounded-lg p-5 space-y-4">
            <div className="flex items-center gap-3">
              <div className="w-11 h-11 rounded-lg bg-primary/10 flex items-center justify-center">
                <Router className="w-5 h-5 text-primary" />
              </div>
              <div>
                <h2 className="font-semibold">{selectedSite?.name}</h2>
                <p className="text-sm text-muted-foreground">Site router</p>
              </div>
            </div>
            <div>
              <p className="text-xs text-muted-foreground mb-1">Router identity</p>
              <code className="block rounded-lg bg-muted p-3 text-xs break-all">{details.nas?.router_identifier}</code>
            </div>
            <div>
              <p className="text-xs text-muted-foreground mb-1">Provisioning URL</p>
              <div className="rounded-lg bg-muted p-3 text-xs break-all">{details.provisioning_url}</div>
            </div>
          </div>

          <div className="bg-card border border-border rounded-lg p-5 space-y-5">
            <div>
              <h2 className="font-semibold">One-command install</h2>
              <p className="text-sm text-muted-foreground mt-1">Paste this exact command into the MikroTik terminal. Opening the URL alone only saves the script; this command also runs it.</p>
            </div>
            <div className="rounded-lg bg-slate-950 text-green-300 p-4 text-sm font-mono overflow-x-auto">
              {details.fetch_command}
            </div>
            <button onClick={() => copy(details.fetch_command, 'command')} className="inline-flex items-center gap-2 px-4 py-2 rounded-lg border border-border hover:bg-muted">
              <Copy className="w-4 h-4" />
              {copied === 'command' ? 'Copied' : 'Copy command'}
            </button>

            <div>
              <h3 className="font-semibold mb-2">Full script preview</h3>
              <pre className="max-h-[520px] overflow-auto rounded-lg bg-slate-950 text-slate-100 p-4 text-xs whitespace-pre-wrap">{details.mikrotik_script}</pre>
            </div>
          </div>
        </div>
      )}
    </div>
  );
}
