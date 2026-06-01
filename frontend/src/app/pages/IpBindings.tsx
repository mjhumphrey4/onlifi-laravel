import { useEffect, useState } from 'react';
import type { FormEvent } from 'react';
import { Loader2, Network, Plus, RefreshCw, ShieldCheck } from 'lucide-react';
import { createRouterIpBinding, getRouterIpBindings } from '../utils/api';
import { useSite } from '../context/SiteContext';

interface IpBinding {
  id: string;
  mac_address: string;
  address?: string;
  to_address?: string;
  server?: string;
  type: 'regular' | 'bypassed' | 'blocked';
  comment?: string;
  disabled?: boolean;
}

const emptyForm = {
  mac_address: '',
  address: '',
  to_address: '',
  server: 'all',
  type: 'bypassed',
  comment: '',
};

export function IpBindings() {
  const { selectedSite } = useSite();
  const [bindings, setBindings] = useState<IpBinding[]>([]);
  const [form, setForm] = useState({ ...emptyForm });
  const [loading, setLoading] = useState(true);
  const [saving, setSaving] = useState(false);
  const [message, setMessage] = useState('');
  const [error, setError] = useState('');

  const load = async () => {
    setLoading(true);
    setError('');
    try {
      const data = await getRouterIpBindings();
      setBindings(data.bindings || data.data || []);
      if (data.message) setMessage(data.message);
    } catch (err: any) {
      setError(err.message || 'Failed to load IP bindings.');
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
      await createRouterIpBinding({
        ...form,
        address: form.address || null,
        to_address: form.to_address || null,
        server: form.server || 'all',
        comment: form.comment || null,
      });
      setForm({ ...emptyForm });
      setMessage('IP binding added to the router.');
      await load();
    } catch (err: any) {
      setError(err.message || 'Failed to add IP binding.');
    } finally {
      setSaving(false);
    }
  };

  if (loading && bindings.length === 0) {
    return <div className="min-h-screen grid place-items-center"><Loader2 className="w-8 h-8 animate-spin text-primary" /></div>;
  }

  return (
    <div className="min-h-screen bg-background p-6 lg:p-8 space-y-6">
      <div className="flex flex-col lg:flex-row lg:items-center lg:justify-between gap-4">
        <div>
          <h1 className="text-2xl font-semibold text-foreground flex items-center gap-2">
            <ShieldCheck className="w-7 h-7 text-primary" />
            IP Bindings
          </h1>
          <p className="text-muted-foreground mt-1">Manage HotSpot IP bindings for {selectedSite?.name || 'the active site'}.</p>
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
          <h2 className="font-semibold text-card-foreground">Add IP Binding</h2>
        </div>

        <div className="grid md:grid-cols-2 xl:grid-cols-3 gap-4">
          <label className="space-y-1">
            <span className="text-sm text-muted-foreground">MAC address *</span>
            <input required value={form.mac_address} onChange={(e) => setForm({ ...form, mac_address: e.target.value })} placeholder="AA:BB:CC:DD:EE:FF" className="w-full px-3 py-2 bg-background border border-input rounded-lg" />
          </label>
          <label className="space-y-1">
            <span className="text-sm text-muted-foreground">Source IP</span>
            <input value={form.address} onChange={(e) => setForm({ ...form, address: e.target.value })} placeholder="Optional" className="w-full px-3 py-2 bg-background border border-input rounded-lg" />
          </label>
          <label className="space-y-1">
            <span className="text-sm text-muted-foreground">To address</span>
            <input value={form.to_address} onChange={(e) => setForm({ ...form, to_address: e.target.value })} placeholder="Optional" className="w-full px-3 py-2 bg-background border border-input rounded-lg" />
          </label>
          <label className="space-y-1">
            <span className="text-sm text-muted-foreground">Server</span>
            <input value={form.server} onChange={(e) => setForm({ ...form, server: e.target.value })} className="w-full px-3 py-2 bg-background border border-input rounded-lg" />
          </label>
          <label className="space-y-1">
            <span className="text-sm text-muted-foreground">Type</span>
            <select value={form.type} onChange={(e) => setForm({ ...form, type: e.target.value })} className="w-full px-3 py-2 bg-background border border-input rounded-lg">
              <option value="bypassed">Bypassed</option>
              <option value="regular">Regular</option>
              <option value="blocked">Blocked</option>
            </select>
          </label>
          <label className="space-y-1">
            <span className="text-sm text-muted-foreground">Comment</span>
            <input value={form.comment} onChange={(e) => setForm({ ...form, comment: e.target.value })} placeholder="Optional note" className="w-full px-3 py-2 bg-background border border-input rounded-lg" />
          </label>
        </div>

        <div className="flex justify-end">
          <button disabled={saving || !selectedSite?.id} className="inline-flex items-center gap-2 px-4 py-2 rounded-lg bg-primary text-primary-foreground hover:bg-primary/90 disabled:opacity-50">
            {saving ? <Loader2 className="w-4 h-4 animate-spin" /> : <Plus className="w-4 h-4" />}
            Add binding
          </button>
        </div>
      </form>

      <div className="bg-card border border-border rounded-lg overflow-hidden">
        <div className="p-5 border-b border-border">
          <h2 className="font-semibold text-card-foreground">Router IP Bindings</h2>
          <p className="text-sm text-muted-foreground mt-1">Entries are read directly from MikroTik HotSpot IP bindings.</p>
        </div>
        <div className="overflow-x-auto">
          <table className="w-full text-sm">
            <thead className="text-left text-muted-foreground border-b border-border">
              <tr>
                <th className="px-5 py-3 font-medium">MAC Address</th>
                <th className="px-5 py-3 font-medium">Address</th>
                <th className="px-5 py-3 font-medium">Server</th>
                <th className="px-5 py-3 font-medium">Type</th>
                <th className="px-5 py-3 font-medium">Status</th>
                <th className="px-5 py-3 font-medium">Comment</th>
              </tr>
            </thead>
            <tbody>
              {bindings.length === 0 ? (
                <tr><td colSpan={6} className="px-5 py-8 text-center text-muted-foreground">No IP bindings found on this router.</td></tr>
              ) : bindings.map((binding) => (
                <tr key={binding.id || `${binding.mac_address}-${binding.address}`} className="border-b border-border/70 last:border-0">
                  <td className="px-5 py-3 font-mono text-card-foreground">{binding.mac_address || '-'}</td>
                  <td className="px-5 py-3 font-mono">{binding.address || binding.to_address || '-'}</td>
                  <td className="px-5 py-3">{binding.server || 'all'}</td>
                  <td className="px-5 py-3">
                    <span className="px-2 py-1 rounded-md bg-primary/10 text-primary capitalize">{binding.type || 'regular'}</span>
                  </td>
                  <td className="px-5 py-3">{binding.disabled ? 'Disabled' : 'Enabled'}</td>
                  <td className="px-5 py-3 text-muted-foreground">{binding.comment || '-'}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      </div>
    </div>
  );
}
