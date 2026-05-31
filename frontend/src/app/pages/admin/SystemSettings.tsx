import React, { useEffect, useState } from 'react';
import { useNavigate } from 'react-router';
import {
  Settings,
  Save,
  RefreshCw,
  Globe,
  Mail,
  DollarSign,
  Shield,
  Bell,
  Database,
  Server,
  ChevronRight,
} from 'lucide-react';
import { TwoFactorPanel } from '@/app/components/TwoFactorPanel';
import { API_BASE } from '../../utils/api';

interface Setting {
  key: string;
  value: string;
  type: 'string' | 'number' | 'boolean' | 'json';
  group: string;
  description?: string;
}

interface SettingGroup {
  name: string;
  icon: React.ReactNode;
  settings: Setting[];
}

export default function SystemSettings() {
  const navigate = useNavigate();
  const [settings, setSettings] = useState<Setting[]>([]);
  const [loading, setLoading] = useState(true);
  const [saving, setSaving] = useState(false);
  const [activeGroup, setActiveGroup] = useState('general');
  const [editedSettings, setEditedSettings] = useState<Record<string, string>>({});

  const settingGroups = [
    { id: 'general', name: 'General', icon: <Globe className="w-5 h-5" /> },
    { id: 'payment', name: 'Payment', icon: <DollarSign className="w-5 h-5" /> },
    { id: 'radius', name: 'RADIUS', icon: <Server className="w-5 h-5" /> },
    { id: 'email', name: 'Email', icon: <Mail className="w-5 h-5" /> },
    { id: 'security', name: 'Security', icon: <Shield className="w-5 h-5" /> },
    { id: 'notifications', name: 'Notifications', icon: <Bell className="w-5 h-5" /> },
  ];

  useEffect(() => {
    fetchSettings();
  }, []);

  const fetchSettings = async () => {
    try {
      setLoading(true);
      const token = localStorage.getItem('admin_token');
      const response = await fetch(`${API_BASE}/super-admin/settings`, {
        headers: { 'Authorization': `Bearer ${token}` },
      });

      if (!response.ok) {
        if (response.status === 401) {
          navigate('/admin/login');
          return;
        }
        throw new Error('Failed to fetch settings');
      }

      const data = await response.json();
      setSettings(Array.isArray(data) ? data : data.settings || []);
    } catch (error) {
      console.error('Error fetching settings:', error);
    } finally {
      setLoading(false);
    }
  };

  const handleSettingChange = (key: string, value: string) => {
    setEditedSettings((prev) => ({ ...prev, [key]: value }));
  };

  const handleSave = async () => {
    if (Object.keys(editedSettings).length === 0) return;

    setSaving(true);
    try {
      const token = localStorage.getItem('admin_token');
      const response = await fetch(`${API_BASE}/super-admin/settings/bulk-update`, {
        method: 'POST',
        headers: {
          'Authorization': `Bearer ${token}`,
          'Content-Type': 'application/json',
        },
        body: JSON.stringify({
          settings: Object.entries(editedSettings).map(([key, value]) => ({ key, value })),
        }),
      });

      if (response.ok) {
        setEditedSettings({});
        fetchSettings();
        alert('Settings saved successfully');
      } else {
        const data = await response.json();
        const validationMessage = data.errors
          ? Object.values(data.errors).flat().join('\n')
          : '';
        alert(data.message || validationMessage || 'Failed to save settings');
      }
    } catch (error) {
      console.error('Error saving settings:', error);
      alert('Failed to save settings');
    } finally {
      setSaving(false);
    }
  };

  const getSettingValue = (key: string) => {
    if (editedSettings[key] !== undefined) return editedSettings[key];
    const setting = settings.find((s) => s.key === key);
    return setting?.value || '';
  };

  const groupedSettings = settings.reduce((acc, setting) => {
    const group = setting.group || 'general';
    if (!acc[group]) acc[group] = [];
    acc[group].push(setting);
    return acc;
  }, {} as Record<string, Setting[]>);

  const defaultSettings: Record<string, { key: string; label: string; type: string; description: string }[]> = {
    general: [
      { key: 'app_name', label: 'Application Name', type: 'string', description: 'The name of your application' },
      { key: 'app_url', label: 'Application URL', type: 'string', description: 'The base URL of your application' },
      { key: 'support_email', label: 'Support Email', type: 'string', description: 'Email for support inquiries' },
    ],
    payment: [
      { key: 'platform_collection_fee_percent', label: 'Collection Fee (%)', type: 'number', description: 'Percentage fee on incoming payments' },
      { key: 'platform_minimum_disbursement', label: 'Minimum Disbursement', type: 'number', description: 'Minimum tenant payout amount' },
      { key: 'default_trial_days', label: 'Default Trial Days', type: 'number', description: 'Trial period granted when a tenant is approved' },
      { key: 'tenant_monthly_subscription_amount', label: 'Monthly Subscription Amount', type: 'number', description: 'Default monthly platform charge for tenants' },
      { key: 'tenant_subscription_currency', label: 'Subscription Currency', type: 'string', description: 'Currency displayed on subscription invoices and prompts' },
      { key: 'subscription_renewal_months', label: 'Renewal Months', type: 'number', description: 'Default months purchased when a tenant renews' },
      { key: 'require_subscription', label: 'Require Subscription', type: 'boolean', description: 'Require tenants to renew after trial expiry' },
      { key: 'dashboard_lock_on_expired_subscription', label: 'Lock Expired Dashboard', type: 'boolean', description: 'Keep services active but block dashboard access when billing expires' },
      { key: 'payment_gateway', label: 'Payment Gateway', type: 'string', description: 'Active payment gateway' },
    ],
    radius: [
      { key: 'radius_server_ip', label: 'RADIUS Server IP', type: 'string', description: 'FreeRADIUS server address used in generated router scripts' },
      { key: 'radius_auth_port', label: 'Authentication Port', type: 'number', description: 'RADIUS authentication UDP port' },
      { key: 'radius_acct_port', label: 'Accounting Port', type: 'number', description: 'RADIUS accounting UDP port' },
      { key: 'radius_shared_secret', label: 'Shared Secret', type: 'string', description: 'Fallback shared secret for router authentication' },
    ],
    email: [
      { key: 'smtp_host', label: 'SMTP Host', type: 'string', description: 'SMTP server hostname' },
      { key: 'smtp_port', label: 'SMTP Port', type: 'number', description: 'SMTP server port' },
      { key: 'smtp_username', label: 'SMTP Username', type: 'string', description: 'SMTP authentication username' },
      { key: 'smtp_password', label: 'SMTP Password', type: 'string', description: 'SMTP authentication password' },
      { key: 'smtp_encryption', label: 'SMTP Encryption', type: 'string', description: 'tls, ssl, or blank' },
      { key: 'smtp_from_address', label: 'From Address', type: 'string', description: 'Default sender email address' },
      { key: 'smtp_from_name', label: 'From Name', type: 'string', description: 'Default sender name' },
    ],
    security: [
      { key: 'session_lifetime', label: 'Session Lifetime (minutes)', type: 'number', description: 'How long sessions remain active' },
      { key: 'password_min_length', label: 'Min Password Length', type: 'number', description: 'Minimum password length requirement' },
      { key: 'require_2fa', label: 'Require 2FA', type: 'boolean', description: 'Require two-factor authentication' },
    ],
    notifications: [
      { key: 'notify_new_signup', label: 'New Signup Notifications', type: 'boolean', description: 'Email admin on new signups' },
      { key: 'notify_signup_email', label: 'Signup Email', type: 'boolean', description: 'Email tenant after signup' },
      { key: 'notify_activation_email', label: 'Activation Email', type: 'boolean', description: 'Email tenant after approval' },
      { key: 'notify_password_reset_email', label: 'Password Reset Email', type: 'boolean', description: 'Email tenant after password reset' },
      { key: 'notify_announcement_email', label: 'Announcement Email', type: 'boolean', description: 'Allow announcements to be emailed' },
    ],
  };

  const currentSettings = defaultSettings[activeGroup] || [];

  return (
    <div className="space-y-6">
      {/* Header */}
      <div className="flex flex-col sm:flex-row justify-between items-start sm:items-center gap-4">
        <div>
          <h1 className="text-2xl font-bold text-white">System Settings</h1>
          <p className="text-slate-400 mt-1">Configure global system settings</p>
        </div>
        <div className="flex gap-3">
          <button
            onClick={fetchSettings}
            className="flex items-center gap-2 px-4 py-2 bg-slate-700 hover:bg-slate-600 text-white rounded-xl transition-colors"
          >
            <RefreshCw className={`w-4 h-4 ${loading ? 'animate-spin' : ''}`} />
            Refresh
          </button>
          <button
            onClick={handleSave}
            disabled={saving || Object.keys(editedSettings).length === 0}
            className="flex items-center gap-2 px-4 py-2 bg-indigo-600 hover:bg-indigo-500 text-white rounded-xl transition-colors disabled:opacity-50 disabled:cursor-not-allowed"
          >
            <Save className="w-4 h-4" />
            {saving ? 'Saving...' : 'Save Changes'}
          </button>
        </div>
      </div>

      <div className="flex flex-col lg:flex-row gap-6">
        {/* Sidebar */}
        <div className="lg:w-64 flex-shrink-0">
          <div className="bg-slate-800 rounded-2xl border border-slate-700 overflow-hidden">
            <div className="p-4 border-b border-slate-700">
              <h3 className="text-sm font-medium text-slate-400">Categories</h3>
            </div>
            <nav className="p-2">
              {settingGroups.map((group) => (
                <button
                  key={group.id}
                  onClick={() => setActiveGroup(group.id)}
                  className={`w-full flex items-center gap-3 px-4 py-3 rounded-xl transition-colors ${
                    activeGroup === group.id
                      ? 'bg-indigo-600 text-white'
                      : 'text-slate-300 hover:bg-slate-700'
                  }`}
                >
                  {group.icon}
                  <span className="text-sm font-medium">{group.name}</span>
                  <ChevronRight className="w-4 h-4 ml-auto" />
                </button>
              ))}
            </nav>
          </div>
        </div>

        {/* Settings Panel */}
        <div className="flex-1">
          <div className="bg-slate-800 rounded-2xl border border-slate-700">
            <div className="p-6 border-b border-slate-700">
              <h2 className="text-lg font-semibold text-white">
                {settingGroups.find((g) => g.id === activeGroup)?.name} Settings
              </h2>
            </div>
            <div className="p-6 space-y-6">
              {activeGroup === 'security' && (
                <TwoFactorPanel endpointPrefix="/super-admin" />
              )}
              {loading ? (
                <div className="flex items-center justify-center py-12">
                  <RefreshCw className="w-8 h-8 text-indigo-500 animate-spin" />
                </div>
              ) : (
                currentSettings.map((setting) => (
                  <div key={setting.key} className="space-y-2">
                    <label className="block text-sm font-medium text-slate-300">
                      {setting.label}
                    </label>
                    {setting.type === 'boolean' ? (
                      <label className="relative inline-flex items-center cursor-pointer">
                        <input
                          type="checkbox"
                          checked={getSettingValue(setting.key) === 'true' || getSettingValue(setting.key) === '1'}
                          onChange={(e) => handleSettingChange(setting.key, e.target.checked ? 'true' : 'false')}
                          className="sr-only peer"
                        />
                        <div className="w-11 h-6 bg-slate-600 peer-focus:outline-none peer-focus:ring-2 peer-focus:ring-indigo-500 rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-indigo-600"></div>
                      </label>
                    ) : (
                      <input
                        type={setting.type === 'number' ? 'number' : 'text'}
                        value={getSettingValue(setting.key)}
                        onChange={(e) => handleSettingChange(setting.key, e.target.value)}
                        className="w-full px-4 py-2.5 bg-slate-700 border border-slate-600 rounded-xl text-white focus:outline-none focus:ring-2 focus:ring-indigo-500"
                        placeholder={`Enter ${setting.label.toLowerCase()}`}
                      />
                    )}
                    <p className="text-xs text-slate-500">{setting.description}</p>
                  </div>
                ))
              )}
            </div>
          </div>
        </div>
      </div>
    </div>
  );
}
