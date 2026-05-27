import React, { useEffect, useState } from 'react';
import { useNavigate } from 'react-router';
import {
  Users,
  Search,
  Filter,
  MoreVertical,
  Eye,
  Edit,
  Trash2,
  Ban,
  CheckCircle,
  Clock,
  XCircle,
  RefreshCw,
  Wrench,
  ChevronLeft,
  ChevronRight,
  Key,
  Mail,
  Database,
  Copy,
  Check,
  Network,
} from 'lucide-react';

interface Tenant {
  id: number;
  name: string;
  slug: string;
  domain: string;
  status: 'pending' | 'approved' | 'rejected' | 'suspended';
  is_active: boolean;
  created_at: string;
  trial_ends_at?: string | null;
  subscription_ends_at?: string | null;
  sms_credits?: number;
  billing?: {
    state: 'active' | 'trial' | 'subscribed' | 'expired';
    requires_payment: boolean;
    current_period_ends_at?: string | null;
  };
  support_notes?: string | null;
  users?: { id: number; name: string; email: string }[];
  primary_email?: string;
  database?: string;
}

export default function TenantList() {
  const navigate = useNavigate();
  const [tenants, setTenants] = useState<Tenant[]>([]);
  const [loading, setLoading] = useState(true);
  const [searchQuery, setSearchQuery] = useState('');
  const [statusFilter, setStatusFilter] = useState<string>('all');
  const [currentPage, setCurrentPage] = useState(1);
  const [totalPages, setTotalPages] = useState(1);
  const [selectedTenant, setSelectedTenant] = useState<Tenant | null>(null);
  const [showEditModal, setShowEditModal] = useState(false);
  const [showResetPasswordModal, setShowResetPasswordModal] = useState(false);
  const [showRadiusModal, setShowRadiusModal] = useState(false);
  const [showRemoteAccessModal, setShowRemoteAccessModal] = useState(false);
  const [actionMenuOpen, setActionMenuOpen] = useState<number | null>(null);
  const [copiedField, setCopiedField] = useState<string | null>(null);
  const [radiusSettings, setRadiusSettings] = useState({
    radius_server_ip: '129.168.0.42',
    radius_auth_port: 1812,
    radius_acct_port: 1813,
  });

  useEffect(() => {
    fetchTenants();
    fetchRadiusSettings();
  }, [currentPage, statusFilter]);

  const fetchTenants = async () => {
    try {
      setLoading(true);
      const token = localStorage.getItem('admin_token');
      const params = new URLSearchParams();
      params.set('page', String(currentPage));
      if (statusFilter !== 'all') params.set('status', statusFilter);
      if (searchQuery) params.set('search', searchQuery);

      const response = await fetch(`/api/super-admin/tenants?${params}`, {
        headers: { 'Authorization': `Bearer ${token}` },
      });

      if (!response.ok) {
        if (response.status === 401) {
          navigate('/admin/login');
          return;
        }
        throw new Error('Failed to fetch tenants');
      }

      const data = await response.json();
      setTenants(data.data || data);
      setTotalPages(data.last_page || 1);
    } catch (error) {
      console.error('Error fetching tenants:', error);
    } finally {
      setLoading(false);
    }
  };

  const fetchRadiusSettings = async () => {
    try {
      const token = localStorage.getItem('admin_token');
      const response = await fetch('/api/super-admin/settings/group/radius', {
        headers: { 'Authorization': `Bearer ${token}` },
      });

      if (response.ok) {
        const data = await response.json();
        setRadiusSettings((current) => ({ ...current, ...data }));
      }
    } catch (error) {
      console.error('Error fetching RADIUS settings:', error);
    }
  };

  const handleSuspend = async (tenant: Tenant) => {
    if (!confirm(`Are you sure you want to suspend "${tenant.name}"?`)) return;
    
    try {
      const token = localStorage.getItem('admin_token');
      const response = await fetch(`/api/super-admin/tenants/${tenant.id}/suspend`, {
        method: 'POST',
        headers: { 'Authorization': `Bearer ${token}` },
      });

      if (response.ok) {
        fetchTenants();
      }
    } catch (error) {
      console.error('Error suspending tenant:', error);
    }
    setActionMenuOpen(null);
  };

  const handleActivate = async (tenant: Tenant) => {
    try {
      const token = localStorage.getItem('admin_token');
      const response = await fetch(`/api/super-admin/tenants/${tenant.id}/activate`, {
        method: 'POST',
        headers: { 'Authorization': `Bearer ${token}` },
      });

      if (response.ok) {
        fetchTenants();
      }
    } catch (error) {
      console.error('Error activating tenant:', error);
    }
    setActionMenuOpen(null);
  };

  const handleDelete = async (tenant: Tenant) => {
    if (!confirm(`Are you sure you want to DELETE "${tenant.name}"? This action cannot be undone.`)) return;
    
    try {
      const token = localStorage.getItem('admin_token');
      const response = await fetch(`/api/super-admin/tenants/${tenant.id}`, {
        method: 'DELETE',
        headers: { 'Authorization': `Bearer ${token}` },
      });

      if (response.ok) {
        fetchTenants();
      }
    } catch (error) {
      console.error('Error deleting tenant:', error);
    }
    setActionMenuOpen(null);
  };

  const handleRepair = async (tenant: Tenant) => {
    if (!confirm(`Run database repair and migrations for "${tenant.name}"?`)) return;

    try {
      const token = localStorage.getItem('admin_token');
      const response = await fetch(`/api/super-admin/tenants/${tenant.id}/repair`, {
        method: 'POST',
        headers: {
          'Authorization': `Bearer ${token}`,
          'Content-Type': 'application/json',
        },
        body: JSON.stringify({ activate: tenant.status !== 'approved' }),
      });
      const data = await response.json();

      if (response.ok) {
        alert(`${data.message}\nActions: ${(data.actions || []).join(', ') || 'none'}${data.warnings?.length ? `\nWarnings: ${data.warnings.join('; ')}` : ''}`);
        fetchTenants();
      } else {
        alert(data.message || 'Tenant repair failed');
      }
    } catch (error) {
      console.error('Error repairing tenant:', error);
      alert('Tenant repair failed');
    }

    setActionMenuOpen(null);
  };

  const handleExtendTrial = async (tenant: Tenant) => {
    const value = prompt(`Extend trial for "${tenant.name}" by how many days?`, '15');
    if (!value) return;

    const days = Number(value);
    if (!Number.isInteger(days) || days < 1) {
      alert('Enter a whole number of days');
      return;
    }

    try {
      const token = localStorage.getItem('admin_token');
      const response = await fetch(`/api/super-admin/tenants/${tenant.id}/extend-trial`, {
        method: 'POST',
        headers: {
          'Authorization': `Bearer ${token}`,
          'Content-Type': 'application/json',
        },
        body: JSON.stringify({ days }),
      });
      const data = await response.json();

      if (response.ok) {
        alert(data.message || 'Trial extended');
        fetchTenants();
      } else {
        alert(data.message || 'Failed to extend trial');
      }
    } catch (error) {
      console.error('Error extending trial:', error);
      alert('Failed to extend trial');
    }

    setActionMenuOpen(null);
  };

  const handleAdjustSmsCredits = async (tenant: Tenant) => {
    const value = prompt(`Add or remove SMS credits for "${tenant.name}". Use a negative number to deduct.`, '100');
    if (!value) return;

    const credits = Number(value);
    if (!Number.isInteger(credits)) {
      alert('Enter a whole number of credits');
      return;
    }

    try {
      const token = localStorage.getItem('admin_token');
      const response = await fetch(`/api/super-admin/tenants/${tenant.id}/sms-credits/adjust`, {
        method: 'POST',
        headers: {
          'Authorization': `Bearer ${token}`,
          'Content-Type': 'application/json',
        },
        body: JSON.stringify({ credits }),
      });
      const data = await response.json();

      if (response.ok) {
        alert(`SMS credits updated. New balance: ${data.credits}`);
        fetchTenants();
      } else {
        alert(data.message || 'Failed to adjust SMS credits');
      }
    } catch (error) {
      console.error('Error adjusting SMS credits:', error);
      alert('Failed to adjust SMS credits');
    }

    setActionMenuOpen(null);
  };

  const copyToClipboard = (text: string, field: string) => {
    navigator.clipboard.writeText(text);
    setCopiedField(field);
    setTimeout(() => setCopiedField(null), 2000);
  };

  const getRadiusInfo = (tenant: Tenant) => {
    const dbName = tenant.database || `tenant_${tenant.slug}`;
    // Generate a unique DB user for this tenant (convention: radius_<slug>)
    const dbUser = `radius_${tenant.slug.replace(/-/g, '_').substring(0, 16)}`;
    return {
      server: String(radiusSettings.radius_server_ip || '129.168.0.42'),
      auth_port: String(radiusSettings.radius_auth_port || 1812),
      acct_port: String(radiusSettings.radius_acct_port || 1813),
      port: '3306',
      database: dbName,
      db_user: dbUser,
      db_password: '<SET_PASSWORD_HERE>',
    };
  };

  const handleResetPassword = async (newPassword: string) => {
    if (!selectedTenant) return;
    
    try {
      const token = localStorage.getItem('admin_token');
      const response = await fetch(`/api/super-admin/tenants/${selectedTenant.id}/reset-password`, {
        method: 'POST',
        headers: {
          'Authorization': `Bearer ${token}`,
          'Content-Type': 'application/json',
        },
        body: JSON.stringify({ password: newPassword }),
      });

      if (response.ok) {
        alert('Password reset successfully');
        setShowResetPasswordModal(false);
        setSelectedTenant(null);
      }
    } catch (error) {
      console.error('Error resetting password:', error);
    }
  };

  const getStatusBadge = (status: string, isActive: boolean) => {
    if (!isActive && status === 'approved') {
      return (
        <span className="inline-flex items-center gap-1 px-2.5 py-1 rounded-full text-xs font-medium bg-red-500/20 text-red-400">
          <Ban className="w-3 h-3" /> Suspended
        </span>
      );
    }
    
    const badges: Record<string, { bg: string; text: string; icon: React.ReactNode }> = {
      pending: { bg: 'bg-yellow-500/20', text: 'text-yellow-400', icon: <Clock className="w-3 h-3" /> },
      approved: { bg: 'bg-green-500/20', text: 'text-green-400', icon: <CheckCircle className="w-3 h-3" /> },
      rejected: { bg: 'bg-red-500/20', text: 'text-red-400', icon: <XCircle className="w-3 h-3" /> },
      suspended: { bg: 'bg-red-500/20', text: 'text-red-400', icon: <Ban className="w-3 h-3" /> },
    };

    const badge = badges[status] || badges.pending;
    return (
      <span className={`inline-flex items-center gap-1 px-2.5 py-1 rounded-full text-xs font-medium ${badge.bg} ${badge.text}`}>
        {badge.icon} {status.charAt(0).toUpperCase() + status.slice(1)}
      </span>
    );
  };

  const getBillingBadge = (tenant: Tenant) => {
    const billing = tenant.billing;
    const state = billing?.state || 'expired';
    const labels: Record<string, string> = {
      trial: 'Trial',
      subscribed: 'Subscribed',
      expired: 'Expired',
      active: 'Active',
    };
    const styles: Record<string, string> = {
      trial: 'bg-blue-500/20 text-blue-300',
      subscribed: 'bg-emerald-500/20 text-emerald-300',
      expired: 'bg-red-500/20 text-red-300',
      active: 'bg-slate-500/20 text-slate-300',
    };

    return (
      <div className="space-y-1">
        <span className={`inline-flex items-center gap-1 px-2.5 py-1 rounded-full text-xs font-medium ${styles[state] || styles.active}`}>
          <Clock className="w-3 h-3" /> {labels[state] || state}
        </span>
        {billing?.current_period_ends_at && (
          <p className="text-xs text-slate-500">
            Ends {new Date(billing.current_period_ends_at).toLocaleDateString()}
          </p>
        )}
      </div>
    );
  };

  return (
    <div className="space-y-6">
      {/* Header */}
      <div className="flex flex-col sm:flex-row justify-between items-start sm:items-center gap-4">
        <div>
          <h1 className="text-2xl font-bold text-white">All Tenants</h1>
          <p className="text-slate-400 mt-1">Manage all tenant accounts</p>
        </div>
        <button
          onClick={fetchTenants}
          className="flex items-center gap-2 px-4 py-2 bg-slate-700 hover:bg-slate-600 text-white rounded-xl transition-colors"
        >
          <RefreshCw className={`w-4 h-4 ${loading ? 'animate-spin' : ''}`} />
          Refresh
        </button>
      </div>

      {/* Filters */}
      <div className="flex flex-col sm:flex-row gap-4">
        <div className="flex-1 relative">
          <Search className="absolute left-3 top-1/2 -translate-y-1/2 w-5 h-5 text-slate-400" />
          <input
            type="text"
            placeholder="Search tenants..."
            value={searchQuery}
            onChange={(e) => setSearchQuery(e.target.value)}
            onKeyDown={(e) => e.key === 'Enter' && fetchTenants()}
            className="w-full pl-10 pr-4 py-2.5 bg-slate-800 border border-slate-700 rounded-xl text-white placeholder-slate-400 focus:outline-none focus:ring-2 focus:ring-indigo-500"
          />
        </div>
        <select
          value={statusFilter}
          onChange={(e) => setStatusFilter(e.target.value)}
          className="px-4 py-2.5 bg-slate-800 border border-slate-700 rounded-xl text-white focus:outline-none focus:ring-2 focus:ring-indigo-500"
        >
          <option value="all">All Status</option>
          <option value="pending">Pending</option>
          <option value="approved">Approved</option>
          <option value="rejected">Rejected</option>
          <option value="suspended">Suspended</option>
        </select>
      </div>

      {/* Table */}
      <div className="bg-slate-800 rounded-2xl border border-slate-700 overflow-hidden">
        <div className="overflow-x-auto">
          <table className="w-full">
            <thead>
              <tr className="border-b border-slate-700">
                <th className="text-left px-6 py-4 text-sm font-medium text-slate-400">Tenant</th>
                <th className="text-left px-6 py-4 text-sm font-medium text-slate-400">Email</th>
                <th className="text-left px-6 py-4 text-sm font-medium text-slate-400">Database</th>
                <th className="text-left px-6 py-4 text-sm font-medium text-slate-400">Status</th>
                <th className="text-left px-6 py-4 text-sm font-medium text-slate-400">Billing</th>
                <th className="text-left px-6 py-4 text-sm font-medium text-slate-400">SMS</th>
                <th className="text-left px-6 py-4 text-sm font-medium text-slate-400">Created</th>
                <th className="text-right px-6 py-4 text-sm font-medium text-slate-400">Actions</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-slate-700">
              {loading ? (
                <tr>
                  <td colSpan={8} className="px-6 py-12 text-center">
                    <RefreshCw className="w-8 h-8 text-indigo-500 animate-spin mx-auto" />
                    <p className="mt-2 text-slate-400">Loading tenants...</p>
                  </td>
                </tr>
              ) : tenants.length === 0 ? (
                <tr>
                  <td colSpan={8} className="px-6 py-12 text-center">
                    <Users className="w-12 h-12 text-slate-600 mx-auto" />
                    <p className="mt-2 text-slate-400">No tenants found</p>
                  </td>
                </tr>
              ) : (
                tenants.map((tenant) => (
                  <tr key={tenant.id} className="hover:bg-slate-700/50 transition-colors">
                    <td className="px-6 py-4">
                      <div>
                        <p className="font-medium text-white">{tenant.name}</p>
                        <p className="text-sm text-slate-400">{tenant.slug}</p>
                      </div>
                    </td>
                    <td className="px-6 py-4">
                      <div className="flex items-center gap-2">
                        <Mail className="w-4 h-4 text-slate-500" />
                        <span className="text-slate-300 text-sm">{tenant.primary_email || tenant.users?.[0]?.email || '-'}</span>
                      </div>
                    </td>
                    <td className="px-6 py-4">
                      <span className="text-xs font-mono bg-slate-700 px-2 py-1 rounded text-slate-300">
                        {tenant.database || '-'}
                      </span>
                    </td>
                    <td className="px-6 py-4">{getStatusBadge(tenant.status, tenant.is_active)}</td>
                    <td className="px-6 py-4">{getBillingBadge(tenant)}</td>
                    <td className="px-6 py-4 text-slate-300">{Number(tenant.sms_credits || 0).toLocaleString()}</td>
                    <td className="px-6 py-4 text-slate-300">
                      {new Date(tenant.created_at).toLocaleDateString()}
                    </td>
                    <td className="px-6 py-4">
                      <div className="flex items-center justify-end gap-2 relative">
                        <button
                          onClick={() => setActionMenuOpen(actionMenuOpen === tenant.id ? null : tenant.id)}
                          className="p-2 text-slate-400 hover:bg-slate-600 rounded-lg transition-colors"
                        >
                          <MoreVertical className="w-5 h-5" />
                        </button>
                        
                        {actionMenuOpen === tenant.id && (
                          <div className="absolute right-0 top-full mt-1 w-48 bg-slate-700 rounded-xl shadow-xl border border-slate-600 z-10 overflow-hidden">
                            <button
                              onClick={() => {
                                setSelectedTenant(tenant);
                                setShowEditModal(true);
                                setActionMenuOpen(null);
                              }}
                              className="w-full flex items-center gap-2 px-4 py-2.5 text-sm text-slate-200 hover:bg-slate-600 transition-colors"
                            >
                              <Edit className="w-4 h-4" /> Edit Details
                            </button>
                            <button
                              onClick={() => {
                                setSelectedTenant(tenant);
                                setShowResetPasswordModal(true);
                                setActionMenuOpen(null);
                              }}
                              className="w-full flex items-center gap-2 px-4 py-2.5 text-sm text-slate-200 hover:bg-slate-600 transition-colors"
                            >
                              <Key className="w-4 h-4" /> Reset Password
                            </button>
                            <button
                              onClick={() => {
                                setSelectedTenant(tenant);
                                setShowRadiusModal(true);
                                setActionMenuOpen(null);
                              }}
                              className="w-full flex items-center gap-2 px-4 py-2.5 text-sm text-indigo-400 hover:bg-slate-600 transition-colors"
                            >
                              <Database className="w-4 h-4" /> RADIUS Info
                            </button>
                            <button
                              onClick={() => {
                                setSelectedTenant(tenant);
                                setShowRemoteAccessModal(true);
                                setActionMenuOpen(null);
                              }}
                              className="w-full flex items-center gap-2 px-4 py-2.5 text-sm text-sky-400 hover:bg-slate-600 transition-colors"
                            >
                              <Network className="w-4 h-4" /> Remote Access
                            </button>
                            <button
                              onClick={() => handleRepair(tenant)}
                              className="w-full flex items-center gap-2 px-4 py-2.5 text-sm text-cyan-400 hover:bg-slate-600 transition-colors"
                            >
                              <Wrench className="w-4 h-4" /> Repair Tenant
                            </button>
                            <button
                              onClick={() => handleExtendTrial(tenant)}
                              className="w-full flex items-center gap-2 px-4 py-2.5 text-sm text-blue-400 hover:bg-slate-600 transition-colors"
                            >
                              <Clock className="w-4 h-4" /> Extend Trial
                            </button>
                            <button
                              onClick={() => handleAdjustSmsCredits(tenant)}
                              className="w-full flex items-center gap-2 px-4 py-2.5 text-sm text-emerald-400 hover:bg-slate-600 transition-colors"
                            >
                              <Mail className="w-4 h-4" /> Adjust SMS Credits
                            </button>
                            {tenant.is_active ? (
                              <button
                                onClick={() => handleSuspend(tenant)}
                                className="w-full flex items-center gap-2 px-4 py-2.5 text-sm text-yellow-400 hover:bg-slate-600 transition-colors"
                              >
                                <Ban className="w-4 h-4" /> Suspend
                              </button>
                            ) : (
                              <button
                                onClick={() => handleActivate(tenant)}
                                className="w-full flex items-center gap-2 px-4 py-2.5 text-sm text-green-400 hover:bg-slate-600 transition-colors"
                              >
                                <CheckCircle className="w-4 h-4" /> Activate
                              </button>
                            )}
                            <button
                              onClick={() => handleDelete(tenant)}
                              className="w-full flex items-center gap-2 px-4 py-2.5 text-sm text-red-400 hover:bg-slate-600 transition-colors"
                            >
                              <Trash2 className="w-4 h-4" /> Delete
                            </button>
                          </div>
                        )}
                      </div>
                    </td>
                  </tr>
                ))
              )}
            </tbody>
          </table>
        </div>

        {/* Pagination */}
        {totalPages > 1 && (
          <div className="flex items-center justify-between px-6 py-4 border-t border-slate-700">
            <p className="text-sm text-slate-400">
              Page {currentPage} of {totalPages}
            </p>
            <div className="flex items-center gap-2">
              <button
                onClick={() => setCurrentPage((p) => Math.max(1, p - 1))}
                disabled={currentPage === 1}
                className="p-2 text-slate-400 hover:bg-slate-700 rounded-lg disabled:opacity-50 disabled:cursor-not-allowed"
              >
                <ChevronLeft className="w-5 h-5" />
              </button>
              <button
                onClick={() => setCurrentPage((p) => Math.min(totalPages, p + 1))}
                disabled={currentPage === totalPages}
                className="p-2 text-slate-400 hover:bg-slate-700 rounded-lg disabled:opacity-50 disabled:cursor-not-allowed"
              >
                <ChevronRight className="w-5 h-5" />
              </button>
            </div>
          </div>
        )}
      </div>

      {/* Edit Modal */}
      {showEditModal && selectedTenant && (
        <EditTenantModal
          tenant={selectedTenant}
          onClose={() => {
            setShowEditModal(false);
            setSelectedTenant(null);
          }}
          onSave={() => {
            setShowEditModal(false);
            setSelectedTenant(null);
            fetchTenants();
          }}
        />
      )}

      {/* Reset Password Modal */}
      {showResetPasswordModal && selectedTenant && (
        <ResetPasswordModal
          tenant={selectedTenant}
          onClose={() => {
            setShowResetPasswordModal(false);
            setSelectedTenant(null);
          }}
          onReset={handleResetPassword}
        />
      )}

      {/* RADIUS Info Modal */}
      {showRadiusModal && selectedTenant && (
        <RadiusInfoModal
          tenant={selectedTenant}
          onClose={() => {
            setShowRadiusModal(false);
            setSelectedTenant(null);
          }}
          radiusInfo={getRadiusInfo(selectedTenant)}
          copyToClipboard={copyToClipboard}
          copiedField={copiedField}
        />
      )}

      {showRemoteAccessModal && selectedTenant && (
        <RemoteAccessModal
          tenant={selectedTenant}
          onClose={() => {
            setShowRemoteAccessModal(false);
            setSelectedTenant(null);
          }}
        />
      )}
    </div>
  );
}

