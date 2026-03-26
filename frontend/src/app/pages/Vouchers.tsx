import { useState, useEffect } from 'react';
import { Ticket, Plus, Users, TrendingUp, Package, Filter, Search, Download, Printer, Trash2, ChevronRight, X } from 'lucide-react';
import { CreateVoucherDialog } from '../components/CreateVoucherDialog';
import { VoucherGroupCard } from '../components/VoucherGroupCard';
import { SalesPointsDialog } from '../components/SalesPointsDialog';

interface VoucherGroup {
  id: number;
  group_name: string;
  description: string;
  profile_name: string;
  validity_hours: number;
  data_limit_mb: number | null;
  speed_limit_kbps: number | null;
  price: number;
  sales_point_id: number | null;
  sales_point_name: string | null;
  created_by: string;
  created_at: string;
  total_vouchers: number;
  unused_count: number;
  used_count: number;
}

interface VoucherStats {
  overall: {
    total_vouchers: number;
    unused: number;
    used: number;
    expired: number;
    total_revenue: number;
  };
  daily: Array<{
    date: string;
    vouchers_used: number;
    revenue: number;
    unique_devices: number;
  }>;
  by_sales_point: Array<{
    name: string;
    total_vouchers: number;
    used: number;
    revenue: number;
  }>;
}

