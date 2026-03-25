import React, { useEffect, useState } from 'react';
import { useNavigate } from 'react-router';
import {
  DollarSign,
  TrendingUp,
  Users,
  Calendar,
  RefreshCw,
  Save,
  ArrowUpRight,
  ArrowDownRight,
} from 'lucide-react';

interface FeeSettings {
  platform_fee_percentage: number;
  minimum_fee: number;
  maximum_fee: number;
  fee_collection_method: string;
}

interface RevenueSummary {
  total_revenue: number;
  this_month: number;
  last_month: number;
  pending_collection: number;
}

interface TenantBalance {
  tenant_id: number;
  tenant_name: string;
  total_transactions: number;
  total_fees: number;
  collected: number;
  pending: number;
}

export default function PlatformFees() {
  const navigate = useNavigate();
  const [settings, setSettings] = useState<FeeSettings>({
    platform_fee_percentage: 2.5,
    minimum_fee: 0,
    maximum_fee: 0,
    fee_collection_method: 'automatic',
  });
  const [revenue, setRevenue] = useState<RevenueSummary>({
    total_revenue: 0,
    this_month: 0,
    last_month: 0,
    pending_collection: 0,
  });
  const [tenantBalances, setTenantBalances] = useState<TenantBalance[]>([]);
  const [loading, setLoading] = useState(true);
  const [saving, setSaving] = useState(false);
  const [activeTab, setActiveTab] = useState<'overview' | 'settings' | 'balances'>('overview');

  useEffect(() => {
    fetchData();
  }, []);

  const fetchData = async () => {
    setLoading(true);
    try {
      const token = localStorage.getItem('admin_token');
      const headers = { 'Authorization': `Bearer ${token}` };

      const [settingsRes, revenueRes, balancesRes] = await Promise.all([
        fetch('/api/super-admin/platform-fees/settings', { headers }),
        fetch('/api/super-admin/platform-fees/revenue', { headers }),
        fetch('/api/super-admin/platform-fees/tenant-balances', { headers }),
      ]);

      if (settingsRes.ok) {
        const data = await settingsRes.json();
        setSettings(data);
      }
      if (revenueRes.ok) {
        const data = await revenueRes.json();
        setRevenue(data);
      }
      if (balancesRes.ok) {
        const data = await balancesRes.json();
        setTenantBalances(Array.isArray(data) ? data : data.balances || []);
      }
    } catch (error) {
      console.error('Error fetching data:', error);
    } finally {
      setLoading(false);
    }
  };

  const handleSaveSettings = async () => {
    setSaving(true);
    try {
      const token = localStorage.getItem('admin_token');
      const response = await fetch('/api/super-admin/platform-fees/settings', {
        method: 'PUT',
        headers: {
          'Authorization': `Bearer ${token}`,
          'Content-Type': 'application/json',
        },
        body: JSON.stringify(settings),
      });

      if (response.ok) {
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

  const formatCurrency = (amount: number) => {
    return new Intl.NumberFormat('en-UG', {
      style: 'currency',
      currency: 'UGX',
      minimumFractionDigits: 0,
    }).format(amount);
  };

  const monthlyChange = revenue.last_month > 0
    ? ((revenue.this_month - revenue.last_month) / revenue.last_month) * 100
    : 0;

  return (
    <div className="space-y-6">
      {/* Header */}
      <div className="flex flex-col sm:flex-row justify-between items-start sm:items-center gap-4">
        <div>
          <h1 className="text-2xl font-bold text-white">Platform Fees</h1>
          <p className="text-slate-400 mt-1">Manage platform fee settings and view revenue</p>
        </div>
        <button
          onClick={fetchData}
          className="flex items-center gap-2 px-4 py-2 bg-slate-700 hover:bg-slate-600 text-white rounded-xl transition-colors"
        >
          <RefreshCw className={`w-4 h-4 ${loading ? 'animate-spin' : ''}`} />
          Refresh
        </button>
      </div>

      {/* Tabs */}
      <div className="flex gap-2 border-b border-slate-700 pb-2">
        {(['overview', 'settings', 'balances'] as const).map((tab) => (
          <button
            key={tab}
            onClick={() => setActiveTab(tab)}
            className={`px-4 py-2 rounded-t-xl text-sm font-medium transition-colors ${
              activeTab === tab
                ? 'bg-slate-800 text-white border-b-2 border-indigo-500'
                : 'text-slate-400 hover:text-white'
            }`}
          >
            {tab.charAt(0).toUpperCase() + tab.slice(1)}
          </button>
        ))}
      </div>

      {/* Overview Tab */}
      {activeTab === 'overview' && (
        <div className="space-y-6">
          {/* Stats Grid */}
          <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
            <div className="bg-slate-800 rounded-2xl border border-slate-700 p-6">
              <div className="flex items-center justify-between">
                <div className="p-3 bg-green-500/20 rounded-xl">
                  <DollarSign className="w-6 h-6 text-green-400" />
                </div>
              </div>
              <p className="mt-4 text-2xl font-bold text-white">{formatCurrency(revenue.total_revenue)}</p>
              <p className="text-sm text-slate-400">Total Revenue</p>
            </div>

            <div className="bg-slate-800 rounded-2xl border border-slate-700 p-6">
              <div className="flex items-center justify-between">
                <div className="p-3 bg-indigo-500/20 rounded-xl">
                  <TrendingUp className="w-6 h-6 text-indigo-400" />
                </div>
                {monthlyChange !== 0 && (
                  <span className={`flex items-center text-sm ${monthlyChange > 0 ? 'text-green-400' : 'text-red-400'}`}>
                    {monthlyChange > 0 ? <ArrowUpRight className="w-4 h-4" /> : <ArrowDownRight className="w-4 h-4" />}
                    {Math.abs(monthlyChange).toFixed(1)}%
                  </span>
                )}
              </div>
              <p className="mt-4 text-2xl font-bold text-white">{formatCurrency(revenue.this_month)}</p>
              <p className="text-sm text-slate-400">This Month</p>
            </div>

            <div className="bg-slate-800 rounded-2xl border border-slate-700 p-6">
              <div className="flex items-center justify-between">
                <div className="p-3 bg-purple-500/20 rounded-xl">
                  <Calendar className="w-6 h-6 text-purple-400" />
                </div>
              </div>
              <p className="mt-4 text-2xl font-bold text-white">{formatCurrency(revenue.last_month)}</p>
              <p className="text-sm text-slate-400">Last Month</p>
            </div>

            <div className="bg-slate-800 rounded-2xl border border-slate-700 p-6">
              <div className="flex items-center justify-between">
                <div className="p-3 bg-yellow-500/20 rounded-xl">
                  <Users className="w-6 h-6 text-yellow-400" />
                </div>
              </div>
              <p className="mt-4 text-2xl font-bold text-white">{formatCurrency(revenue.pending_collection)}</p>
              <p className="text-sm text-slate-400">Pending Collection</p>
            </div>
          </div>

          {/* Current Fee Rate */}
          <div className="bg-slate-800 rounded-2xl border border-slate-700 p-6">
            <h3 className="text-lg font-semibold text-white mb-4">Current Fee Configuration</h3>
            <div className="grid gap-4 sm:grid-cols-3">
              <div className="bg-slate-700/50 rounded-xl p-4">
                <p className="text-3xl font-bold text-indigo-400">{settings.platform_fee_percentage}%</p>
                <p className="text-sm text-slate-400 mt-1">Platform Fee Rate</p>
              </div>
              <div className="bg-slate-700/50 rounded-xl p-4">
                <p className="text-3xl font-bold text-white">{formatCurrency(settings.minimum_fee)}</p>
                <p className="text-sm text-slate-400 mt-1">Minimum Fee</p>
              </div>
              <div className="bg-slate-700/50 rounded-xl p-4">
                <p className="text-3xl font-bold text-white">{settings.maximum_fee > 0 ? formatCurrency(settings.maximum_fee) : 'No Limit'}</p>
                <p className="text-sm text-slate-400 mt-1">Maximum Fee</p>
              </div>
            </div>
          </div>
        </div>
      )}

      {/* Settings Tab */}
      {activeTab === 'settings' && (
        <div className="bg-slate-800 rounded-2xl border border-slate-700">
          <div className="p-6 border-b border-slate-700">
            <h2 className="text-lg font-semibold text-white">Fee Settings</h2>
            <p className="text-sm text-slate-400 mt-1">Configure platform fee rates and collection methods</p>
          </div>
          <div className="p-6 space-y-6">
            <div className="grid gap-6 sm:grid-cols-2">
              <div>
                <label className="block text-sm font-medium text-slate-300 mb-2">
                  Platform Fee Percentage (%)
                </label>
                <input
                  type="number"
                  step="0.1"
                  min="0"
                  max="100"
                  value={settings.platform_fee_percentage}
                  onChange={(e) => setSettings({ ...settings, platform_fee_percentage: parseFloat(e.target.value) })}
                  className="w-full px-4 py-2.5 bg-slate-700 border border-slate-600 rounded-xl text-white focus:outline-none focus:ring-2 focus:ring-indigo-500"
                />
                <p className="text-xs text-slate-500 mt-1">Percentage charged on each transaction</p>
              </div>

              <div>
                <label className="block text-sm font-medium text-slate-300 mb-2">
                  Fee Collection Method
                </label>
                <select
                  value={settings.fee_collection_method}
                  onChange={(e) => setSettings({ ...settings, fee_collection_method: e.target.value })}
                  className="w-full px-4 py-2.5 bg-slate-700 border border-slate-600 rounded-xl text-white focus:outline-none focus:ring-2 focus:ring-indigo-500"
                >
                  <option value="automatic">Automatic (deduct from transactions)</option>
                  <option value="invoice">Invoice (monthly billing)</option>
                  <option value="manual">Manual Collection</option>
                </select>
              </div>

              <div>
                <label className="block text-sm font-medium text-slate-300 mb-2">
                  Minimum Fee (UGX)
                </label>
                <input
                  type="number"
                  min="0"
                  value={settings.minimum_fee}
                  onChange={(e) => setSettings({ ...settings, minimum_fee: parseInt(e.target.value) })}
                  className="w-full px-4 py-2.5 bg-slate-700 border border-slate-600 rounded-xl text-white focus:outline-none focus:ring-2 focus:ring-indigo-500"
                />
                <p className="text-xs text-slate-500 mt-1">Minimum fee per transaction (0 = no minimum)</p>
              </div>

              <div>
                <label className="block text-sm font-medium text-slate-300 mb-2">
                  Maximum Fee (UGX)
                </label>
                <input
                  type="number"
                  min="0"
                  value={settings.maximum_fee}
                  onChange={(e) => setSettings({ ...settings, maximum_fee: parseInt(e.target.value) })}
                  className="w-full px-4 py-2.5 bg-slate-700 border border-slate-600 rounded-xl text-white focus:outline-none focus:ring-2 focus:ring-indigo-500"
                />
                <p className="text-xs text-slate-500 mt-1">Maximum fee per transaction (0 = no maximum)</p>
              </div>
            </div>

            <div className="flex justify-end pt-4 border-t border-slate-700">
              <button
                onClick={handleSaveSettings}
                disabled={saving}
                className="flex items-center gap-2 px-6 py-2.5 bg-indigo-600 hover:bg-indigo-500 text-white rounded-xl transition-colors disabled:opacity-50"
              >
                <Save className="w-4 h-4" />
                {saving ? 'Saving...' : 'Save Settings'}
              </button>
            </div>
          </div>
        </div>
      )}

      {/* Balances Tab */}
      {activeTab === 'balances' && (
        <div className="bg-slate-800 rounded-2xl border border-slate-700 overflow-hidden">
          <div className="p-6 border-b border-slate-700">
            <h2 className="text-lg font-semibold text-white">Tenant Balances</h2>
            <p className="text-sm text-slate-400 mt-1">View fee balances by tenant</p>
          </div>
          <div className="overflow-x-auto">
            <table className="w-full">
              <thead>
                <tr className="border-b border-slate-700">
                  <th className="text-left px-6 py-4 text-sm font-medium text-slate-400">Tenant</th>
                  <th className="text-right px-6 py-4 text-sm font-medium text-slate-400">Transactions</th>
                  <th className="text-right px-6 py-4 text-sm font-medium text-slate-400">Total Fees</th>
                  <th className="text-right px-6 py-4 text-sm font-medium text-slate-400">Collected</th>
                  <th className="text-right px-6 py-4 text-sm font-medium text-slate-400">Pending</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-slate-700">
                {tenantBalances.length === 0 ? (
                  <tr>
                    <td colSpan={5} className="px-6 py-12 text-center text-slate-400">
                      No tenant balances found
                    </td>
                  </tr>
                ) : (
                  tenantBalances.map((balance) => (
                    <tr key={balance.tenant_id} className="hover:bg-slate-700/50">
                      <td className="px-6 py-4 text-white font-medium">{balance.tenant_name}</td>
                      <td className="px-6 py-4 text-right text-slate-300">{balance.total_transactions}</td>
                      <td className="px-6 py-4 text-right text-slate-300">{formatCurrency(balance.total_fees)}</td>
                      <td className="px-6 py-4 text-right text-green-400">{formatCurrency(balance.collected)}</td>
                      <td className="px-6 py-4 text-right text-yellow-400">{formatCurrency(balance.pending)}</td>
                    </tr>
                  ))
                )}
              </tbody>
            </table>
          </div>
        </div>
      )}
    </div>
  );
}