function EditTenantModal({ tenant, onClose, onSave }: { tenant: Tenant; onClose: () => void; onSave: () => void }) {
  const [formData, setFormData] = useState({
    name: tenant.name,
    domain: tenant.domain || '',
    trial_ends_at: tenant.trial_ends_at ? tenant.trial_ends_at.slice(0, 10) : '',
    subscription_ends_at: tenant.subscription_ends_at ? tenant.subscription_ends_at.slice(0, 10) : '',
    support_notes: tenant.support_notes || '',
  });
  const [saving, setSaving] = useState(false);

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    setSaving(true);

    try {
      const token = localStorage.getItem('admin_token');
      const response = await fetch(`/api/super-admin/tenants/${tenant.id}`, {
        method: 'PUT',
        headers: {
          'Authorization': `Bearer ${token}`,
          'Content-Type': 'application/json',
        },
        body: JSON.stringify({
          ...formData,
          trial_ends_at: formData.trial_ends_at || null,
          subscription_ends_at: formData.subscription_ends_at || null,
        }),
      });

      if (response.ok) {
        onSave();
      } else {
        const data = await response.json();
        alert(data.message || 'Failed to update tenant');
      }
    } catch (error) {
      console.error('Error updating tenant:', error);
      alert('Failed to update tenant');
    } finally {
      setSaving(false);
    }
  };

  return (
    <div className="fixed inset-0 bg-black/60 flex items-center justify-center z-50 p-4">
      <div className="bg-slate-800 rounded-2xl w-full max-w-md border border-slate-700">
        <div className="p-6 border-b border-slate-700">
          <h2 className="text-xl font-bold text-white">Edit Tenant</h2>
          <p className="text-sm text-slate-400 mt-1">Update tenant information</p>
        </div>
        <form onSubmit={handleSubmit} className="p-6 space-y-4">
          <div>
            <label className="block text-sm font-medium text-slate-300 mb-2">Tenant Name</label>
            <input
              type="text"
              value={formData.name}
              onChange={(e) => setFormData({ ...formData, name: e.target.value })}
              className="w-full px-4 py-2.5 bg-slate-700 border border-slate-600 rounded-xl text-white focus:outline-none focus:ring-2 focus:ring-indigo-500"
              required
            />
          </div>
          <div>
            <label className="block text-sm font-medium text-slate-300 mb-2">Domain</label>
            <input
              type="text"
              value={formData.domain}
              onChange={(e) => setFormData({ ...formData, domain: e.target.value })}
              className="w-full px-4 py-2.5 bg-slate-700 border border-slate-600 rounded-xl text-white focus:outline-none focus:ring-2 focus:ring-indigo-500"
              placeholder="example.com"
            />
          </div>
          <div>
            <label className="block text-sm font-medium text-slate-300 mb-2">Support Notes</label>
            <textarea
              value={formData.support_notes}
              onChange={(e) => setFormData({ ...formData, support_notes: e.target.value })}
              className="w-full px-4 py-2.5 bg-slate-700 border border-slate-600 rounded-xl text-white focus:outline-none focus:ring-2 focus:ring-indigo-500"
              rows={4}
              placeholder="Internal notes about this tenant"
            />
          </div>
          <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
            <div>
              <label className="block text-sm font-medium text-slate-300 mb-2">Trial Ends</label>
              <input
                type="date"
                value={formData.trial_ends_at}
                onChange={(e) => setFormData({ ...formData, trial_ends_at: e.target.value })}
                className="w-full px-4 py-2.5 bg-slate-700 border border-slate-600 rounded-xl text-white focus:outline-none focus:ring-2 focus:ring-indigo-500"
              />
            </div>
            <div>
              <label className="block text-sm font-medium text-slate-300 mb-2">Subscription Ends</label>
              <input
                type="date"
                value={formData.subscription_ends_at}
                onChange={(e) => setFormData({ ...formData, subscription_ends_at: e.target.value })}
                className="w-full px-4 py-2.5 bg-slate-700 border border-slate-600 rounded-xl text-white focus:outline-none focus:ring-2 focus:ring-indigo-500"
              />
            </div>
          </div>
          <div className="flex gap-3 pt-4">
            <button
              type="button"
              onClick={onClose}
              className="flex-1 px-4 py-2.5 bg-slate-700 hover:bg-slate-600 text-white rounded-xl transition-colors"
            >
              Cancel
            </button>
            <button
              type="submit"
              disabled={saving}
              className="flex-1 px-4 py-2.5 bg-indigo-600 hover:bg-indigo-500 text-white rounded-xl transition-colors disabled:opacity-50"
            >
              {saving ? 'Saving...' : 'Save Changes'}
            </button>
          </div>
        </form>
      </div>
    </div>
  );
}

