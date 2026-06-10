import { useEffect, useState } from 'react';
import type { FormEvent } from 'react';
import type { ReactNode } from 'react';
import { CheckCircle2, Loader2, Network, Plus, RefreshCw, Trash2, XCircle, ZapOff } from 'lucide-react';
import {
  createPppoeClient,
  deactivatePppoeClient,
  deletePppoeClient,
  disablePppoeClient,
  enablePppoeClient,
  getPppoeActiveSessions,
  getPppoeClients,
  getPppoePools,
  getPppoeProfiles,
} from '../utils/api';
import { useSite } from '../context/SiteContext';

interface PppoeClient {
  id: number;
  name: string;
  username: string;
  password?: string | null;
  profile?: string | null;
  service?: string | null;
  remote_address?: string | null;
  expires_at?: string | null;
  phone?: string | null;
  notes?: string | null;
  is_active: boolean | number;
  last_seen_at?: string | null;
}

interface PppoeSession {
  id: string;
  username?: string;
  name?: string;
  service?: string;
  caller_id?: string;
  address?: string;
  uptime?: string;
}

interface PppoeProfile {
  id: string;
  name: string;
  local_address?: string;
  remote_address?: string;
  rate_limit?: string;
  only_one?: string;
  comment?: string;
}

interface IpPool {
  id: string;
  name: string;
  ranges?: string;
  next_pool?: string;
  comment?: string;
}

const emptyForm = {
  name: '',
  username: '',
  password: '',
  profile: 'default',
  service: 'pppoe',
  remote_address: '',
  expires_at: '',
  phone: '',
  notes: '',
  is_active: true,
};

type TabKey = 'active' | 'clients' | 'profiles' | 'pools';

