import React, { useEffect, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/app/components/ui/card';
import { Button } from '@/app/components/ui/button';
import { Users, UserCheck, UserX, Clock, TrendingUp, AlertCircle } from 'lucide-react';

interface Statistics {
  total_tenants: number;
  pending_tenants: number;
  approved_tenants: number;
  rejected_tenants: number;
  suspended_tenants: number;
  active_tenants: number;
  trial_tenants: number;
  subscribed_tenants: number;
  expired_trials: number;
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
      const response = await fetch('/api/super-admin/tenants/statistics', {
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

  const StatCard = ({ title, value, icon: Icon, description, color }: any) => (
    <Card>
      <CardHeader className="flex flex-row items-center justify-between space-y-0 pb-2">
        <CardTitle className="text-sm font-medium">{title}</CardTitle>
        <Icon className={`h-4 w-4 ${color}`} />
      </CardHeader>
      <CardContent>
        <div className="text-2xl font-bold">{value}</div>
        {description && (
          <p className="text-xs text-muted-foreground mt-1">{description}</p>
        )}
      </CardContent>
    </Card>
  );

  if (loading) {
    return (
      <div className="flex items-center justify-center h-screen">
        <div className="text-center">
          <div className="animate-spin rounded-full h-12 w-12 border-b-2 border-indigo-600 mx-auto"></div>
          <p className="mt-4 text-gray-600">Loading dashboard...</p>
        </div>
      </div>
    );
  }

  return (
    <div className="p-6 space-y-6">
      <div className="flex justify-between items-center">
        <div>
          <h1 className="text-3xl font-bold">Super Admin Dashboard</h1>
          <p className="text-gray-600 mt-1">Manage tenants and system settings</p>
        </div>
        <Button onClick={() => navigate('/admin/tenants/pending')} variant="default">
          View Pending Approvals
          {stats && stats.pending_tenants > 0 && (
            <span className="ml-2 bg-red-500 text-white px-2 py-0.5 rounded-full text-xs">
              {stats.pending_tenants}
            </span>
          )}
        </Button>
      </div>

      <div className="grid gap-4 md:grid-cols-2 lg:grid-cols-4">
        <StatCard
          title="Total Tenants"
          value={stats?.total_tenants || 0}
          icon={Users}
          description="All registered tenants"
          color="text-blue-600"
        />
        <StatCard
          title="Pending Approval"
          value={stats?.pending_tenants || 0}
          icon={Clock}
          description="Awaiting admin approval"
          color="text-yellow-600"
        />
        <StatCard
          title="Active Tenants"
          value={stats?.active_tenants || 0}
          icon={UserCheck}
          description="Currently active"
          color="text-green-600"
        />
        <StatCard
          title="Suspended"
          value={stats?.suspended_tenants || 0}
          icon={UserX}
          description="Suspended accounts"
          color="text-red-600"
        />
      </div>

      <div className="grid gap-4 md:grid-cols-3">
        <StatCard
          title="Trial Accounts"
          value={stats?.trial_tenants || 0}
          icon={TrendingUp}
          description="Active trial period"
          color="text-purple-600"
        />
        <StatCard
          title="Subscribed"
          value={stats?.subscribed_tenants || 0}
          icon={UserCheck}
          description="Paid subscriptions"
          color="text-green-600"
        />
        <StatCard
          title="Expired Trials"
          value={stats?.expired_trials || 0}
          icon={AlertCircle}
          description="Need attention"
          color="text-orange-600"
        />
      </div>

      <div className="grid gap-4 md:grid-cols-2 lg:grid-cols-3">
        <Card className="cursor-pointer hover:shadow-lg transition-shadow" onClick={() => navigate('/admin/tenants')}>
          <CardHeader>
            <CardTitle>Manage Tenants</CardTitle>
            <CardDescription>View and manage all tenant accounts</CardDescription>
          </CardHeader>
        </Card>

        <Card className="cursor-pointer hover:shadow-lg transition-shadow" onClick={() => navigate('/admin/announcements')}>
          <CardHeader>
            <CardTitle>Announcements</CardTitle>
            <CardDescription>Create and manage system announcements</CardDescription>
          </CardHeader>
        </Card>

        <Card className="cursor-pointer hover:shadow-lg transition-shadow" onClick={() => navigate('/admin/settings')}>
          <CardHeader>
            <CardTitle>System Settings</CardTitle>
            <CardDescription>Configure global system settings</CardDescription>
          </CardHeader>
        </Card>
      </div>
    </div>
  );
}