function ResetPasswordModal({ tenant, onClose, onReset }: { tenant: Tenant; onClose: () => void; onReset: (password: string) => void }) {
  const [password, setPassword] = useState('');
  const [confirmPassword, setConfirmPassword] = useState('');

  const handleSubmit = (e: React.FormEvent) => {
    e.preventDefault();
    if (password !== confirmPassword) {
      alert('Passwords do not match');
      return;
    }
    if (password.length < 8) {
      alert('Password must be at least 8 characters');
      return;
    }
    onReset(password);
  };

  return (
    <div className="fixed inset-0 bg-black/60 flex items-center justify-center z-50 p-4">
      <div className="bg-slate-800 rounded-2xl w-full max-w-md border border-slate-700">
        <div className="p-6 border-b border-slate-700">
          <h2 className="text-xl font-bold text-white">Reset Password</h2>
          <p className="text-sm text-slate-400 mt-1">Reset password for {tenant.name}</p>
        </div>
        <form onSubmit={handleSubmit} className="p-6 space-y-4">
          <div>
            <label className="block text-sm font-medium text-slate-300 mb-2">New Password</label>
            <input
              type="password"
              value={password}
              onChange={(e) => setPassword(e.target.value)}
              className="w-full px-4 py-2.5 bg-slate-700 border border-slate-600 rounded-xl text-white focus:outline-none focus:ring-2 focus:ring-indigo-500"
              required
              minLength={8}
            />
          </div>
          <div>
            <label className="block text-sm font-medium text-slate-300 mb-2">Confirm Password</label>
            <input
              type="password"
              value={confirmPassword}
              onChange={(e) => setConfirmPassword(e.target.value)}
              className="w-full px-4 py-2.5 bg-slate-700 border border-slate-600 rounded-xl text-white focus:outline-none focus:ring-2 focus:ring-indigo-500"
              required
              minLength={8}
            />
          </div>
          <div className="flex gap-3 pt-4">
            <button
              type="button"
              onClick={onClose}
              className="flex-1 px-4 py-2.5 bg-slate-700 hover:bg-slate-600 text-white rounded-xl transition-colors"
            >
              Cancel
            </button>
            <button
              type="submit"
              className="flex-1 px-4 py-2.5 bg-red-600 hover:bg-red-500 text-white rounded-xl transition-colors"
            >
              Reset Password
            </button>
          </div>
        </form>
      </div>
    </div>
  );
}

