import { useState, useEffect, useMemo } from 'react';
import { Ticket, Plus, Users, TrendingUp, Package } from 'lucide-react';
import { CreateVoucherDialog } from '../components/CreateVoucherDialog';
import { VoucherGroupCard } from '../components/VoucherGroupCard';
import { SalesPointsDialog } from '../components/SalesPointsDialog';
import { useSite } from '../context/SiteContext';
import { API_BASE, getVoucherGroups, getVoucherStatistics } from '../utils/api';

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
  in_use_count: number;
}

interface VoucherStats {
  overall: {
    total_vouchers: number;
    unused: number;
    in_use: number;
    reserved: number;
    used: number;
    expired: number;
    total_revenue: number;
    revenue_30_days: number;
  };
  by_sales_point: Array<{
    id: number;
    name: string;
    total_vouchers: number;
    unused: number;
    reserved: number;
    in_use: number;
    used: number;
    revenue: number;
    revenue_30_days: number;
  }>;
}

export function Vouchers() {
  const { selectedSite } = useSite();
  const [groups, setGroups] = useState<VoucherGroup[]>([]);
  const [stats, setStats] = useState<VoucherStats | null>(null);
  const [loading, setLoading] = useState(true);
  const [showCreateDialog, setShowCreateDialog] = useState(false);
  const [showSalesPointsDialog, setShowSalesPointsDialog] = useState(false);
  const [selectedSalesPointId, setSelectedSalesPointId] = useState<number | null>(null);
  const [deletingGroupId, setDeletingGroupId] = useState<number | null>(null);
  const [groupPage, setGroupPage] = useState(1);
  const groupsPerPage = 9;
  const groupToneForIndex = (index: number) => (index >= 3 && index <= 5 ? 'bg-primary/5' : 'bg-card');

  useEffect(() => {
    setGroupPage(1);
    loadData();
  }, [selectedSite?.id, selectedSalesPointId]);

  const loadData = async () => {
    try {
      setLoading(true);
      const [groupsData, statsData] = await Promise.all([
        getVoucherGroups(),
        getVoucherStatistics(selectedSalesPointId ? { sales_point_id: selectedSalesPointId } : undefined),
      ]);

      setGroups(Array.isArray(groupsData) ? groupsData : groupsData.groups || []);
      setStats({
        overall: {
          total_vouchers: statsData.total_vouchers || 0,
          unused: statsData.unused_vouchers || 0,
          in_use: statsData.consumed_vouchers || statsData.in_use_vouchers || 0,
          reserved: statsData.reserved_vouchers || 0,
          used: statsData.used_vouchers || 0,
          expired: statsData.expired_vouchers || 0,
          total_revenue: statsData.total_revenue || 0,
          revenue_30_days: statsData.revenue_30_days || 0,
        },
        by_sales_point: statsData.by_sales_point || [],
      });
    } catch (error) {
      console.error('Failed to load vouchers:', error);
      if (selectedSalesPointId) {
        setSelectedSalesPointId(null);
      }
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
      const token = localStorage.getItem('tenant_token');
      const headers: HeadersInit = {
        'Content-Type': 'application/json',
        'Accept': 'application/json',
      };
      if (token) headers['Authorization'] = `Bearer ${token}`;
      const siteId = localStorage.getItem('selected_site_id');
      if (siteId) headers['X-Site-ID'] = siteId;

      const res = await fetch(`${API_BASE}/vouchers/groups/${groupId}`, {
        method: 'DELETE',
        headers,
        credentials: 'include',
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

  const salesPointTabs = useMemo(() => {
    const points = new Map<number, { id: number; name: string; groupCount: number }>();
    groups.forEach((group) => {
      if (!group.sales_point_id || !group.sales_point_name) return;
      const existing = points.get(group.sales_point_id);
      points.set(group.sales_point_id, {
        id: group.sales_point_id,
        name: group.sales_point_name,
        groupCount: (existing?.groupCount || 0) + 1,
      });
    });
    return Array.from(points.values()).sort((a, b) => a.name.localeCompare(b.name));
  }, [groups]);

  const selectedSalesPoint = selectedSalesPointId
    ? salesPointTabs.find((point) => point.id === selectedSalesPointId) || null
    : null;

  const filteredGroups = selectedSalesPointId
    ? groups.filter(g => g.sales_point_id === selectedSalesPointId)
    : groups;
  const totalGroupPages = Math.max(1, Math.ceil(filteredGroups.length / groupsPerPage));
  const currentGroupPage = Math.min(groupPage, totalGroupPages);
  const paginatedGroups = filteredGroups.slice(
    (currentGroupPage - 1) * groupsPerPage,
    currentGroupPage * groupsPerPage
  );

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

      {/* Sales Point Tabs - Quick Filter */}
      {salesPointTabs.length > 0 && (
        <div className="mb-6 bg-card border border-border rounded-lg p-2">
          <div className="flex items-center gap-2 overflow-x-auto pb-1">
            <button
              onClick={() => setSelectedSalesPointId(null)}
              className={`flex-shrink-0 px-4 py-2 rounded-lg text-sm font-medium transition-all ${
                selectedSalesPointId === null
                  ? 'bg-primary text-primary-foreground shadow-sm'
                  : 'bg-muted/50 text-muted-foreground hover:bg-muted hover:text-foreground'
              }`}
            >
              All Sales Points
              <span className={`ml-2 px-1.5 py-0.5 rounded text-xs ${
                selectedSalesPointId === null ? 'bg-primary-foreground/20' : 'bg-muted-foreground/20'
              }`}>
                {groups.length}
              </span>
            </button>
            {salesPointTabs.map((point) => (
              <button
                key={point.id}
                onClick={() => setSelectedSalesPointId(selectedSalesPointId === point.id ? null : point.id)}
                className={`flex-shrink-0 px-4 py-2 rounded-lg text-sm font-medium transition-all ${
                  selectedSalesPointId === point.id
                    ? 'bg-primary text-primary-foreground shadow-sm'
                    : 'bg-muted/50 text-muted-foreground hover:bg-muted hover:text-foreground'
                }`}
              >
                {point.name}
                <span className={`ml-2 px-1.5 py-0.5 rounded text-xs ${
                  selectedSalesPointId === point.id ? 'bg-primary-foreground/20' : 'bg-muted-foreground/20'
                }`}>
                  {point.groupCount}
                </span>
              </button>
            ))}
          </div>
        </div>
      )}

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

          <div className="bg-gradient-to-br from-yellow-500 to-amber-600 rounded-lg p-5 text-white">
            <div className="flex items-center justify-between mb-2">
              <p className="text-sm opacity-80">Used</p>
              <TrendingUp className="w-5 h-5 opacity-80" />
            </div>
            <p className="text-3xl font-bold">{stats.overall.in_use || 0}</p>
            <p className="text-xs opacity-70 mt-1">
              {stats.overall.total_vouchers > 0
                ? `${((stats.overall.in_use / stats.overall.total_vouchers) * 100).toFixed(1)}% active now`
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
              <p className="text-sm opacity-80">30-Day Revenue</p>
              <TrendingUp className="w-5 h-5 opacity-80" />
            </div>
            <p className="text-2xl font-bold">{formatCurrency(stats.overall.revenue_30_days || 0)}</p>
            <p className="text-xs opacity-70 mt-1">
              {selectedSalesPoint ? selectedSalesPoint.name : 'All sales points'}
            </p>
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
                {selectedSalesPoint.name} ({filteredGroups.length})
              </span>
            )}
          </div>
        </div>

        {filteredGroups.length === 0 ? (
          <div className="text-center py-12">
            <Ticket className="w-12 h-12 text-muted-foreground mx-auto mb-3" />
            <p className="text-muted-foreground mb-4">
              {selectedSalesPoint
                ? `No voucher groups for ${selectedSalesPoint.name}`
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
          <div className="space-y-5">
            <div className="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-4">
              {paginatedGroups.map((group, index) => (
                <VoucherGroupCard
                  key={group.id}
                  group={group}
                  toneClassName={groupToneForIndex(index)}
                  onDelete={handleDeleteGroup}
                  isDeleting={deletingGroupId === group.id}
                />
              ))}
            </div>

            {totalGroupPages > 1 && (
              <div className="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 border-t border-border pt-4">
                <p className="text-sm text-muted-foreground">
                  Showing {(currentGroupPage - 1) * groupsPerPage + 1}-{Math.min(currentGroupPage * groupsPerPage, filteredGroups.length)} of {filteredGroups.length} groups
                </p>
                <div className="flex items-center gap-2">
                  <button
                    onClick={() => setGroupPage((page) => Math.max(1, page - 1))}
                    disabled={currentGroupPage === 1}
                    className="px-3 py-1.5 rounded-lg border border-border text-sm disabled:opacity-50 hover:bg-muted"
                  >
                    Previous
                  </button>
                  {Array.from({ length: totalGroupPages }, (_, index) => index + 1).map((page) => (
                    <button
                      key={page}
                      onClick={() => setGroupPage(page)}
                      className={`w-9 h-9 rounded-lg text-sm ${
                        currentGroupPage === page
                          ? 'bg-primary text-primary-foreground'
                          : 'border border-border hover:bg-muted'
                      }`}
                    >
                      {page}
                    </button>
                  ))}
                  <button
                    onClick={() => setGroupPage((page) => Math.min(totalGroupPages, page + 1))}
                    disabled={currentGroupPage === totalGroupPages}
                    className="px-3 py-1.5 rounded-lg border border-border text-sm disabled:opacity-50 hover:bg-muted"
                  >
                    Next
                  </button>
                </div>
              </div>
            )}
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
