import { FormEvent, useEffect, useState } from 'react';
import { Edit2, Loader2, Plus, ShieldCheck, Trash2, UserCog } from 'lucide-react';
import { createSubUser, deleteSubUser, getSites, getSubUsers, updateSubUser } from '../utils/api';
import { useAuth } from '../context/AuthContext';

interface SiteOption {
  id: number;
  name: string;
  assigned_device_ip_range?: string | null;
}

interface SubUser {
  id: number;
  name: string;
  email: string;
  is_active: boolean;
  role: 'sub_user' | 'installer';
  allowed_site_ids: number[];
  permissions: string[];
  allowed_sites?: SiteOption[];
}

const permissionOptions = [
  { id: 'view_clients', label: 'Active clients' },
  { id: 'view_routers', label: 'Routers and monitoring' },
  { id: 'view_transactions', label: 'Transactions' },
  { id: 'manage_vouchers', label: 'Vouchers' },
];

const emptyForm = {
  name: '',
  email: '',
  password: '',
  role: 'sub_user' as 'sub_user' | 'installer',
  is_active: true,
  allowed_site_ids: [] as number[],
  permissions: ['view_clients', 'view_routers'] as string[],
};

export function SubUsers() {
  const { user } = useAuth();
  const [subUsers, setSubUsers] = useState<SubUser[]>([]);
  const [sites, setSites] = useState<SiteOption[]>([]);
  const [loading, setLoading] = useState(true);
  const [saving, setSaving] = useState(false);
  const [editing, setEditing] = useState<SubUser | null>(null);
  const [form, setForm] = useState({ ...emptyForm });

  const load = async () => {
    setLoading(true);
    try {
      const [usersData, sitesData] = await Promise.all([getSubUsers(), getSites()]);
      setSubUsers(usersData.sub_users || []);
      setSites(sitesData.sites || []);
    } finally {
      setLoading(false);
    }
  };

  useEffect(() => {
    load();
  }, []);

  const toggleNumber = (field: 'allowed_site_ids', value: number) => {
    if (form.role === 'installer') {
      setForm({ ...form, [field]: form[field].includes(value) ? [] : [value] });
      return;
    }

    const next = form[field].includes(value)
      ? form[field].filter((item) => item !== value)
      : [...form[field], value];
    setForm({ ...form, [field]: next });
  };

  const togglePermission = (value: string) => {
    const next = form.permissions.includes(value)
      ? form.permissions.filter((item) => item !== value)
      : [...form.permissions, value];
    setForm({ ...form, permissions: next });
  };

  const edit = (user: SubUser) => {
    setEditing(user);
    setForm({
      name: user.name,
      email: user.email,
      password: '',
      is_active: user.is_active,
      role: user.role || 'sub_user',
      allowed_site_ids: user.allowed_site_ids || [],
      permissions: user.permissions || [],
    });
  };

  const reset = () => {
    setEditing(null);
    setForm({ ...emptyForm });
  };

  const submit = async (event: FormEvent) => {
    event.preventDefault();
    setSaving(true);
    try {
      if (editing) {
        await updateSubUser(editing.id, form);
      } else {
        await createSubUser(form);
      }
      reset();
      await load();
    } catch (error: any) {
      alert(error.message || 'Failed to save sub-user');
    } finally {
      setSaving(false);
    }
  };

  const remove = async (user: SubUser) => {
    if (!confirm(`Delete account user ${user.name}?`)) return;
    await deleteSubUser(user.id);
    await load();
  };

  const cannotSaveReason = (() => {
    if (saving) return '';
    if (form.allowed_site_ids.length === 0) {
      return form.role === 'installer'
        ? 'Select the installer assigned site first.'
        : 'Select at least one site first.';
    }
    if (form.role === 'sub_user' && form.permissions.length === 0) {
      return 'Select at least one dashboard permission first.';
    }
    return '';
  })();

  if (loading) {
    return <div className="min-h-screen grid place-items-center"><Loader2 className="w-8 h-8 animate-spin text-primary" /></div>;
  }

  return (
    <div className="min-h-screen bg-background p-6 lg:p-8 space-y-6">
      <div>
        <h1 className="text-2xl font-semibold text-foreground flex items-center gap-2">
          <UserCog className="w-7 h-7 text-primary" />
          Account Users
        </h1>
        <p className="text-muted-foreground mt-1">Give staff limited access to specific sites and dashboard sections.</p>
      </div>

      <form onSubmit={submit} className="bg-card border border-border rounded-lg p-5 space-y-4">
        <div className="flex items-center gap-2">
          <Plus className="w-5 h-5 text-primary" />
          <h2 className="font-semibold text-card-foreground">{editing ? 'Edit Account User' : 'Add Account User'}</h2>
        </div>

        <div className="grid md:grid-cols-2 gap-4">
          <label className="space-y-1">
            <span className="text-sm text-muted-foreground">Name</span>
            <input required value={form.name} onChange={(e) => setForm({ ...form, name: e.target.value })} className="w-full px-3 py-2 bg-background border border-input rounded-lg" />
          </label>
          <label className="space-y-1">
            <span className="text-sm text-muted-foreground">Email</span>
            <input required type="email" value={form.email} onChange={(e) => setForm({ ...form, email: e.target.value })} className="w-full px-3 py-2 bg-background border border-input rounded-lg" />
          </label>
          <label className="space-y-1">
            <span className="text-sm text-muted-foreground">{editing ? 'New password' : 'Password'}</span>
            <input required={!editing} type="password" value={form.password} onChange={(e) => setForm({ ...form, password: e.target.value })} className="w-full px-3 py-2 bg-background border border-input rounded-lg" />
          </label>
          <label className="space-y-1">
            <span className="text-sm text-muted-foreground">Profile</span>
            <select value={form.role} onChange={(e) => {
              const role = e.target.value as 'sub_user' | 'installer';
              setForm({
                ...form,
                role,
                allowed_site_ids: role === 'installer' ? form.allowed_site_ids.slice(0, 1) : form.allowed_site_ids,
                permissions: role === 'installer' ? ['installer:devices:create'] : ['view_clients', 'view_routers'],
              });
            }} className="w-full px-3 py-2 bg-background border border-input rounded-lg">
              <option value="sub_user">Dashboard user</option>
              <option value="installer">Installer app user</option>
            </select>
          </label>
          <label className="flex items-center gap-2 pt-6 text-sm text-card-foreground">
            <input type="checkbox" checked={form.is_active} onChange={(e) => setForm({ ...form, is_active: e.target.checked })} />
            Active
          </label>
        </div>

        <div className="grid lg:grid-cols-2 gap-4">
          <div>
            <p className="text-sm font-medium text-card-foreground mb-2">Sites</p>
            <div className="grid sm:grid-cols-2 gap-2">
              {sites.map((site) => (
                <label key={site.id} className="flex items-center gap-2 rounded-lg border border-border px-3 py-2 text-sm">
                  <input type={form.role === 'installer' ? 'radio' : 'checkbox'} checked={form.allowed_site_ids.includes(site.id)} onChange={() => toggleNumber('allowed_site_ids', site.id)} />
                  <span>
                    {site.name}
                    {site.assigned_device_ip_range && <span className="block text-xs text-muted-foreground">{site.assigned_device_ip_range}</span>}
                  </span>
                </label>
              ))}
            </div>
            {form.role === 'installer' && (
              <p className="mt-2 text-xs text-muted-foreground">Installer profiles use one assigned site only and receive its assigned device IP range in the mobile app.</p>
            )}
            {sites.length === 0 && (
              <p className="mt-2 text-xs text-destructive">No sites are available yet. Create a site before adding installer accounts.</p>
            )}
          </div>
          {form.role === 'sub_user' && <div>
            <p className="text-sm font-medium text-card-foreground mb-2">Permissions</p>
            <div className="grid sm:grid-cols-2 gap-2">
              {permissionOptions.map((permission) => (
                <label key={permission.id} className="flex items-center gap-2 rounded-lg border border-border px-3 py-2 text-sm">
                  <input type="checkbox" checked={form.permissions.includes(permission.id)} onChange={() => togglePermission(permission.id)} />
                  {permission.label}
                </label>
              ))}
            </div>
          </div>}
        </div>

        <div className="flex justify-end gap-2">
          {editing && <button type="button" onClick={reset} className="px-4 py-2 rounded-lg border border-border hover:bg-muted">Cancel</button>}
          {cannotSaveReason && <p className="mr-auto text-xs text-muted-foreground">{cannotSaveReason}</p>}
          <button disabled={saving || Boolean(cannotSaveReason)} className="inline-flex items-center gap-2 px-4 py-2 rounded-lg bg-primary text-primary-foreground disabled:opacity-50">
            {saving ? <Loader2 className="w-4 h-4 animate-spin" /> : <ShieldCheck className="w-4 h-4" />}
            {editing ? 'Update account user' : 'Create account user'}
          </button>
        </div>
      </form>

      <div className="bg-card border border-border rounded-lg overflow-hidden">
        <div className="p-5 border-b border-border">
          <h2 className="font-semibold text-card-foreground">Existing Account Users</h2>
        </div>
        <div className="overflow-x-auto">
          <table className="w-full text-sm">
            <thead className="text-left text-muted-foreground border-b border-border">
              <tr>
                <th className="px-5 py-3 font-medium">User</th>
                <th className="px-5 py-3 font-medium">Sites</th>
                <th className="px-5 py-3 font-medium">Profile</th>
                <th className="px-5 py-3 font-medium">Permissions</th>
                <th className="px-5 py-3 font-medium">Status</th>
                <th className="px-5 py-3 font-medium text-right">Actions</th>
              </tr>
            </thead>
            <tbody>
              {user && (
                <tr className="border-b border-border/70 bg-primary/5">
                  <td className="px-5 py-3">
                    <p className="font-medium text-card-foreground">{user.name}</p>
                    <p className="text-xs text-muted-foreground">{user.email}</p>
                  </td>
                  <td className="px-5 py-3 text-muted-foreground">All sites</td>
                  <td className="px-5 py-3 text-muted-foreground">Owner</td>
                  <td className="px-5 py-3 text-muted-foreground">Owner privileges</td>
                  <td className="px-5 py-3">Owner</td>
                  <td className="px-5 py-3 text-right text-xs text-muted-foreground">Locked</td>
                </tr>
              )}
              {subUsers.length === 0 ? (
                <tr><td colSpan={6} className="px-5 py-8 text-center text-muted-foreground">No additional account users yet.</td></tr>
              ) : subUsers.map((accountUser) => (
                <tr key={accountUser.id} className="border-b border-border/70 last:border-0">
                  <td className="px-5 py-3">
                    <p className="font-medium text-card-foreground">{accountUser.name}</p>
                    <p className="text-xs text-muted-foreground">{accountUser.email}</p>
                  </td>
                  <td className="px-5 py-3 text-muted-foreground">{accountUser.allowed_sites?.map((site) => `${site.name}${site.assigned_device_ip_range ? ` (${site.assigned_device_ip_range})` : ''}`).join(', ') || '-'}</td>
                  <td className="px-5 py-3 text-muted-foreground">{accountUser.role === 'installer' ? 'Installer' : 'Dashboard user'}</td>
                  <td className="px-5 py-3 text-muted-foreground">{accountUser.role === 'installer' ? 'Installer device uploads' : accountUser.permissions.join(', ')}</td>
                  <td className="px-5 py-3">{accountUser.is_active ? 'Active' : 'Disabled'}</td>
                  <td className="px-5 py-3 text-right">
                    <button onClick={() => edit(accountUser)} className="p-2 rounded-lg hover:bg-muted"><Edit2 className="w-4 h-4" /></button>
                    <button onClick={() => remove(accountUser)} className="p-2 rounded-lg hover:bg-destructive/10 text-destructive"><Trash2 className="w-4 h-4" /></button>
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