export function Vouchers() {
  const [groups, setGroups] = useState<VoucherGroup[]>([]);
  const [stats, setStats] = useState<VoucherStats | null>(null);
  const [loading, setLoading] = useState(true);
  const [showCreateDialog, setShowCreateDialog] = useState(false);
  const [showSalesPointsDialog, setShowSalesPointsDialog] = useState(false);
  const [selectedGroup, setSelectedGroup] = useState<VoucherGroup | null>(null);
  const [selectedSalesPoint, setSelectedSalesPoint] = useState<string | null>(null);
  const [deletingGroupId, setDeletingGroupId] = useState<number | null>(null);

  useEffect(() => {
    loadData();
  }, []);

  const loadData = async () => {
    try {
      setLoading(true);
      const token = localStorage.getItem('tenant_token') || localStorage.getItem('admin_token');
      const headers: HeadersInit = {
        'Content-Type': 'application/json',
        'Accept': 'application/json',
      };
      if (token) headers['Authorization'] = `Bearer ${token}`;

      const [groupsRes, statsRes] = await Promise.all([
        fetch('/api/vouchers/groups', { headers }),
        fetch('/api/vouchers/statistics', { headers })
      ]);

      if (groupsRes.ok) {
        const groupsData = await groupsRes.json();
        setGroups(Array.isArray(groupsData) ? groupsData : groupsData.groups || []);
      }
      if (statsRes.ok) {
        const statsData = await statsRes.json();
        setStats({
          overall: {
            total_vouchers: statsData.total_vouchers || 0,
            unused: statsData.unused_vouchers || 0,
            used: statsData.used_vouchers || 0,
            expired: statsData.expired_vouchers || 0,
            total_revenue: statsData.total_revenue || 0,
          },
          daily: statsData.daily || [],
          by_sales_point: statsData.by_sales_point || [],
        });
      }
    } catch (error) {
      console.error('Failed to load vouchers:', error);
    } finally {
      setLoading(false);
    }
  };

  const handleVoucherCreated = () => {
    setShowCreateDialog(false);
    loadData();
  };

  const formatCurrency = (amount: number) => {
    return `UGX ${amount.toLocaleString()}`;
  };

  const handleDeleteGroup = async (groupId: number) => {
    if (!confirm('Are you sure you want to delete this voucher group? All vouchers in this group will be deleted.')) return;
    
    setDeletingGroupId(groupId);
    try {
      const token = localStorage.getItem('tenant_token') || localStorage.getItem('admin_token');
      const headers: HeadersInit = {
        'Content-Type': 'application/json',
        'Accept': 'application/json',
      };
      if (token) headers['Authorization'] = `Bearer ${token}`;

      const res = await fetch(`/api/vouchers/groups/${groupId}`, {
        method: 'DELETE',
        headers,
      });

      if (res.ok) {
        loadData();
      } else {
        const error = await res.json();
        alert(error.message || 'Failed to delete group');
      }
    } catch (error) {
      console.error('Failed to delete group:', error);
      alert('Failed to delete group');
    } finally {
      setDeletingGroupId(null);
    }
  };

  // Filter groups by selected sales point
  const filteredGroups = selectedSalesPoint
    ? groups.filter(g => g.sales_point_name === selectedSalesPoint)
    : groups;

  if (loading) {
    return (
      <div className="flex items-center justify-center h-64">
        <Ticket className="w-6 h-6 text-primary animate-spin" />
      </div>
    );
  }

  return (
    <div className="p-4 sm:p-6 lg:p-8">
      {/* Header */}
      <div className="mb-6 sm:mb-8 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
        <div>
          <h1 className="text-2xl sm:text-3xl text-foreground mb-1 flex items-center gap-2">
            <Ticket className="w-8 h-8 text-primary" />
            Voucher Management
          </h1>
          <p className="text-sm text-muted-foreground">
            Create, manage, and track WiFi vouchers for your network
          </p>
        </div>
        <div className="flex items-center gap-3">
          <button
            onClick={() => setShowSalesPointsDialog(true)}
            className="flex items-center gap-2 px-4 py-2 bg-card border border-border text-card-foreground rounded-lg hover:bg-muted transition-colors"
          >
            <Users className="w-4 h-4" />
            Sales Points
          </button>
          <button
            onClick={() => setShowCreateDialog(true)}
            className="flex items-center gap-2 px-4 py-2 bg-primary text-primary-foreground rounded-lg hover:bg-primary/90 transition-colors"
          >
            <Plus className="w-4 h-4" />
            Create Vouchers
          </button>
        </div>
      </div>

      {/* Stats Overview */}
      {stats?.overall && (
        <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
          <div className="bg-gradient-to-br from-blue-600 to-blue-700 rounded-lg p-5 text-white">
            <div className="flex items-center justify-between mb-2">
              <p className="text-sm opacity-80">Total Vouchers</p>
              <Package className="w-5 h-5 opacity-80" />
            </div>
            <p className="text-3xl font-bold">{stats.overall.total_vouchers || 0}</p>
            <p className="text-xs opacity-70 mt-1">All time</p>
          </div>

          <div className="bg-gradient-to-br from-emerald-600 to-emerald-700 rounded-lg p-5 text-white">
            <div className="flex items-center justify-between mb-2">
              <p className="text-sm opacity-80">Used Vouchers</p>
              <TrendingUp className="w-5 h-5 opacity-80" />
            </div>
            <p className="text-3xl font-bold">{stats.overall.used || 0}</p>
            <p className="text-xs opacity-70 mt-1">
              {stats.overall.total_vouchers > 0
                ? `${((stats.overall.used / stats.overall.total_vouchers) * 100).toFixed(1)}% usage rate`
                : 'No data'}
            </p>
          </div>

          <div className="bg-gradient-to-br from-purple-600 to-purple-700 rounded-lg p-5 text-white">
            <div className="flex items-center justify-between mb-2">
              <p className="text-sm opacity-80">Available</p>
              <Ticket className="w-5 h-5 opacity-80" />
            </div>
            <p className="text-3xl font-bold">{stats.overall.unused || 0}</p>
            <p className="text-xs opacity-70 mt-1">Ready to use</p>
          </div>

          <div className="bg-gradient-to-br from-orange-600 to-orange-700 rounded-lg p-5 text-white">
            <div className="flex items-center justify-between mb-2">
              <p className="text-sm opacity-80">Total Revenue</p>
              <TrendingUp className="w-5 h-5 opacity-80" />
            </div>
            <p className="text-2xl font-bold">{formatCurrency(stats.overall.total_revenue || 0)}</p>
            <p className="text-xs opacity-70 mt-1">From used vouchers</p>
          </div>
        </div>
      )}

      {/* Daily Usage Chart */}
      {stats?.daily && stats.daily.length > 0 && (
        <div className="bg-card border border-border rounded-lg p-6 mb-6">
          <h2 className="text-lg font-semibold text-card-foreground mb-4">Daily Usage (Last 30 Days)</h2>
          <div className="space-y-3">
            {stats.daily.slice(0, 7).map((day) => (
              <div key={day.date} className="flex items-center gap-4">
                <div className="w-24 text-sm text-muted-foreground">
                  {new Date(day.date).toLocaleDateString('en-GB', { month: 'short', day: 'numeric' })}
                </div>
                <div className="flex-1">
                  <div className="flex items-center justify-between mb-1">
                    <span className="text-sm text-card-foreground">{day.vouchers_used} vouchers</span>
                    <span className="text-sm font-semibold text-primary">{formatCurrency(day.revenue)}</span>
                  </div>
                  <div className="w-full bg-muted rounded-full h-2">
                    <div
                      className="bg-primary h-2 rounded-full transition-all"
                      style={{ width: `${Math.min((day.vouchers_used / Math.max(...stats.daily.map(d => d.vouchers_used))) * 100, 100)}%` }}
                    />
                  </div>
                </div>
                <div className="text-xs text-muted-foreground w-20 text-right">
                  {day.unique_devices} devices
                </div>
              </div>
            ))}
          </div>
        </div>
      )}

      {/* Sales Points - Clickable to filter voucher groups */}
      {stats?.by_sales_point && stats.by_sales_point.length > 0 && (
        <div className="bg-card border border-border rounded-lg p-6 mb-6">
          <div className="flex items-center justify-between mb-4">
            <h2 className="text-lg font-semibold text-card-foreground">Sales Points</h2>
            {selectedSalesPoint && (
              <button
                onClick={() => setSelectedSalesPoint(null)}
                className="flex items-center gap-1 px-3 py-1 text-sm bg-primary/10 text-primary rounded-full hover:bg-primary/20 transition-colors"
              >
                <X className="w-3 h-3" />
                Clear filter
              </button>
            )}
          </div>
          <div className="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-4 xl:grid-cols-5 gap-3">
            {stats.by_sales_point.map((point) => (
              <button
                key={point.name}
                onClick={() => setSelectedSalesPoint(selectedSalesPoint === point.name ? null : point.name)}
                className={`text-left rounded-lg p-4 transition-all ${
                  selectedSalesPoint === point.name
                    ? 'bg-primary text-primary-foreground ring-2 ring-primary'
                    : 'bg-muted/50 hover:bg-muted hover:ring-1 hover:ring-primary/50'
                }`}
              >
                <div className="flex items-center justify-between mb-2">
                  <Users className={`w-4 h-4 ${selectedSalesPoint === point.name ? 'text-primary-foreground' : 'text-primary'}`} />
                  <ChevronRight className={`w-4 h-4 ${selectedSalesPoint === point.name ? 'text-primary-foreground' : 'text-muted-foreground'}`} />
                </div>
                <h3 className={`font-semibold text-sm mb-1 ${selectedSalesPoint === point.name ? '' : 'text-card-foreground'}`}>
                  {point.name}
                </h3>
                <p className={`text-xs ${selectedSalesPoint === point.name ? 'opacity-80' : 'text-muted-foreground'}`}>
                  {point.total_vouchers} vouchers • {formatCurrency(point.revenue)}
                </p>
              </button>
            ))}
          </div>
        </div>
      )}

      {/* Voucher Groups */}
      <div className="bg-card border border-border rounded-lg p-6">
        <div className="flex items-center justify-between mb-4">
          <div className="flex items-center gap-3">
            <h2 className="text-lg font-semibold text-card-foreground">Voucher Groups</h2>
            {selectedSalesPoint && (
              <span className="px-2 py-1 text-xs bg-primary/10 text-primary rounded-full">
                {selectedSalesPoint} ({filteredGroups.length})
              </span>
            )}
          </div>
        </div>

        {filteredGroups.length === 0 ? (
          <div className="text-center py-12">
            <Ticket className="w-12 h-12 text-muted-foreground mx-auto mb-3" />
            <p className="text-muted-foreground mb-4">
              {selectedSalesPoint 
                ? `No voucher groups for ${selectedSalesPoint}` 
                : 'No voucher groups created yet'}
            </p>
            <button
              onClick={() => setShowCreateDialog(true)}
              className="px-4 py-2 bg-primary text-primary-foreground rounded-lg hover:bg-primary/90 transition-colors"
            >
              Create Your First Group
            </button>
          </div>
        ) : (
          <div className="grid grid-cols-1 lg:grid-cols-2 gap-4">
            {filteredGroups.map((group) => (
              <VoucherGroupCard
                key={group.id}
                group={group}
                onSelect={() => setSelectedGroup(group)}
                onDelete={handleDeleteGroup}
                isDeleting={deletingGroupId === group.id}
              />
            ))}
          </div>
        )}
      </div>

      {/* Dialogs */}
      {showCreateDialog && (
        <CreateVoucherDialog
          onClose={() => setShowCreateDialog(false)}
          onSuccess={handleVoucherCreated}
        />
      )}

      {showSalesPointsDialog && (
        <SalesPointsDialog
          onClose={() => setShowSalesPointsDialog(false)}
          onUpdate={loadData}
        />
      )}
    </div>
  );
}
