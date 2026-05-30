import { useEffect, useState } from 'react';
import type { FormEvent } from 'react';
import { CheckCircle2, Loader2, Network, Plus, RefreshCw, Trash2, XCircle } from 'lucide-react';
import { createPppoeClient, deletePppoeClient, disablePppoeClient, enablePppoeClient, getPppoeClients } from '../utils/api';
import { useSite } from '../context/SiteContext';

interface PppoeClient {
  id: number;
  name: string;
  username: string;
  password?: string | null;
  profile?: string | null;
  service?: string | null;
  remote_address?: string | null;
  phone?: string | null;
  notes?: string | null;
  is_active: boolean | number;
  last_seen_at?: string | null;
  created_at?: string | null;
}

const emptyForm = {
  name: '',
  username: '',
  password: '',
  profile: 'default',
  service: 'pppoe',
  remote_address: '',
  phone: '',
  notes: '',
  is_active: true,
};

export function PppoeClients() {
  const { selectedSite } = useSite();
  const [clients, setClients] = useState<PppoeClient[]>([]);
  const [form, setForm] = useState({ ...emptyForm });
  const [loading, setLoading] = useState(true);
  const [saving, setSaving] = useState(false);
  const [message, setMessage] = useState('');
  const [error, setError] = useState('');

  const load = async () => {
    setLoading(true);
    setError('');
    try {
      const data = await getPppoeClients();
      setClients(data.clients || data.data || []);
      if (data.message) setMessage(data.message);
    } catch (err: any) {
      setError(err.message || 'Failed to load PPPoE clients.');
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
        phone: form.phone || null,
        notes: form.notes || null,
      });
      setForm({ ...emptyForm });
      setMessage('PPPoE client created.');
      await load();
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
      await load();
    } catch (err: any) {
      setError(err.message || 'Failed to update PPPoE client.');
    }
  };

  const removeClient = async (client: PppoeClient) => {
    if (!confirm(`Delete PPPoE client "${client.username}"?`)) return;
    setError('');
    try {
      await deletePppoeClient(client.id);
      await load();
    } catch (err: any) {
      setError(err.message || 'Failed to delete PPPoE client.');
    }
  };

  if (loading && clients.length === 0) {
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
          <p className="text-muted-foreground mt-1">Manage PPPoE clients for {selectedSite?.name || 'the active site'}.</p>
        </div>
        <button onClick={load} className="inline-flex items-center gap-2 px-4 py-2 rounded-lg border border-border hover:bg-muted">
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
            <input value={form.profile} onChange={(e) => setForm({ ...form, profile: e.target.value })} className="w-full px-3 py-2 bg-background border border-input rounded-lg" />
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
            <span className="text-sm text-muted-foreground">Phone</span>
            <input value={form.phone} onChange={(e) => setForm({ ...form, phone: e.target.value })} className="w-full px-3 py-2 bg-background border border-input rounded-lg" />
          </label>
          <label className="space-y-1">
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
        <div className="p-5 border-b border-border">
          <h2 className="font-semibold text-card-foreground">PPPoE Clients</h2>
          <p className="text-sm text-muted-foreground mt-1">Activate or disable access without deleting the client record.</p>
        </div>
        <div className="overflow-x-auto">
          <table className="w-full text-sm">
            <thead className="text-left text-muted-foreground border-b border-border">
              <tr>
                <th className="px-5 py-3 font-medium">Client</th>
                <th className="px-5 py-3 font-medium">Profile</th>
                <th className="px-5 py-3 font-medium">Remote Address</th>
                <th className="px-5 py-3 font-medium">Last Seen</th>
                <th className="px-5 py-3 font-medium">Status</th>
                <th className="px-5 py-3 font-medium text-right">Actions</th>
              </tr>
            </thead>
            <tbody>
              {clients.length === 0 ? (
                <tr><td colSpan={6} className="px-5 py-8 text-center text-muted-foreground">No PPPoE clients for this site yet.</td></tr>
              ) : clients.map((client) => (
                <tr key={client.id} className="border-b border-border/70 last:border-0">
                  <td className="px-5 py-3">
                    <p className="font-medium text-card-foreground">{client.name}</p>
                    <p className="text-xs text-muted-foreground font-mono">{client.username}</p>
                    {client.phone && <p className="text-xs text-muted-foreground">{client.phone}</p>}
                  </td>
                  <td className="px-5 py-3">{client.profile || 'default'}</td>
                  <td className="px-5 py-3 font-mono">{client.remote_address || '-'}</td>
                  <td className="px-5 py-3 whitespace-nowrap">{client.last_seen_at ? new Date(client.last_seen_at).toLocaleString() : 'Never'}</td>
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
                      <button onClick={() => removeClient(client)} className="p-2 rounded-lg text-destructive hover:bg-destructive/10" title="Delete client">
                        <Trash2 className="w-4 h-4" />
                      </button>
                    </div>
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