export function PppoeClients() {
  const { selectedSite } = useSite();
  const [tab, setTab] = useState<TabKey>('active');
  const [clients, setClients] = useState<PppoeClient[]>([]);
  const [sessions, setSessions] = useState<PppoeSession[]>([]);
  const [profiles, setProfiles] = useState<PppoeProfile[]>([]);
  const [pools, setPools] = useState<IpPool[]>([]);
  const [form, setForm] = useState({ ...emptyForm });
  const [loading, setLoading] = useState(true);
  const [saving, setSaving] = useState(false);
  const [message, setMessage] = useState('');
  const [error, setError] = useState('');

  const load = async (refresh = false) => {
    setLoading(true);
    setError('');
    try {
      const [clientsData, activeData, profilesData, poolsData] = await Promise.all([
        getPppoeClients(refresh),
        getPppoeActiveSessions(),
        getPppoeProfiles(),
        getPppoePools(),
      ]);

      const nextClients = clientsData.clients || clientsData.data || [];
      const nextProfiles = profilesData.profiles || [];
      setClients(nextClients);
      setSessions(activeData.sessions || []);
      setProfiles(nextProfiles);
      setPools(poolsData.pools || []);

      if (!form.profile && nextProfiles[0]?.name) {
        setForm((current) => ({ ...current, profile: nextProfiles[0].name }));
      }
      if (clientsData.message) setMessage(clientsData.message);
    } catch (err: any) {
      setError(err.message || 'Failed to load PPPoE data.');
    } finally {
      setLoading(false);
    }
  };

  useEffect(() => {
    load();
  }, [selectedSite?.id]);

  const submit = async (event: FormEvent) => {
    event.preventDefault();
    setSaving(true);
    setMessage('');
    setError('');
    try {
      await createPppoeClient({
        ...form,
        profile: form.profile || null,
        service: form.service || null,
        remote_address: form.remote_address || null,
        expires_at: form.expires_at || null,
        phone: form.phone || null,
        notes: form.notes || null,
      });
      setForm({ ...emptyForm });
      setMessage('PPPoE client created.');
      await load(true);
      setTab('clients');
    } catch (err: any) {
      setError(err.message || 'Failed to create PPPoE client.');
    } finally {
      setSaving(false);
    }
  };

  const toggleClient = async (client: PppoeClient) => {
    setError('');
    try {
      if (Boolean(client.is_active)) {
        await disablePppoeClient(client.id);
      } else {
        await enablePppoeClient(client.id);
      }
      await load(true);
    } catch (err: any) {
      setError(err.message || 'Failed to update PPPoE client.');
    }
  };

  const deactivateClient = async (client: PppoeClient) => {
    if (!confirm(`Deactivate and disconnect "${client.username}"?`)) return;
    setError('');
    try {
      await deactivatePppoeClient(client.id);
      await load(true);
    } catch (err: any) {
      setError(err.message || 'Failed to deactivate PPPoE client.');
    }
  };

  const removeClient = async (client: PppoeClient) => {
    if (!confirm(`Delete PPPoE client "${client.username}"?`)) return;
    setError('');
    try {
      await deletePppoeClient(client.id);
      await load(true);
    } catch (err: any) {
      setError(err.message || 'Failed to delete PPPoE client.');
    }
  };

  if (loading && clients.length === 0 && sessions.length === 0) {
    return <div className="min-h-screen grid place-items-center"><Loader2 className="w-8 h-8 animate-spin text-primary" /></div>;
  }

  return (
    <div className="min-h-screen bg-background p-6 lg:p-8 space-y-6">
      <div className="flex flex-col lg:flex-row lg:items-center lg:justify-between gap-4">
        <div>
          <h1 className="text-2xl font-semibold text-foreground flex items-center gap-2">
            <Network className="w-7 h-7 text-primary" />
            PPPoE
          </h1>
          <p className="text-muted-foreground mt-1">Manage PPPoE sessions, users, profiles and pools for {selectedSite?.name || 'the active site'}.</p>
        </div>
        <button onClick={() => load(true)} className="inline-flex items-center gap-2 px-4 py-2 rounded-lg border border-border hover:bg-muted">
          <RefreshCw className={`w-4 h-4 ${loading ? 'animate-spin' : ''}`} />
          Refresh
        </button>
      </div>

      {message && <div className="rounded-lg border border-border bg-card p-3 text-sm text-card-foreground">{message}</div>}
      {error && <div className="rounded-lg border border-destructive/30 bg-destructive/10 p-3 text-sm text-destructive">{error}</div>}

      <form onSubmit={submit} className="bg-card border border-border rounded-lg p-5 space-y-4">
        <div className="flex items-center gap-2">
          <Plus className="w-5 h-5 text-primary" />
          <h2 className="font-semibold text-card-foreground">Add PPPoE Client</h2>
        </div>

        <div className="grid md:grid-cols-2 xl:grid-cols-4 gap-4">
          <label className="space-y-1">
            <span className="text-sm text-muted-foreground">Client name</span>
            <input required value={form.name} onChange={(e) => setForm({ ...form, name: e.target.value })} className="w-full px-3 py-2 bg-background border border-input rounded-lg" />
          </label>
          <label className="space-y-1">
            <span className="text-sm text-muted-foreground">Username</span>
            <input required value={form.username} onChange={(e) => setForm({ ...form, username: e.target.value })} className="w-full px-3 py-2 bg-background border border-input rounded-lg" />
          </label>
          <label className="space-y-1">
            <span className="text-sm text-muted-foreground">Password</span>
            <input value={form.password} onChange={(e) => setForm({ ...form, password: e.target.value })} className="w-full px-3 py-2 bg-background border border-input rounded-lg" />
          </label>
          <label className="space-y-1">
            <span className="text-sm text-muted-foreground">Profile</span>
            <select value={form.profile} onChange={(e) => setForm({ ...form, profile: e.target.value })} className="w-full px-3 py-2 bg-background border border-input rounded-lg">
              <option value="default">default</option>
              {profiles.map((profile) => <option key={profile.id || profile.name} value={profile.name}>{profile.name}</option>)}
            </select>
          </label>
          <label className="space-y-1">
            <span className="text-sm text-muted-foreground">Service</span>
            <input value={form.service} onChange={(e) => setForm({ ...form, service: e.target.value })} className="w-full px-3 py-2 bg-background border border-input rounded-lg" />
          </label>
          <label className="space-y-1">
            <span className="text-sm text-muted-foreground">Remote address</span>
            <input value={form.remote_address} onChange={(e) => setForm({ ...form, remote_address: e.target.value })} className="w-full px-3 py-2 bg-background border border-input rounded-lg" />
          </label>
          <label className="space-y-1">
            <span className="text-sm text-muted-foreground">Expiry date</span>
            <input type="datetime-local" value={form.expires_at} onChange={(e) => setForm({ ...form, expires_at: e.target.value })} className="w-full px-3 py-2 bg-background border border-input rounded-lg" />
          </label>
          <label className="space-y-1">
            <span className="text-sm text-muted-foreground">Phone</span>
            <input value={form.phone} onChange={(e) => setForm({ ...form, phone: e.target.value })} className="w-full px-3 py-2 bg-background border border-input rounded-lg" />
          </label>
          <label className="space-y-1 xl:col-span-2">
            <span className="text-sm text-muted-foreground">Notes</span>
            <input value={form.notes} onChange={(e) => setForm({ ...form, notes: e.target.value })} className="w-full px-3 py-2 bg-background border border-input rounded-lg" />
          </label>
        </div>

        <div className="flex items-center justify-between gap-4">
          <label className="inline-flex items-center gap-2 text-sm">
            <input type="checkbox" checked={form.is_active} onChange={(e) => setForm({ ...form, is_active: e.target.checked })} className="rounded border-border" />
            Active immediately
          </label>
          <button disabled={saving || !selectedSite?.id} className="inline-flex items-center gap-2 px-4 py-2 rounded-lg bg-primary text-primary-foreground hover:bg-primary/90 disabled:opacity-50">
            {saving ? <Loader2 className="w-4 h-4 animate-spin" /> : <Plus className="w-4 h-4" />}
            Add client
          </button>
        </div>
      </form>

      <div className="bg-card border border-border rounded-lg overflow-hidden">
        <div className="flex flex-wrap gap-2 p-4 border-b border-border">
          {[
            ['active', `Active Sessions (${sessions.length})`],
            ['clients', `Users (${clients.length})`],
            ['profiles', `Profiles (${profiles.length})`],
            ['pools', `Pools (${pools.length})`],
          ].map(([key, label]) => (
            <button key={key} onClick={() => setTab(key as TabKey)} className={`px-3 py-2 rounded-lg text-sm ${tab === key ? 'bg-primary text-primary-foreground' : 'border border-border hover:bg-muted'}`}>
              {label}
            </button>
          ))}
        </div>

        {tab === 'active' && (
          <DataTable empty="No active PPPoE sessions right now." columns={['User', 'Address', 'Caller ID', 'Service', 'Uptime']}>
            {sessions.map((session) => (
              <tr key={session.id || session.username} className="border-b border-border/70 last:border-0">
                <td className="px-5 py-3 font-medium text-card-foreground">{session.username || session.name || '-'}</td>
                <td className="px-5 py-3 font-mono">{session.address || '-'}</td>
                <td className="px-5 py-3 font-mono">{session.caller_id || '-'}</td>
                <td className="px-5 py-3">{session.service || '-'}</td>
                <td className="px-5 py-3">{session.uptime || '-'}</td>
              </tr>
            ))}
          </DataTable>
        )}

        {tab === 'clients' && (
          <DataTable empty="No PPPoE users for this site yet." columns={['Client', 'Profile', 'Remote Address', 'Expiry', 'Status', 'Actions']}>
            {clients.map((client) => (
              <tr key={client.id} className="border-b border-border/70 last:border-0">
                <td className="px-5 py-3">
                  <p className="font-medium text-card-foreground">{client.name}</p>
                  <p className="text-xs text-muted-foreground font-mono">{client.username}</p>
                  {client.phone && <p className="text-xs text-muted-foreground">{client.phone}</p>}
                </td>
                <td className="px-5 py-3">{client.profile || 'default'}</td>
                <td className="px-5 py-3 font-mono">{client.remote_address || '-'}</td>
                <td className="px-5 py-3 whitespace-nowrap">{client.expires_at ? new Date(client.expires_at).toLocaleString() : '-'}</td>
                <td className="px-5 py-3">
                  {Boolean(client.is_active) ? (
                    <span className="inline-flex items-center gap-1 px-2 py-1 rounded-md bg-emerald-500/10 text-emerald-600"><CheckCircle2 className="w-3 h-3" /> Active</span>
                  ) : (
                    <span className="inline-flex items-center gap-1 px-2 py-1 rounded-md bg-muted text-muted-foreground"><XCircle className="w-3 h-3" /> Disabled</span>
                  )}
                </td>
                <td className="px-5 py-3">
                  <div className="flex items-center justify-end gap-2">
                    <button onClick={() => toggleClient(client)} className="px-3 py-1.5 rounded-lg border border-border hover:bg-muted">
                      {Boolean(client.is_active) ? 'Disable' : 'Activate'}
                    </button>
                    <button onClick={() => deactivateClient(client)} className="p-2 rounded-lg text-amber-600 hover:bg-amber-500/10" title="Deactivate and disconnect">
                      <ZapOff className="w-4 h-4" />
                    </button>
                    <button onClick={() => removeClient(client)} className="p-2 rounded-lg text-destructive hover:bg-destructive/10" title="Delete client">
                      <Trash2 className="w-4 h-4" />
                    </button>
                  </div>
                </td>
              </tr>
            ))}
          </DataTable>
        )}

        {tab === 'profiles' && (
          <DataTable empty="No PPPoE profiles returned by the router." columns={['Profile', 'Local Address', 'Remote Address / Pool', 'Rate Limit', 'Only One']}>
            {profiles.map((profile) => (
              <tr key={profile.id || profile.name} className="border-b border-border/70 last:border-0">
                <td className="px-5 py-3 font-medium text-card-foreground">{profile.name}</td>
                <td className="px-5 py-3 font-mono">{profile.local_address || '-'}</td>
                <td className="px-5 py-3 font-mono">{profile.remote_address || '-'}</td>
                <td className="px-5 py-3">{profile.rate_limit || '-'}</td>
                <td className="px-5 py-3">{profile.only_one || '-'}</td>
              </tr>
            ))}
          </DataTable>
        )}

        {tab === 'pools' && (
          <DataTable empty="No IP pools returned by the router." columns={['Pool', 'Ranges', 'Next Pool', 'Comment']}>
            {pools.map((pool) => (
              <tr key={pool.id || pool.name} className="border-b border-border/70 last:border-0">
                <td className="px-5 py-3 font-medium text-card-foreground">{pool.name}</td>
                <td className="px-5 py-3 font-mono">{pool.ranges || '-'}</td>
                <td className="px-5 py-3">{pool.next_pool || '-'}</td>
                <td className="px-5 py-3">{pool.comment || '-'}</td>
              </tr>
            ))}
          </DataTable>
        )}
      </div>
    </div>
  );
}

function DataTable({ columns, empty, children }: { columns: string[]; empty: string; children: ReactNode }) {
  const hasRows = Array.isArray(children) ? children.length > 0 : Boolean(children);

  return (
    <div className="overflow-x-auto">
      <table className="w-full text-sm">
        <thead className="text-left text-muted-foreground border-b border-border">
          <tr>
            {columns.map((column) => <th key={column} className="px-5 py-3 font-medium">{column}</th>)}
          </tr>
        </thead>
        <tbody>
          {hasRows ? children : <tr><td colSpan={columns.length} className="px-5 py-8 text-center text-muted-foreground">{empty}</td></tr>}
        </tbody>
      </table>
    </div>
  );
}
