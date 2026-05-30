import { useEffect, useState } from 'react';
import { AlertCircle, CheckCircle, Copy, Download, RefreshCw, Server } from 'lucide-react';
import { useSite } from '../context/SiteContext';
import { API_BASE } from '../utils/api';

interface NasEntry {
  id: number;
  router_identifier: string;
  shortname: string;
  description?: string;
  secret: string;
}

export function RadiusSetup() {
  const { selectedSite } = useSite();
  const [nas, setNas] = useState<NasEntry | null>(null);
  const [radiusServer, setRadiusServer] = useState('');
  const [radiusPort, setRadiusPort] = useState(1812);
  const [radiusAcctPort, setRadiusAcctPort] = useState(1813);
  const [mikrotikScript, setMikrotikScript] = useState('');
  const [provisioningUrl, setProvisioningUrl] = useState('');
  const [fetchCommand, setFetchCommand] = useState('');
  const [loading, setLoading] = useState(true);
  const [copied, setCopied] = useState('');

  const getAuthHeaders = (): HeadersInit => {
    const token = localStorage.getItem('tenant_token');
    const headers: HeadersInit = {
      'Content-Type': 'application/json',
      Accept: 'application/json',
    };
    if (token) headers.Authorization = `Bearer ${token}`;
    if (selectedSite?.id) headers['X-Site-ID'] = String(selectedSite.id);
    return headers;
  };

  const load = async () => {
    if (!selectedSite?.id) {
      setLoading(false);
      setNas(null);
      return;
    }

    setLoading(true);
    try {
      const response = await fetch(`${API_BASE}/nas`, { headers: getAuthHeaders() });
      const data = await response.json();
      setRadiusServer(data.radius_server || '');
      setRadiusPort(data.radius_port || 1812);
      setRadiusAcctPort(data.radius_acct_port || 1813);

      const entry = data.nas_entries?.[0];
      if (!entry) {
        setNas(null);
        return;
      }

      setNas(entry);
      const detailResponse = await fetch(`${API_BASE}/nas/${entry.id}`, { headers: getAuthHeaders() });
      const detailData = await detailResponse.json();
      setMikrotikScript(detailData.mikrotik_script || '');
      setProvisioningUrl(detailData.provisioning_url || '');
      setFetchCommand(detailData.fetch_command || '');
    } catch (error) {
      console.error('Error loading site router setup:', error);
    } finally {
      setLoading(false);
    }
  };

  useEffect(() => {
    setNas(null);
    setMikrotikScript('');
    setProvisioningUrl('');
    setFetchCommand('');
    load();
  }, [selectedSite?.id]);

  const copyToClipboard = async (text: string, key: string) => {
    if (!text) return;
    await navigator.clipboard.writeText(text);
    setCopied(key);
    setTimeout(() => setCopied(''), 1600);
  };

  const handleDownloadScript = async () => {
    if (!nas) return;
    const response = await fetch(`${API_BASE}/nas/${nas.id}/mikrotik-script`, { headers: getAuthHeaders() });
    if (!response.ok) {
      alert('Failed to download script');
      return;
    }
    const blob = await response.blob();
    const url = window.URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = `onlifi-${selectedSite?.slug || 'site'}-router.rsc`;
    document.body.appendChild(a);
    a.click();
    window.URL.revokeObjectURL(url);
    document.body.removeChild(a);
  };

  if (loading) {
    return (
      <div className="flex items-center justify-center h-64">
        <RefreshCw className="w-6 h-6 text-primary animate-spin" />
      </div>
    );
  }

  return (
    <div className="p-4 sm:p-6 lg:p-8">
      <div className="mb-6 sm:mb-8">
        <h1 className="text-2xl sm:text-3xl text-foreground mb-2">RADIUS Setup</h1>
        <p className="text-sm text-muted-foreground">The selected site has one router. Its RADIUS identity and setup script are prepared automatically.</p>
      </div>

      <div className="bg-gradient-to-br from-primary to-primary/80 rounded-lg p-6 mb-6 text-primary-foreground">
        <div className="flex items-center gap-2 mb-4">
          <Server className="w-5 h-5" />
          <h2 className="text-lg font-semibold">RADIUS Server Information</h2>
        </div>
        <div className="grid grid-cols-1 sm:grid-cols-3 gap-4">
          <div>
            <p className="text-xs opacity-75 mb-1">Server Address</p>
            <p className="font-mono font-semibold">{radiusServer}</p>
          </div>
          <div>
            <p className="text-xs opacity-75 mb-1">Auth Port</p>
            <p className="font-mono font-semibold">{radiusPort}</p>
          </div>
          <div>
            <p className="text-xs opacity-75 mb-1">Accounting Port</p>
            <p className="font-mono font-semibold">{radiusAcctPort}</p>
          </div>
        </div>
      </div>

      {!nas ? (
        <div className="bg-card border border-border rounded-lg p-10 text-center text-muted-foreground">
          Select a site to prepare its router configuration.
        </div>
      ) : (
        <div className="space-y-6">
          <div className="bg-card border border-border rounded-lg p-6">
            <div className="flex flex-col lg:flex-row lg:items-start lg:justify-between gap-4">
              <div>
                <h2 className="text-lg font-semibold text-card-foreground">{selectedSite?.name} Router</h2>
                <p className="text-sm text-muted-foreground mt-1">Router identity is based on the active site and shared by hotspot, RADIUS, and provisioning.</p>
                <div className="flex items-center gap-2 mt-3">
                  <span className="text-xs text-muted-foreground">Router ID:</span>
                  <code className="text-xs bg-background px-2 py-1 rounded font-mono break-all">{nas.router_identifier}</code>
                  <button onClick={() => copyToClipboard(nas.router_identifier, 'id')} className="p-1 hover:bg-background rounded transition-colors" title="Copy Router ID">
                    {copied === 'id' ? <CheckCircle className="w-3 h-3 text-emerald-500" /> : <Copy className="w-3 h-3 text-muted-foreground" />}
                  </button>
                </div>
              </div>
              <button onClick={handleDownloadScript} className="inline-flex items-center justify-center gap-2 px-4 py-2 bg-primary text-primary-foreground rounded-lg hover:bg-primary/90 transition-colors">
                <Download className="w-4 h-4" />
                Download Script
              </button>
            </div>
          </div>

          <div className="bg-card border border-border rounded-lg p-6">
            <div className="flex items-start gap-2 mb-4">
              <AlertCircle className="w-5 h-5 text-yellow-500 flex-shrink-0 mt-0.5" />
              <p className="text-sm text-muted-foreground">Run the one-line command on the MikroTik terminal. It downloads and immediately imports the setup script, then configures LAN, DHCP, NAT, hotspot, RADIUS, SSTP VPN, captive files, and telemetry for this site.</p>
            </div>

            <label className="block text-sm font-medium text-card-foreground mb-2">One-line RouterOS command</label>
            <div className="relative">
              <pre className="bg-background p-4 pr-20 rounded-lg overflow-x-auto text-xs font-mono border border-border">{fetchCommand}</pre>
              <button onClick={() => copyToClipboard(fetchCommand, 'command')} className="absolute top-2 right-2 px-3 py-1 bg-primary text-primary-foreground rounded text-xs flex items-center gap-1 hover:bg-primary/90">
                <Copy className="w-3 h-3" />
                {copied === 'command' ? 'Copied' : 'Copy'}
              </button>
            </div>
            {provisioningUrl && <p className="text-xs text-muted-foreground mt-2 break-all">Script URL: {provisioningUrl}</p>}
          </div>

          <div className="bg-card border border-border rounded-lg p-6">
            <h3 className="text-lg font-semibold text-card-foreground mb-3">Full Script Preview</h3>
            <pre className="bg-background p-4 rounded-lg overflow-x-auto text-xs font-mono border border-border max-h-[560px] whitespace-pre-wrap">{mikrotikScript}</pre>
          </div>
        </div>
      )}
    </div>
  );
}