function RemoteAccessModal({ tenant, onClose }: { tenant: Tenant; onClose: () => void }) {
  const [loading, setLoading] = useState(true);
  const [savingId, setSavingId] = useState<number | null>(null);
  const [data, setData] = useState<any>(null);
  const [forms, setForms] = useState<Record<number, any>>({});

  const load = async () => {
    setLoading(true);
    try {
      const token = localStorage.getItem('admin_token');
      const response = await fetch(`/api/super-admin/tenants/${tenant.id}/remote-access`, {
        headers: { 'Authorization': `Bearer ${token}`, 'Accept': 'application/json' },
      });
      if (response.ok) {
        const payload = await response.json();
        setData(payload);
        const nextForms: Record<number, any> = {};
        (payload.sites || []).forEach((site: any) => {
          nextForms[site.id] = {
            vpn_private_ip: site.vpn_private_ip || '',
            vpn_username: site.vpn_username || '',
            vpn_status: site.vpn_status || 'pending',
            router_api_port: site.router_api_port || 8728,
            remote_access_notes: site.remote_access_notes || '',
          };
        });
        setForms(nextForms);
      }
    } finally {
      setLoading(false);
    }
  };

  useEffect(() => {
    load();
  }, [tenant.id]);

  const save = async (siteId: number) => {
    setSavingId(siteId);
    try {
      const token = localStorage.getItem('admin_token');
      const response = await fetch(`/api/super-admin/tenants/${tenant.id}/remote-access/${siteId}`, {
        method: 'PUT',
        headers: {
          'Authorization': `Bearer ${token}`,
          'Content-Type': 'application/json',
          'Accept': 'application/json',
        },
        body: JSON.stringify(forms[siteId]),
      });

      if (!response.ok) {
        const payload = await response.json();
        alert(payload.message || payload.error || 'Failed to update remote access details');
      } else {
        await load();
      }
    } finally {
      setSavingId(null);
    }
  };

  return (
    <div className="fixed inset-0 bg-black/60 flex items-center justify-center z-50 p-4">
      <div className="bg-slate-800 rounded-2xl w-full max-w-4xl border border-slate-700 max-h-[90vh] overflow-hidden flex flex-col">
        <div className="p-6 border-b border-slate-700 flex items-start justify-between gap-4">
          <div>
            <h2 className="text-xl font-bold text-white">Remote Access</h2>
            <p className="text-sm text-slate-400 mt-1">{tenant.name} - SSTP range {data?.vpn_range || '10.10.1.0/24'}</p>
          </div>
          <button onClick={onClose} className="px-4 py-2 bg-slate-700 hover:bg-slate-600 text-white rounded-xl">Close</button>
        </div>
        <div className="p-6 overflow-y-auto space-y-4">
          {loading ? (
            <div className="py-10 text-center text-slate-400">Loading remote access details...</div>
          ) : (data?.sites || []).length === 0 ? (
            <div className="py-10 text-center text-slate-400">No sites found for this tenant.</div>
          ) : (data.sites || []).map((site: any) => (
            <div key={site.id} className="rounded-xl border border-slate-700 p-4 space-y-4">
              <div>
                <h3 className="font-semibold text-white">{site.name}</h3>
                <p className="text-xs text-slate-400">{site.slug}</p>
              </div>
              <div className="grid md:grid-cols-4 gap-3">
                <label className="block text-sm">
                  <span className="text-slate-300">VPN private IP</span>
                  <input value={forms[site.id]?.vpn_private_ip || ''} onChange={(e) => setForms({ ...forms, [site.id]: { ...forms[site.id], vpn_private_ip: e.target.value } })} placeholder="10.10.1.10" className="mt-1 w-full px-3 py-2 bg-slate-700 border border-slate-600 rounded-lg text-white" />
                </label>
                <label className="block text-sm">
                  <span className="text-slate-300">VPN username</span>
                  <input value={forms[site.id]?.vpn_username || ''} onChange={(e) => setForms({ ...forms, [site.id]: { ...forms[site.id], vpn_username: e.target.value } })} placeholder={site.slug} className="mt-1 w-full px-3 py-2 bg-slate-700 border border-slate-600 rounded-lg text-white" />
                </label>
                <label className="block text-sm">
                  <span className="text-slate-300">Status</span>
                  <select value={forms[site.id]?.vpn_status || 'pending'} onChange={(e) => setForms({ ...forms, [site.id]: { ...forms[site.id], vpn_status: e.target.value } })} className="mt-1 w-full px-3 py-2 bg-slate-700 border border-slate-600 rounded-lg text-white">
                    <option value="pending">Pending</option>
                    <option value="active">Active</option>
                    <option value="offline">Offline</option>
                    <option value="suspended">Suspended</option>
                  </select>
                </label>
                <label className="block text-sm">
                  <span className="text-slate-300">API port</span>
                  <input type="number" value={forms[site.id]?.router_api_port || 8728} onChange={(e) => setForms({ ...forms, [site.id]: { ...forms[site.id], router_api_port: Number(e.target.value) } })} className="mt-1 w-full px-3 py-2 bg-slate-700 border border-slate-600 rounded-lg text-white" />
                </label>
              </div>
              <label className="block text-sm">
                <span className="text-slate-300">SoftEther notes</span>
                <textarea value={forms[site.id]?.remote_access_notes || ''} onChange={(e) => setForms({ ...forms, [site.id]: { ...forms[site.id], remote_access_notes: e.target.value } })} rows={2} className="mt-1 w-full px-3 py-2 bg-slate-700 border border-slate-600 rounded-lg text-white" />
              </label>
              <div className="flex justify-end">
                <button onClick={() => save(site.id)} disabled={savingId === site.id} className="px-4 py-2 bg-indigo-600 hover:bg-indigo-500 text-white rounded-lg disabled:opacity-60">
                  {savingId === site.id ? 'Saving...' : 'Save Remote Access'}
                </button>
              </div>
            </div>
          ))}
        </div>
      </div>
    </div>
  );
}

