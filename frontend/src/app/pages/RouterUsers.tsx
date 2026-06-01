import { useEffect, useState } from 'react';
import type { FormEvent } from 'react';
import { CheckCircle2, Loader2, Plus, RefreshCw, UserCog, XCircle } from 'lucide-react';
import { createRouterSystemUser, getRouterSystemUsers, updateRouterSystemUserStatus } from '../utils/api';
import { useSite } from '../context/SiteContext';

interface RouterUser {
  id: string;
  name: string;
  group?: string;
  last_logged_in?: string;
  comment?: string;
  disabled?: boolean;
}

const emptyForm = {
  name: '',
  password: '',
  group: 'full',
  comment: '',
};

export function RouterUsers() {
  const { selectedSite } = useSite();
  const [users, setUsers] = useState<RouterUser[]>([]);
  const [form, setForm] = useState({ ...emptyForm });
  const [loading, setLoading] = useState(true);
  const [saving, setSaving] = useState(false);
  const [updatingId, setUpdatingId] = useState('');
  const [message, setMessage] = useState('');
  const [error, setError] = useState('');

  const load = async () => {
    setLoading(true);
    setError('');
    try {
      const data = await getRouterSystemUsers();
      setUsers(data.users || data.data || []);
      if (data.message) setMessage(data.message);
    } catch (err: any) {
      setError(err.message || 'Failed to load RouterOS users.');
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
      await createRouterSystemUser({
        name: form.name.trim(),
        password: form.password,
        group: form.group.trim() || 'read',
        comment: form.comment.trim() || null,
      });
      setForm({ ...emptyForm });
      setMessage('RouterOS user added to the active site router.');
      await load();
    } catch (err: any) {
      setError(err.message || 'Failed to add RouterOS user.');
    } finally {
      setSaving(false);
    }
  };

  const toggleStatus = async (user: RouterUser) => {
    if (!user.id) return;

    setUpdatingId(user.id);
    setMessage('');
    setError('');
    try {
      await updateRouterSystemUserStatus({
        id: user.id,
        disabled: !user.disabled,
      });
      setMessage(`RouterOS user ${user.disabled ? 'enabled' : 'disabled'}.`);
      await load();
    } catch (err: any) {
      setError(err.message || 'Failed to update RouterOS user.');
    } finally {
      setUpdatingId('');
    }
  };

  if (loading && users.length === 0) {
    return <div className="min-h-screen grid place-items-center"><Loader2 className="w-8 h-8 animate-spin text-primary" /></div>;
  }

  return (
    <div className="min-h-screen bg-background p-6 lg:p-8 space-y-6">
      <div className="flex flex-col lg:flex-row lg:items-center lg:justify-between gap-4">
        <div>
          <h1 className="text-2xl font-semibold text-foreground flex items-center gap-2">
            <UserCog className="w-7 h-7 text-primary" />
            Router Users
          </h1>
          <p className="text-muted-foreground mt-1">Manage MikroTik /system/users for {selectedSite?.name || 'the active site'}.</p>
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
          <h2 className="font-semibold text-card-foreground">Add RouterOS User</h2>
        </div>

        <div className="grid md:grid-cols-2 xl:grid-cols-4 gap-4">
          <label className="space-y-1">
            <span className="text-sm text-muted-foreground">Username *</span>
            <input required value={form.name} onChange={(e) => setForm({ ...form, name: e.target.value })} className="w-full px-3 py-2 bg-background border border-input rounded-lg" />
          </label>
          <label className="space-y-1">
            <span className="text-sm text-muted-foreground">Password *</span>
            <input required type="password" value={form.password} onChange={(e) => setForm({ ...form, password: e.target.value })} className="w-full px-3 py-2 bg-background border border-input rounded-lg" />
          </label>
          <label className="space-y-1">
            <span className="text-sm text-muted-foreground">Group</span>
            <select value={form.group} onChange={(e) => setForm({ ...form, group: e.target.value })} className="w-full px-3 py-2 bg-background border border-input rounded-lg">
              <option value="full">full</option>
              <option value="write">write</option>
              <option value="read">read</option>
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
            Add user
          </button>
        </div>
      </form>

      <div className="bg-card border border-border rounded-lg overflow-hidden">
        <div className="p-5 border-b border-border">
          <h2 className="font-semibold text-card-foreground">RouterOS Users</h2>
          <p className="text-sm text-muted-foreground mt-1">Users are read directly from MikroTik /system/users.</p>
        </div>
        <div className="overflow-x-auto">
          <table className="w-full text-sm">
            <thead className="text-left text-muted-foreground border-b border-border">
              <tr>
                <th className="px-5 py-3 font-medium">Username</th>
                <th className="px-5 py-3 font-medium">Group</th>
                <th className="px-5 py-3 font-medium">Last Login</th>
                <th className="px-5 py-3 font-medium">Status</th>
                <th className="px-5 py-3 font-medium">Comment</th>
                <th className="px-5 py-3 font-medium text-right">Action</th>
              </tr>
            </thead>
            <tbody>
              {users.length === 0 ? (
                <tr><td colSpan={6} className="px-5 py-8 text-center text-muted-foreground">No RouterOS users found on this router.</td></tr>
              ) : users.map((user) => (
                <tr key={user.id || user.name} className="border-b border-border/70 last:border-0">
                  <td className="px-5 py-3 font-medium text-card-foreground">{user.name || '-'}</td>
                  <td className="px-5 py-3">{user.group || '-'}</td>
                  <td className="px-5 py-3 text-muted-foreground">{user.last_logged_in || '-'}</td>
                  <td className="px-5 py-3">
                    <span className={`inline-flex items-center gap-1 px-2 py-1 rounded-md ${user.disabled ? 'bg-destructive/10 text-destructive' : 'bg-emerald-500/10 text-emerald-600'}`}>
                      {user.disabled ? <XCircle className="w-3.5 h-3.5" /> : <CheckCircle2 className="w-3.5 h-3.5" />}
                      {user.disabled ? 'Disabled' : 'Enabled'}
                    </span>
                  </td>
                  <td className="px-5 py-3 text-muted-foreground">{user.comment || '-'}</td>
                  <td className="px-5 py-3 text-right">
                    <button
                      onClick={() => toggleStatus(user)}
                      disabled={updatingId === user.id}
                      className="inline-flex items-center gap-2 px-3 py-1.5 rounded-lg border border-border hover:bg-muted disabled:opacity-50"
                    >
                      {updatingId === user.id && <Loader2 className="w-3.5 h-3.5 animate-spin" />}
                      {user.disabled ? 'Enable' : 'Disable'}
                    </button>
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
