import { useEffect, useState } from 'react';
import { Copy, Check, Loader2, Network, ShieldCheck } from 'lucide-react';
import { getRemoteAccess } from '../utils/api';

export function RemoteAccess() {
  const [data, setData] = useState<any>(null);
  const [loading, setLoading] = useState(true);
  const [copied, setCopied] = useState<string | null>(null);

  useEffect(() => {
    const load = async () => {
      setLoading(true);
      try {
        setData(await getRemoteAccess());
      } finally {
        setLoading(false);
      }
    };

    load();
  }, []);

  const copy = async (value: string, key: string) => {
    if (!value) return;
    await navigator.clipboard.writeText(value);
    setCopied(key);
    window.setTimeout(() => setCopied(null), 1600);
  };

  if (loading) {
    return <div className="min-h-screen grid place-items-center"><Loader2 className="w-8 h-8 animate-spin text-primary" /></div>;
  }

  const sites = data?.sites || [];

  return (
    <div className="min-h-screen bg-background p-6 lg:p-8 space-y-6">
      <div>
        <h1 className="text-2xl font-semibold text-foreground flex items-center gap-2">
          <Network className="w-7 h-7 text-primary" />
          Remote Access
        </h1>
        <p className="text-muted-foreground mt-1">SSTP VPN addressing and router API access details for each site.</p>
      </div>

      <div className="bg-card border border-border rounded-lg p-5">
        <p className="text-sm text-muted-foreground">Remote access host</p>
        <p className="text-2xl font-semibold mt-1 font-mono">{data?.vpn_host || 'vpn.onlifi.net'}</p>
      </div>

      <div className="bg-card border border-border rounded-lg overflow-hidden">
        <div className="p-5 border-b border-border">
          <h2 className="font-semibold">Site VPN Assignments</h2>
        </div>
        <div className="overflow-x-auto">
          <table className="w-full text-sm">
            <thead className="text-left text-muted-foreground border-b border-border">
              <tr>
                <th className="px-5 py-3 font-medium">Site</th>
                <th className="px-5 py-3 font-medium">Endpoint</th>
                <th className="px-5 py-3 font-medium">Status</th>
              </tr>
            </thead>
            <tbody>
              {sites.length === 0 ? (
                <tr><td colSpan={3} className="px-5 py-8 text-center text-muted-foreground">No sites configured yet.</td></tr>
              ) : sites.map((site: any) => (
                <tr key={site.id} className="border-b border-border/60 last:border-0">
                  <td className="px-5 py-3">
                    <p className="font-medium">{site.name}</p>
                    <p className="text-xs text-muted-foreground">{site.slug}</p>
                  </td>
                  <td className="px-5 py-3">
                    <button onClick={() => copy(site.vpn_public_endpoint, `endpoint-${site.id}`)} className="inline-flex items-center gap-2 font-mono text-primary hover:underline disabled:text-muted-foreground" disabled={!site.vpn_public_endpoint}>
                      {site.vpn_public_endpoint || 'Pending assignment'}
                      {site.vpn_public_endpoint && (copied === `endpoint-${site.id}` ? <Check className="w-3 h-3" /> : <Copy className="w-3 h-3" />)}
                    </button>
                  </td>
                  <td className="px-5 py-3">
                    <span className="inline-flex items-center gap-1 px-2 py-1 rounded-md bg-muted capitalize">
                      <ShieldCheck className="w-3 h-3" />
                      {site.vpn_status || 'pending'}
                    </span>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      </div>
    </div>
  );
}