function RadiusInfoModal({ 
  tenant, 
  onClose, 
  radiusInfo, 
  copyToClipboard, 
  copiedField 
}: { 
  tenant: Tenant; 
  onClose: () => void; 
  radiusInfo: Record<string, string>; 
  copyToClipboard: (text: string, field: string) => void;
  copiedField: string | null;
}) {
  const [activeTab, setActiveTab] = useState<'config' | 'sql'>('config');

  const CopyButton = ({ value, field }: { value: string; field: string }) => (
    <button
      onClick={() => copyToClipboard(value, field)}
      className="p-1.5 hover:bg-slate-600 rounded transition-colors"
      title="Copy to clipboard"
    >
      {copiedField === field ? (
        <Check className="w-4 h-4 text-green-400" />
      ) : (
        <Copy className="w-4 h-4 text-slate-400" />
      )}
    </button>
  );

  // Generate the actual FreeRADIUS sql.conf content
  const sqlConfContent = `# FreeRADIUS SQL Configuration for ${tenant.name}
# Add this to /etc/freeradius/3.0/mods-available/sql

sql {
    driver = "rlm_sql_mysql"
    dialect = "mysql"
    
    # Connection info
    server = "${radiusInfo.server}"
    port = ${radiusInfo.port}
    login = "${radiusInfo.db_user}"
    password = "${radiusInfo.db_password}"
    
    # Database
    radius_db = "${radiusInfo.database}"
    
    # Table configuration
    read_clients = yes
    client_table = "nas"
    
    # Query configuration
    mysql {
        warnings = auto
    }
}`;

  // Generate the SQL queries for RADIUS tables
  const sqlQueriesContent = `-- FreeRADIUS SQL Queries for ${tenant.name}
-- These queries authenticate vouchers from the tenant database

-- Authorization Query (checks if voucher exists and is valid)
authorize_check_query = "\\
    SELECT id, voucher_code AS username, 'Cleartext-Password' AS attribute, \\
           password AS value, ':=' AS op \\
    FROM vouchers \\
    WHERE voucher_code = '%{SQL-User-Name}' \\
    AND status = 'unused' \\
    AND (expires_at IS NULL OR expires_at > NOW())"

-- Group membership query
authorize_group_check_query = "\\
    SELECT vg.profile_name AS groupname \\
    FROM vouchers v \\
    JOIN voucher_groups vg ON v.group_id = vg.id \\
    WHERE v.voucher_code = '%{SQL-User-Name}'"

-- Post-Auth query (mark voucher as used on successful auth)
post_auth_query = "\\
    UPDATE vouchers \\
    SET status = 'used', \\
        used_at = NOW(), \\
        first_used_at = COALESCE(first_used_at, NOW()) \\
    WHERE voucher_code = '%{SQL-User-Name}' \\
    AND status = 'unused'"

-- Accounting Start query
accounting_start_query = "\\
    INSERT INTO radacct \\
    (acctsessionid, acctuniqueid, username, nasipaddress, \\
     nasportid, acctstarttime, acctupdatetime, acctstoptime, \\
     acctsessiontime, acctinputoctets, acctoutputoctets, \\
     calledstationid, callingstationid, servicetype, \\
     framedprotocol, framedipaddress) \\
    VALUES \\
    ('%{Acct-Session-Id}', '%{Acct-Unique-Session-Id}', '%{SQL-User-Name}', \\
     '%{NAS-IP-Address}', '%{NAS-Port}', NOW(), NOW(), NULL, \\
     0, 0, 0, '%{Called-Station-Id}', '%{Calling-Station-Id}', \\
     '%{Service-Type}', '%{Framed-Protocol}', '%{Framed-IP-Address}')"

-- Accounting Stop query
accounting_stop_query = "\\
    UPDATE radacct \\
    SET acctstoptime = NOW(), \\
        acctsessiontime = '%{Acct-Session-Time}', \\
        acctinputoctets = '%{Acct-Input-Octets}', \\
        acctoutputoctets = '%{Acct-Output-Octets}', \\
        acctterminatecause = '%{Acct-Terminate-Cause}' \\
    WHERE acctsessionid = '%{Acct-Session-Id}' \\
    AND acctuniqueid = '%{Acct-Unique-Session-Id}'"`;

  const downloadConfig = (content: string, filename: string) => {
    const blob = new Blob([content], { type: 'text/plain' });
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = filename;
    a.click();
    URL.revokeObjectURL(url);
  };

  return (
    <div className="fixed inset-0 bg-black/60 flex items-center justify-center z-50 p-4">
      <div className="bg-slate-800 rounded-2xl w-full max-w-3xl border border-slate-700 max-h-[90vh] overflow-hidden flex flex-col">
        <div className="p-6 border-b border-slate-700 flex items-center gap-3">
          <div className="w-10 h-10 bg-indigo-500/20 rounded-xl flex items-center justify-center">
            <Database className="w-5 h-5 text-indigo-400" />
          </div>
          <div className="flex-1">
            <h2 className="text-xl font-bold text-white">RADIUS Configuration</h2>
            <p className="text-sm text-slate-400">FreeRADIUS settings for {tenant.name}</p>
          </div>
        </div>

        {/* Tabs */}
        <div className="flex border-b border-slate-700">
          <button
            onClick={() => setActiveTab('config')}
            className={`flex-1 px-4 py-3 text-sm font-medium transition-colors ${
              activeTab === 'config'
                ? 'text-indigo-400 border-b-2 border-indigo-400 bg-slate-700/30'
                : 'text-slate-400 hover:text-white'
            }`}
          >
            SQL Module Config
          </button>
          <button
            onClick={() => setActiveTab('sql')}
            className={`flex-1 px-4 py-3 text-sm font-medium transition-colors ${
              activeTab === 'sql'
                ? 'text-indigo-400 border-b-2 border-indigo-400 bg-slate-700/30'
                : 'text-slate-400 hover:text-white'
            }`}
          >
            SQL Queries
          </button>
        </div>
        
        <div className="p-6 overflow-y-auto flex-1">
          {activeTab === 'config' && (
            <div className="space-y-4">
              <div className="flex items-center justify-between">
                <h3 className="text-sm font-semibold text-slate-300">
                  /etc/freeradius/3.0/mods-available/sql
                </h3>
                <div className="flex gap-2">
                  <button
                    onClick={() => copyToClipboard(sqlConfContent, 'sqlconf')}
                    className="flex items-center gap-1 px-3 py-1.5 text-xs bg-slate-700 hover:bg-slate-600 text-white rounded-lg transition-colors"
                  >
                    {copiedField === 'sqlconf' ? <Check className="w-3 h-3" /> : <Copy className="w-3 h-3" />}
                    Copy
                  </button>
                  <button
                    onClick={() => downloadConfig(sqlConfContent, `sql-${tenant.slug}.conf`)}
                    className="flex items-center gap-1 px-3 py-1.5 text-xs bg-indigo-600 hover:bg-indigo-500 text-white rounded-lg transition-colors"
                  >
                    Download
                  </button>
                </div>
              </div>
              <pre className="bg-slate-900 rounded-xl p-4 text-xs font-mono text-green-400 overflow-x-auto whitespace-pre-wrap">
                {sqlConfContent}
              </pre>
              
              <div className="bg-yellow-500/10 border border-yellow-500/30 rounded-xl p-4 mt-4">
                <p className="text-sm text-yellow-300">
                  <strong>Setup Instructions:</strong>
                </p>
                <ol className="text-xs text-yellow-200 mt-2 space-y-1 list-decimal list-inside">
                  <li>Copy this configuration to <code className="bg-slate-700 px-1 rounded">/etc/freeradius/3.0/mods-available/sql</code></li>
                  <li>Enable the module: <code className="bg-slate-700 px-1 rounded">ln -s ../mods-available/sql /etc/freeradius/3.0/mods-enabled/</code></li>
                  <li>Restart FreeRADIUS: <code className="bg-slate-700 px-1 rounded">systemctl restart freeradius</code></li>
                </ol>
              </div>
            </div>
          )}

          {activeTab === 'sql' && (
            <div className="space-y-4">
              <div className="flex items-center justify-between">
                <h3 className="text-sm font-semibold text-slate-300">
                  SQL Queries for Voucher Authentication
                </h3>
                <div className="flex gap-2">
                  <button
                    onClick={() => copyToClipboard(sqlQueriesContent, 'sqlqueries')}
                    className="flex items-center gap-1 px-3 py-1.5 text-xs bg-slate-700 hover:bg-slate-600 text-white rounded-lg transition-colors"
                  >
                    {copiedField === 'sqlqueries' ? <Check className="w-3 h-3" /> : <Copy className="w-3 h-3" />}
                    Copy
                  </button>
                  <button
                    onClick={() => downloadConfig(sqlQueriesContent, `queries-${tenant.slug}.conf`)}
                    className="flex items-center gap-1 px-3 py-1.5 text-xs bg-indigo-600 hover:bg-indigo-500 text-white rounded-lg transition-colors"
                  >
                    Download
                  </button>
                </div>
              </div>
              <pre className="bg-slate-900 rounded-xl p-4 text-xs font-mono text-green-400 overflow-x-auto whitespace-pre-wrap">
                {sqlQueriesContent}
              </pre>

              <div className="bg-indigo-500/10 border border-indigo-500/30 rounded-xl p-4 mt-4">
                <p className="text-sm text-indigo-300">
                  <strong>Note:</strong> Add these queries to your <code className="bg-slate-700 px-1 rounded">queries.conf</code> file to enable voucher authentication and accounting.
                </p>
              </div>
            </div>
          )}
        </div>

        <div className="p-6 border-t border-slate-700 flex gap-3">
          <button
            onClick={onClose}
            className="flex-1 px-4 py-2.5 bg-slate-700 hover:bg-slate-600 text-white rounded-xl transition-colors"
          >
            Close
          </button>
        </div>
      </div>
    </div>
  );
}
