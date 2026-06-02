import { FormEvent, useEffect, useState } from 'react';
import { Building2, Check, Loader2, LockKeyhole, Mail, Phone, Save, User } from 'lucide-react';
import { TwoFactorPanel } from '../components/TwoFactorPanel';
import { tenantChangePassword, tenantMe, updateTenantProfile } from '../utils/api';

export function Settings() {
  const [loading, setLoading] = useState(true);
  const [savingProfile, setSavingProfile] = useState(false);
  const [savingPassword, setSavingPassword] = useState(false);
  const [profileMessage, setProfileMessage] = useState('');
  const [passwordMessage, setPasswordMessage] = useState('');
  const [error, setError] = useState('');
  const [profile, setProfile] = useState({
    name: '',
    email: '',
    tenant_name: '',
    default_withdraw_phone: '',
  });
  const [passwords, setPasswords] = useState({
    current_password: '',
    new_password: '',
    new_password_confirmation: '',
  });

  useEffect(() => {
    const load = async () => {
      setLoading(true);
      try {
        const data = await tenantMe();
        setProfile({
          name: data.user?.name || '',
          email: data.user?.email || '',
          tenant_name: data.tenant?.name || data.user?.tenant_name || '',
          default_withdraw_phone: data.tenant?.settings?.default_withdraw_phone || '',
        });
      } catch (err: any) {
        setError(err.message || 'Failed to load account settings');
      } finally {
        setLoading(false);
      }
    };

    load();
  }, []);

  const saveProfile = async (event: FormEvent) => {
    event.preventDefault();
    setError('');
    setProfileMessage('');
    setSavingProfile(true);

    try {
      const response = await updateTenantProfile(profile);
      localStorage.setItem('tenant_user', JSON.stringify(response.user));
      setProfileMessage(response.message || 'Profile updated successfully');
    } catch (err: any) {
      setError(err.message || 'Failed to update profile');
    } finally {
      setSavingProfile(false);
    }
  };

  const savePassword = async (event: FormEvent) => {
    event.preventDefault();
    setError('');
    setPasswordMessage('');

    if (passwords.new_password !== passwords.new_password_confirmation) {
      setError('New password and confirmation do not match');
      return;
    }

    setSavingPassword(true);
    try {
      const response = await tenantChangePassword(
        passwords.current_password,
        passwords.new_password,
        passwords.new_password_confirmation
      );
      setPasswords({ current_password: '', new_password: '', new_password_confirmation: '' });
      setPasswordMessage(response.message || 'Password changed successfully');
    } catch (err: any) {
      setError(err.message || 'Failed to change password');
    } finally {
      setSavingPassword(false);
    }
  };

  if (loading) {
    return (
      <div className="flex items-center justify-center h-64">
        <Loader2 className="w-7 h-7 text-primary animate-spin" />
      </div>
    );
  }

  return (
    <div className="p-4 sm:p-6 lg:p-8 space-y-6">
      <div>
        <h1 className="text-2xl font-bold text-foreground">General Settings</h1>
        <p className="text-sm text-muted-foreground mt-1">Manage your account details, withdrawal phone number, security, and password.</p>
      </div>

      {error && (
        <div className="rounded-lg border border-destructive/20 bg-destructive/10 p-3 text-sm text-destructive">
          {error}
        </div>
      )}

      <form onSubmit={saveProfile} className="bg-card border border-border rounded-lg p-5 sm:p-6 space-y-5">
        <div className="flex items-center gap-3">
          <div className="w-11 h-11 rounded-lg bg-primary/10 text-primary grid place-items-center">
            <User className="w-5 h-5" />
          </div>
          <div>
            <h2 className="text-lg font-semibold text-card-foreground">Account Information</h2>
            <p className="text-sm text-muted-foreground">These details identify the dashboard owner and account.</p>
          </div>
        </div>

        {profileMessage && (
          <div className="rounded-lg border border-emerald-500/20 bg-emerald-500/10 p-3 text-sm text-emerald-700 flex items-center gap-2">
            <Check className="w-4 h-4" />
            {profileMessage}
          </div>
        )}

        <div className="grid md:grid-cols-2 gap-4">
          <label className="space-y-2">
            <span className="text-sm font-medium text-card-foreground flex items-center gap-2">
              <User className="w-4 h-4 text-muted-foreground" />
              Owner Name
            </span>
            <input
              value={profile.name}
              onChange={(event) => setProfile({ ...profile, name: event.target.value })}
              className="w-full px-3 py-2 bg-background border border-input rounded-lg focus:outline-none focus:ring-2 focus:ring-primary"
              required
            />
          </label>

          <label className="space-y-2">
            <span className="text-sm font-medium text-card-foreground flex items-center gap-2">
              <Mail className="w-4 h-4 text-muted-foreground" />
              Login Email
            </span>
            <input
              type="email"
              value={profile.email}
              onChange={(event) => setProfile({ ...profile, email: event.target.value })}
              className="w-full px-3 py-2 bg-background border border-input rounded-lg focus:outline-none focus:ring-2 focus:ring-primary"
              required
            />
          </label>

          <label className="space-y-2">
            <span className="text-sm font-medium text-card-foreground flex items-center gap-2">
              <Building2 className="w-4 h-4 text-muted-foreground" />
              Account Name
            </span>
            <input
              value={profile.tenant_name}
              onChange={(event) => setProfile({ ...profile, tenant_name: event.target.value })}
              className="w-full px-3 py-2 bg-background border border-input rounded-lg focus:outline-none focus:ring-2 focus:ring-primary"
              required
            />
          </label>

          <label className="space-y-2">
            <span className="text-sm font-medium text-card-foreground flex items-center gap-2">
              <Phone className="w-4 h-4 text-muted-foreground" />
              Default Withdrawal Phone
            </span>
            <input
              value={profile.default_withdraw_phone}
              onChange={(event) => setProfile({ ...profile, default_withdraw_phone: event.target.value })}
              className="w-full px-3 py-2 bg-background border border-input rounded-lg focus:outline-none focus:ring-2 focus:ring-primary"
              placeholder="+256..."
            />
          </label>
        </div>

        <div className="flex justify-end">
          <button
            disabled={savingProfile}
            className="inline-flex items-center gap-2 px-4 py-2 rounded-lg bg-primary text-primary-foreground hover:bg-primary/90 disabled:opacity-60"
          >
            {savingProfile ? <Loader2 className="w-4 h-4 animate-spin" /> : <Save className="w-4 h-4" />}
            Save Account
          </button>
        </div>
      </form>

      <div className="bg-card border border-border rounded-lg p-5 sm:p-6">
        <TwoFactorPanel endpointPrefix="/tenant" />
      </div>

      <form onSubmit={savePassword} className="bg-card border border-border rounded-lg p-5 sm:p-6 space-y-5">
        <div className="flex items-center gap-3">
          <div className="w-11 h-11 rounded-lg bg-primary/10 text-primary grid place-items-center">
            <LockKeyhole className="w-5 h-5" />
          </div>
          <div>
            <h2 className="text-lg font-semibold text-card-foreground">Change Password</h2>
            <p className="text-sm text-muted-foreground">Update your login password securely.</p>
          </div>
        </div>

        {passwordMessage && (
          <div className="rounded-lg border border-emerald-500/20 bg-emerald-500/10 p-3 text-sm text-emerald-700 flex items-center gap-2">
            <Check className="w-4 h-4" />
            {passwordMessage}
          </div>
        )}

        <div className="grid md:grid-cols-3 gap-4">
          <label className="space-y-2">
            <span className="text-sm font-medium text-card-foreground">Current Password</span>
            <input
              type="password"
              value={passwords.current_password}
              onChange={(event) => setPasswords({ ...passwords, current_password: event.target.value })}
              className="w-full px-3 py-2 bg-background border border-input rounded-lg focus:outline-none focus:ring-2 focus:ring-primary"
              required
            />
          </label>

          <label className="space-y-2">
            <span className="text-sm font-medium text-card-foreground">New Password</span>
            <input
              type="password"
              value={passwords.new_password}
              onChange={(event) => setPasswords({ ...passwords, new_password: event.target.value })}
              className="w-full px-3 py-2 bg-background border border-input rounded-lg focus:outline-none focus:ring-2 focus:ring-primary"
              minLength={8}
              required
            />
          </label>

          <label className="space-y-2">
            <span className="text-sm font-medium text-card-foreground">Confirm New Password</span>
            <input
              type="password"
              value={passwords.new_password_confirmation}
              onChange={(event) => setPasswords({ ...passwords, new_password_confirmation: event.target.value })}
              className="w-full px-3 py-2 bg-background border border-input rounded-lg focus:outline-none focus:ring-2 focus:ring-primary"
              minLength={8}
              required
            />
          </label>
        </div>

        <div className="flex justify-end">
          <button
            disabled={savingPassword}
            className="inline-flex items-center gap-2 px-4 py-2 rounded-lg bg-primary text-primary-foreground hover:bg-primary/90 disabled:opacity-60"
          >
            {savingPassword ? <Loader2 className="w-4 h-4 animate-spin" /> : <LockKeyhole className="w-4 h-4" />}
            Change Password
          </button>
        </div>
      </form>
    </div>
  );
}
