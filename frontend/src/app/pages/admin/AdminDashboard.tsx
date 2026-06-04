import React, { useEffect, useState } from 'react';
import { useNavigate } from 'react-router';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/app/components/ui/card';
import { Button } from '@/app/components/ui/button';
import { Users, UserCheck, UserX, Clock, Settings, Megaphone, ChevronRight, RefreshCw, DollarSign, Router, AlertCircle, Network, CreditCard, Wrench } from 'lucide-react';
import { API_BASE } from '../../utils/api';

interface Statistics {
  total_tenants: number;
  pending_tenants: number;
  approved_tenants: number;
  rejected_tenants: number;
  suspended_tenants: number;
  active_tenants: number;
  approved_active_tenants: number;
  tenants_with_fee_overrides: number;
  registered_radius_routers: number;
  platform_fees_collected: number;
  yo_payment_tenants: number;
  iotec_payment_tenants: number;
  mikrotik_tenants: number;
  omada_tenants: number;
}

export default function AdminDashboard() {
  const navigate = useNavigate();
  const [stats, setStats] = useState<Statistics | null>(null);
  const [loading, setLoading] = useState(true);

  useEffect(() => {
    fetchStatistics();
  }, []);

  const fetchStatistics = async () => {
    try {
      const token = localStorage.getItem('admin_token');
      const response = await fetch(`${API_BASE}/super-admin/tenants/statistics`, {
        headers: {
          'Authorization': `Bearer ${token}`,
        },
      });

      if (!response.ok) {
        if (response.status === 401) {
          navigate('/admin/login');
          return;
        }
        throw new Error('Failed to fetch statistics');
      }

      const data = await response.json();
      setStats(data);
    } catch (error) {
      console.error('Error fetching statistics:', error);
    } finally {
      setLoading(false);
    }
  };

  const StatCard = ({ title, value, icon: Icon, description, color, bgColor, onClick }: any) => (
    <Card 
      className={`relative overflow-hidden transition-all duration-200 ${onClick ? 'cursor-pointer hover:shadow-lg hover:scale-[1.02]' : ''}`}
      onClick={onClick}
    >
      <div className={`absolute top-0 right-0 w-24 h-24 -mr-8 -mt-8 rounded-full opacity-10 ${bgColor || 'bg-gray-500'}`} />
      <CardHeader className="flex flex-row items-center justify-between space-y-0 pb-2">
        <CardTitle className="text-sm font-medium text-muted-foreground">{title}</CardTitle>
        <div className={`p-2 rounded-lg ${bgColor || 'bg-gray-100'} bg-opacity-20`}>
          <Icon className={`h-5 w-5 ${color}`} />
        </div>
      </CardHeader>
      <CardContent>
        <div className="text-3xl font-bold tracking-tight">{value}</div>
        {description && (
          <p className="text-xs text-muted-foreground mt-1">{description}</p>
        )}
      </CardContent>
    </Card>
  );

  const ActionCard = ({ title, description, icon: Icon, onClick, color, bgColor }: any) => (
    <Card 
      className="cursor-pointer group transition-all duration-200 hover:shadow-lg hover:scale-[1.02] border-l-4"
      style={{ borderLeftColor: color }}
      onClick={onClick}
    >
      <CardHeader className="pb-3">
        <div className="flex items-center justify-between">
          <div className={`p-3 rounded-xl ${bgColor} bg-opacity-10`}>
            <Icon className={`h-6 w-6`} style={{ color }} />
          </div>
          <ChevronRight className="h-5 w-5 text-muted-foreground group-hover:translate-x-1 transition-transform" />
        </div>
        <CardTitle className="text-lg mt-3">{title}</CardTitle>
        <CardDescription className="text-sm">{description}</CardDescription>
      </CardHeader>
    </Card>
  );

  if (loading) {
    return (
      <div className="flex items-center justify-center h-screen bg-background">
        <div className="text-center">
          <RefreshCw className="h-12 w-12 text-primary animate-spin mx-auto" />
          <p className="mt-4 text-muted-foreground">Loading dashboard...</p>
        </div>
      </div>
    );
  }

  return (
    <div className="min-h-screen bg-background p-6 lg:p-8 space-y-8">
      {/* Header */}
      <div className="flex flex-col sm:flex-row justify-between items-start sm:items-center gap-4">
        <div>
          <h1 className="text-3xl font-bold tracking-tight">Super Admin Dashboard</h1>
          <p className="text-muted-foreground mt-1">Manage tenants and system settings</p>
        </div>
        <Button 
          onClick={() => navigate('/admin/tenants/pending')} 
          size="lg"
          className="relative group"
        >
          <Clock className="mr-2 h-4 w-4" />
          View Pending Approvals
          {stats && stats.pending_tenants > 0 && (
            <span className="absolute -top-2 -right-2 bg-destructive text-destructive-foreground px-2 py-0.5 rounded-full text-xs font-bold animate-pulse">
              {stats.pending_tenants}
            </span>
          )}
        </Button>
      </div>

      {/* Alert for pending approvals */}
      {stats && stats.pending_tenants > 0 && (
        <div 
          className="bg-yellow-500/10 border border-yellow-500/30 rounded-lg p-4 flex items-center gap-4 cursor-pointer hover:bg-yellow-500/20 transition-colors"
          onClick={() => navigate('/admin/tenants/pending')}
        >
          <div className="p-2 bg-yellow-500/20 rounded-full">
            <AlertCircle className="h-6 w-6 text-yellow-600" />
          </div>
          <div className="flex-1">
            <p className="font-semibold text-yellow-700 dark:text-yellow-400">
              {stats.pending_tenants} tenant{stats.pending_tenants > 1 ? 's' : ''} awaiting approval
            </p>
            <p className="text-sm text-yellow-600/80 dark:text-yellow-500/80">
              Click here to review and approve pending signups
            </p>
          </div>
          <ChevronRight className="h-5 w-5 text-yellow-600" />
        </div>
      )}

      {/* Main Stats Grid */}
      <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <StatCard
          title="Total Tenants"
          value={stats?.total_tenants || 0}
          icon={Users}
          description="All registered tenants"
          color="text-blue-600"
          bgColor="bg-blue-500"
          onClick={() => navigate('/admin/tenants')}
        />
        <StatCard
          title="Pending Approval"
          value={stats?.pending_tenants || 0}
          icon={Clock}
          description="Awaiting admin approval"
          color="text-yellow-600"
          bgColor="bg-yellow-500"
          onClick={() => navigate('/admin/tenants/pending')}
        />
        <StatCard
          title="Active Tenants"
          value={stats?.active_tenants || 0}
          icon={UserCheck}
          description="Currently active"
          color="text-green-600"
          bgColor="bg-green-500"
          onClick={() => navigate('/admin/tenants')}
        />
        <StatCard
          title="Suspended"
          value={stats?.suspended_tenants || 0}
          icon={UserX}
          description="Suspended accounts"
          color="text-red-600"
          bgColor="bg-red-500"
          onClick={() => navigate('/admin/tenants')}
        />
      </div>

      <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
        <StatCard
          title="RADIUS Routers"
          value={stats?.registered_radius_routers || 0}
          icon={Router}
          description="Registered NAS entries"
          color="text-cyan-600"
          bgColor="bg-cyan-500"
        />
        <StatCard
          title="Custom Fees"
          value={stats?.tenants_with_fee_overrides || 0}
          icon={DollarSign}
          description="Tenant-specific pricing"
          color="text-amber-600"
          bgColor="bg-amber-500"
          onClick={() => navigate('/admin/platform-fees')}
        />
        <StatCard
          title="Platform Fees"
          value={`UGX ${Math.round(stats?.platform_fees_collected || 0).toLocaleString()}`}
          icon={DollarSign}
          description="All-time collected"
          color="text-emerald-600"
          bgColor="bg-emerald-500"
          onClick={() => navigate('/admin/platform-fees')}
        />
      </div>

      <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <StatCard
          title="Mikrotik Users"
          value={stats?.mikrotik_tenants || 0}
          icon={Router}
          description="Selected Mikrotik during signup"
          color="text-sky-600"
          bgColor="bg-sky-500"
          onClick={() => navigate('/admin/mikrotik-users')}
        />
        <StatCard
          title="Omada Users"
          value={stats?.omada_tenants || 0}
          icon={Network}
          description="Marked for future Omada setup"
          color="text-violet-600"
          bgColor="bg-violet-500"
          onClick={() => navigate('/admin/omada-users')}
        />
        <StatCard
          title="YoPayments"
          value={stats?.yo_payment_tenants || 0}
          icon={CreditCard}
          description="5.5% selected provider"
          color="text-orange-600"
          bgColor="bg-orange-500"
        />
        <StatCard
          title="IOTEC"
          value={stats?.iotec_payment_tenants || 0}
          icon={CreditCard}
          description="5% selected provider"
          color="text-teal-600"
          bgColor="bg-teal-500"
        />
      </div>

      {/* Quick Actions */}
      <div>
        <h2 className="text-xl font-semibold mb-4">Quick Actions</h2>
        <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
          <ActionCard
            title="Manage Tenants"
            description="View and manage all tenant accounts"
            icon={Users}
            onClick={() => navigate('/admin/tenants')}
            color="#3B82F6"
            bgColor="bg-blue-500"
          />
          <ActionCard
            title="Pending Approvals"
            description="Review new tenant signups"
            icon={Clock}
            onClick={() => navigate('/admin/tenants/pending')}
            color="#EAB308"
            bgColor="bg-yellow-500"
          />
          <ActionCard
            title="VPN Setup"
            description="View WireGuard keys, peer configs, IPs, and endpoints"
            icon={Network}
            onClick={() => navigate('/admin/vpn-management')}
            color="#0EA5E9"
            bgColor="bg-sky-500"
          />
          <ActionCard
            title="Repair Tools"
            description="Fix tenant databases, migrations, and default sites"
            icon={Wrench}
            onClick={() => navigate('/admin/tenants')}
            color="#06B6D4"
            bgColor="bg-cyan-500"
          />
          <ActionCard
            title="Announcements"
            description="Create and manage announcements"
            icon={Megaphone}
            onClick={() => navigate('/admin/announcements')}
            color="#8B5CF6"
            bgColor="bg-purple-500"
          />
          <ActionCard
            title="System Settings"
            description="Configure global settings"
            icon={Settings}
            onClick={() => navigate('/admin/settings')}
            color="#6B7280"
            bgColor="bg-gray-500"
          />
        </div>
      </div>
    </div>
  );
}
