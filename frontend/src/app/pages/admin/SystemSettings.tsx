import React, { useEffect, useState } from 'react';
import { useNavigate } from 'react-router';
import {
  Settings,
  Save,
  RefreshCw,
  Globe,
  Mail,
  DollarSign,
  Clock,
  Shield,
  Bell,
  Database,
  ChevronRight,
} from 'lucide-react';

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
    { id: 'trial', name: 'Trial Settings', icon: <Clock className="w-5 h-5" /> },
    { id: 'payment', name: 'Payment', icon: <DollarSign className="w-5 h-5" /> },
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
      const response = await fetch('/api/super-admin/settings', {
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
      const response = await fetch('/api/super-admin/settings/bulk-update', {
        method: 'POST',
        headers: {
          'Authorization': `Bearer ${token}`,
          'Content-Type': 'application/json',
        },
        body: JSON.stringify({ settings: editedSettings }),
      });

      if (response.ok) {
        setEditedSettings({});
        fetchSettings();
        alert('Settings saved successfully');
      } else {
        const data = await response.json();
        alert(data.message || 'Failed to save settings');
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
    trial: [
      { key: 'default_trial_days', label: 'Default Trial Days', type: 'number', description: 'Number of trial days for new tenants' },
      { key: 'trial_extension_days', label: 'Trial Extension Days', type: 'number', description: 'Days to extend trial when requested' },
      { key: 'auto_suspend_expired', label: 'Auto Suspend Expired', type: 'boolean', description: 'Automatically suspend expired trials' },
    ],
    payment: [
      { key: 'platform_fee_percentage', label: 'Platform Fee (%)', type: 'number', description: 'Percentage fee on transactions' },
      { key: 'minimum_withdrawal', label: 'Minimum Withdrawal', type: 'number', description: 'Minimum withdrawal amount' },
      { key: 'payment_gateway', label: 'Payment Gateway', type: 'string', description: 'Active payment gateway' },
    ],
    email: [
      { key: 'smtp_host', label: 'SMTP Host', type: 'string', description: 'SMTP server hostname' },
      { key: 'smtp_port', label: 'SMTP Port', type: 'number', description: 'SMTP server port' },
      { key: 'smtp_username', label: 'SMTP Username', type: 'string', description: 'SMTP authentication username' },
      { key: 'smtp_from_address', label: 'From Address', type: 'string', description: 'Default sender email address' },
    ],
    security: [
      { key: 'session_lifetime', label: 'Session Lifetime (minutes)', type: 'number', description: 'How long sessions remain active' },
      { key: 'password_min_length', label: 'Min Password Length', type: 'number', description: 'Minimum password length requirement' },
      { key: 'require_2fa', label: 'Require 2FA', type: 'boolean', description: 'Require two-factor authentication' },
    ],
    notifications: [
      { key: 'notify_new_signup', label: 'New Signup Notifications', type: 'boolean', description: 'Email admin on new signups' },
      { key: 'notify_trial_expiry', label: 'Trial Expiry Notifications', type: 'boolean', description: 'Notify before trial expires' },
      { key: 'trial_expiry_days', label: 'Days Before Expiry', type: 'number', description: 'Days before expiry to notify' },
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
