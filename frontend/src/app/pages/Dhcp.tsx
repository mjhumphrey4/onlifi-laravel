import { useEffect, useState } from 'react';
import { Database, Loader2, Network, RefreshCw, Router, Wifi } from 'lucide-react';
import { useSite } from '../context/SiteContext';
import { getRouterDhcpLeases, getRouterDhcpPools } from '../utils/api';

interface DhcpLease {
  id: string;
  mac_address: string;
  ip_address: string;
  hostname: string;
  last_seen: string;
  status: string;
  server: string;
  dynamic: boolean;
  comment: string;
  device_type: string;
}

interface DhcpPool {
  id: string;
  name: string;
  ranges: string;
  next_pool: string;
  comment: string;
}

type Tab = 'leases' | 'pools';

export function Dhcp() {
  const { selectedSite } = useSite();
  const [activeTab, setActiveTab] = useState<Tab>('leases');
  const [leases, setLeases] = useState<DhcpLease[]>([]);
  const [pools, setPools] = useState<DhcpPool[]>([]);
  const [loading, setLoading] = useState(true);
  const [refreshing, setRefreshing] = useState(false);
  const [message, setMessage] = useState('');
  const [error, setError] = useState('');
  const [lastSyncedAt, setLastSyncedAt] = useState<string | null>(null);

  const load = async (refresh = false) => {
    if (refresh) setRefreshing(true);
    else setLoading(true);
    setError('');
    setMessage('');

    try {
      const [leasesData, poolsData] = await Promise.all([
        getRouterDhcpLeases(refresh),
        getRouterDhcpPools(refresh),
      ]);

      setLeases(leasesData.leases || leasesData.data || []);
      setPools(poolsData.pools || []);
      setLastSyncedAt(leasesData.last_synced_at || poolsData.last_synced_at || null);

      const messages = [leasesData.message, poolsData.message].filter(Boolean);
      if (messages.length > 0) {
        setMessage(messages[0]);
      } else if (leasesData.cached || poolsData.cached) {
        setMessage('Showing last known DHCP data from the selected site router.');
      }
    } catch (err: any) {
      setError(err.message || 'Failed to load DHCP information.');
    } finally {
      setLoading(false);
      setRefreshing(false);
    }
  };

  useEffect(() => {
    load(true);
  }, [selectedSite?.id]);

  if (loading && leases.length === 0 && pools.length === 0) {
    return <div className="min-h-screen grid place-items-center"><Loader2 className="w-8 h-8 animate-spin text-primary" /></div>;
  }

  return (
    <div className="min-h-screen bg-background p-6 lg:p-8 space-y-6">
      <div className="flex flex-col lg:flex-row lg:items-center lg:justify-between gap-4">
        <div>
          <h1 className="text-2xl font-semibold text-foreground flex items-center gap-2">
            <Network className="w-7 h-7 text-primary" />
            DHCP
          </h1>
          <p className="text-muted-foreground mt-1">DHCP leases and pools for {selectedSite?.name || 'the active site'}.</p>
        </div>
        <button
          onClick={() => load(true)}
          disabled={refreshing}
          className="inline-flex items-center gap-2 px-4 py-2 rounded-lg border border-border hover:bg-muted disabled:opacity-50"
        >
          <RefreshCw className={`w-4 h-4 ${refreshing ? 'animate-spin' : ''}`} />
          {refreshing ? 'Refreshing...' : 'Refresh'}
        </button>
      </div>

      {message && <div className="rounded-lg border border-border bg-card p-3 text-sm text-card-foreground">{message}</div>}
      {error && <div className="rounded-lg border border-destructive/30 bg-destructive/10 p-3 text-sm text-destructive">{error}</div>}

      <div className="flex flex-wrap items-center gap-2">
        <TabButton active={activeTab === 'leases'} icon={Wifi} label={`Leases (${leases.length})`} onClick={() => setActiveTab('leases')} />
        <TabButton active={activeTab === 'pools'} icon={Database} label={`Pools (${pools.length})`} onClick={() => setActiveTab('pools')} />
        {lastSyncedAt && <span className="text-xs text-muted-foreground ml-auto">Synced {new Date(lastSyncedAt).toLocaleString()}</span>}
      </div>

      {activeTab === 'leases' ? (
        <div className="bg-card border border-border rounded-lg overflow-hidden">
          <div className="p-5 border-b border-border">
            <h2 className="font-semibold text-card-foreground">Current DHCP Leases</h2>
            <p className="text-sm text-muted-foreground mt-1">Bound leases currently present in the MikroTik DHCP pool.</p>
          </div>
          <div className="overflow-x-auto">
            <table className="w-full text-sm">
              <thead className="text-left text-muted-foreground border-b border-border">
                <tr>
                  <th className="px-5 py-3 font-medium">IP Address</th>
                  <th className="px-5 py-3 font-medium">MAC Address</th>
                  <th className="px-5 py-3 font-medium">Hostname</th>
                  <th className="px-5 py-3 font-medium">Server</th>
                  <th className="px-5 py-3 font-medium">Type</th>
                  <th className="px-5 py-3 font-medium">Last Seen</th>
                </tr>
              </thead>
              <tbody>
                {leases.length === 0 ? (
                  <tr><td colSpan={6} className="px-5 py-8 text-center text-muted-foreground">No bound DHCP leases found for this site router.</td></tr>
                ) : leases.map((lease) => (
                  <tr key={lease.id || `${lease.mac_address}-${lease.ip_address}`} className="border-b border-border/70 last:border-0">
                    <td className="px-5 py-3 font-medium text-card-foreground">{lease.ip_address || '-'}</td>
                    <td className="px-5 py-3 font-mono text-xs">{lease.mac_address || '-'}</td>
                    <td className="px-5 py-3">{lease.hostname || ''}</td>
                    <td className="px-5 py-3 text-muted-foreground">{lease.server || '-'}</td>
                    <td className="px-5 py-3">
                      <span className="inline-flex items-center gap-1 rounded-md bg-emerald-500/10 px-2 py-1 text-xs text-emerald-600">
                        <Router className="w-3 h-3" />
                        {lease.dynamic ? 'Dynamic' : 'Static'}
                      </span>
                    </td>
                    <td className="px-5 py-3 text-muted-foreground">{lease.last_seen || '-'}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </div>
      ) : (
        <div className="bg-card border border-border rounded-lg overflow-hidden">
          <div className="p-5 border-b border-border">
            <h2 className="font-semibold text-card-foreground">DHCP Pools</h2>
            <p className="text-sm text-muted-foreground mt-1">Available MikroTik IP pools and their address ranges.</p>
          </div>
          <div className="overflow-x-auto">
            <table className="w-full text-sm">
              <thead className="text-left text-muted-foreground border-b border-border">
                <tr>
                  <th className="px-5 py-3 font-medium">Pool Name</th>
                  <th className="px-5 py-3 font-medium">Ranges</th>
                  <th className="px-5 py-3 font-medium">Next Pool</th>
                  <th className="px-5 py-3 font-medium">Comment</th>
                </tr>
              </thead>
              <tbody>
                {pools.length === 0 ? (
                  <tr><td colSpan={4} className="px-5 py-8 text-center text-muted-foreground">No DHCP pools found for this site router.</td></tr>
                ) : pools.map((pool) => (
                  <tr key={pool.id || pool.name} className="border-b border-border/70 last:border-0">
                    <td className="px-5 py-3 font-medium text-card-foreground">{pool.name || '-'}</td>
                    <td className="px-5 py-3 font-mono text-xs">{pool.ranges || '-'}</td>
                    <td className="px-5 py-3 text-muted-foreground">{pool.next_pool || '-'}</td>
                    <td className="px-5 py-3 text-muted-foreground">{pool.comment || '-'}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </div>
      )}
    </div>
  );
}

function TabButton({ active, icon: Icon, label, onClick }: { active: boolean; icon: any; label: string; onClick: () => void }) {
  return (
    <button
      onClick={onClick}
      className={`inline-flex items-center gap-2 rounded-lg border px-4 py-2 text-sm transition-colors ${
        active ? 'border-primary bg-primary text-primary-foreground' : 'border-border bg-card text-card-foreground hover:bg-muted'
      }`}
    >
      <Icon className="w-4 h-4" />
      {label}
    </button>
  );
}
