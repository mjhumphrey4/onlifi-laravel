import { useState, useEffect } from 'react';
import { Users as UsersIcon, Search, UserCheck, UserX, Shield, User, Database, Calendar, Activity, Trash2 } from 'lucide-react';

interface User {
  id: number;
  username: string;
  email: string;
  full_name: string;
  phone: string | null;
  role: string;
  status: string;
  created_at: string;
  last_login: string | null;
  database_name: string;
  plan_type: string;
  subscription_status: string;
}

export function Users() {
  const [users, setUsers] = useState<User[]>([]);
  const [filteredUsers, setFilteredUsers] = useState<User[]>([]);
  const [loading, setLoading] = useState(true);
  const [searchQuery, setSearchQuery] = useState('');
  const [statusFilter, setStatusFilter] = useState<string>('all');
  const [roleFilter, setRoleFilter] = useState<string>('all');

  useEffect(() => {
    loadUsers();
  }, []);

  useEffect(() => {
    filterUsers();
  }, [searchQuery, statusFilter, roleFilter, users]);

  const loadUsers = async () => {
    try {
      setLoading(true);
      const token = localStorage.getItem('admin_token');
      const response = await fetch('/api/super-admin/tenants', {
        headers: {
          'Authorization': `Bearer ${token}`,
          'Content-Type': 'application/json',
        },
      });
      const data = await response.json();
      
      if (response.ok) {
        setUsers(data.tenants || data);
      }
    } catch (error) {
      console.error('Failed to load users:', error);
    } finally {
      setLoading(false);
    }
  };

  const filterUsers = () => {
    let filtered = users;

    if (searchQuery) {
      const query = searchQuery.toLowerCase();
      filtered = filtered.filter(user =>
        user.username.toLowerCase().includes(query) ||
        user.email.toLowerCase().includes(query) ||
        user.full_name.toLowerCase().includes(query)
      );
    }

    if (statusFilter !== 'all') {
      filtered = filtered.filter(user => user.status === statusFilter);
    }

    if (roleFilter !== 'all') {
      filtered = filtered.filter(user => user.role === roleFilter);
    }

    setFilteredUsers(filtered);
  };

  const updateUserStatus = async (userId: number, newStatus: string) => {
    try {
      const token = localStorage.getItem('admin_token');
      const action = newStatus === 'active' ? 'activate' : 'suspend';
      const response = await fetch(`/api/super-admin/tenants/${userId}/${action}`, {
        method: 'POST',
        headers: {
          'Authorization': `Bearer ${token}`,
          'Content-Type': 'application/json',
        },
      });

      const data = await response.json();

      if (response.ok) {
        loadUsers();
      } else {
        alert(data.message || data.error || 'Failed to update user status');
      }
    } catch (error) {
      console.error('Failed to update user status:', error);
      alert('Failed to update user status');
    }
  };

  const approveUser = async (userId: number) => {
    if (!confirm('Approve this user? This will create their database and activate their account.')) {
      return;
    }

    try {
      const token = localStorage.getItem('admin_token');
      const response = await fetch(`/api/super-admin/tenants/${userId}/approve`, {
        method: 'POST',
        headers: {
          'Authorization': `Bearer ${token}`,
          'Content-Type': 'application/json',
        },
      });

      const data = await response.json();

      if (response.ok) {
        alert('User approved successfully! Database created and account activated.');
        loadUsers();
      } else {
        alert(data.message || data.error || 'Failed to approve user');
      }
    } catch (error) {
      console.error('Failed to approve user:', error);
      alert('Failed to approve user');
    }
  };

  const deleteUser = async (userId: number, username: string) => {
    if (!confirm(`⚠️ DELETE USER: ${username}\n\nThis will permanently delete:\n• User account and credentials\n• User's tenant database\n• All user data and settings\n• All activity logs\n\nThis action CANNOT be undone!\n\nAre you absolutely sure?`)) {
      return;
    }

    // Double confirmation for safety
    if (!confirm(`Final confirmation: Delete user "${username}" and their database permanently?`)) {
      return;
    }

    try {
      const token = localStorage.getItem('admin_token');
      const response = await fetch(`/api/super-admin/tenants/${userId}`, {
        method: 'DELETE',
        headers: {
          'Authorization': `Bearer ${token}`,
          'Content-Type': 'application/json',
        },
      });

      const data = await response.json();

      if (response.ok) {
        alert(data.message || 'User deleted successfully');
        loadUsers();
      } else {
        alert(data.message || data.error || 'Failed to delete user');
      }
    } catch (error) {
      console.error('Failed to delete user:', error);
      alert('Failed to delete user');
    }
  };

  const getStatusBadge = (status: string) => {
    const styles = {
      active: 'bg-emerald-500/10 text-emerald-500 border-emerald-500/20',
      suspended: 'bg-red-500/10 text-red-500 border-red-500/20',
      pending: 'bg-yellow-500/10 text-yellow-500 border-yellow-500/20',
    };
    return styles[status as keyof typeof styles] || styles.pending;
  };

  const getRoleBadge = (role: string) => {
    const styles = {
      admin: 'bg-purple-500/10 text-purple-500 border-purple-500/20',
      user: 'bg-blue-500/10 text-blue-500 border-blue-500/20',
      reseller: 'bg-orange-500/10 text-orange-500 border-orange-500/20',
    };
    return styles[role as keyof typeof styles] || styles.user;
  };

  const stats = {
    total: users.length,
    active: users.filter(u => u.status === 'active').length,
    suspended: users.filter(u => u.status === 'suspended').length,
    admins: users.filter(u => u.role === 'admin').length,
  };

  if (loading) {
    return (
      <div className="p-8 flex items-center justify-center">
        <div className="text-center">
          <UsersIcon className="w-12 h-12 text-primary mx-auto mb-4 animate-pulse" />
          <p className="text-muted-foreground">Loading users...</p>
        </div>
      </div>
    );
  }

  return (
    <div className="p-4 sm:p-6 lg:p-8">
      {/* Header */}
      <div className="mb-6">
        <h1 className="text-2xl sm:text-3xl font-bold text-foreground mb-2">User Management</h1>
        <p className="text-muted-foreground">Manage all registered users and their accounts</p>
      </div>

      {/* Stats Cards */}
      <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
        <div className="bg-card border border-border rounded-lg p-5">
          <div className="flex items-center justify-between mb-2">
            <p className="text-sm text-muted-foreground">Total Users</p>
            <UsersIcon className="w-5 h-5 text-primary" />
          </div>
          <p className="text-3xl font-bold text-card-foreground">{stats.total}</p>
        </div>

        <div className="bg-card border border-border rounded-lg p-5">
          <div className="flex items-center justify-between mb-2">
            <p className="text-sm text-muted-foreground">Active Users</p>
            <UserCheck className="w-5 h-5 text-emerald-500" />
          </div>
          <p className="text-3xl font-bold text-emerald-500">{stats.active}</p>
        </div>

        <div className="bg-card border border-border rounded-lg p-5">
          <div className="flex items-center justify-between mb-2">
            <p className="text-sm text-muted-foreground">Pending Approval</p>
            <Activity className="w-5 h-5 text-yellow-500" />
          </div>
          <p className="text-3xl font-bold text-yellow-500">{stats.pending}</p>
        </div>

        <div className="bg-card border border-border rounded-lg p-5">
          <div className="flex items-center justify-between mb-2">
            <p className="text-sm text-muted-foreground">Administrators</p>
            <Shield className="w-5 h-5 text-purple-500" />
          </div>
          <p className="text-3xl font-bold text-purple-500">{stats.admins}</p>
        </div>
      </div>

      {/* Filters */}
      <div className="bg-card border border-border rounded-lg p-4 mb-6">
        <div className="grid grid-cols-1 md:grid-cols-3 gap-4">
          {/* Search */}
          <div className="relative">
            <Search className="absolute left-3 top-1/2 -translate-y-1/2 w-5 h-5 text-muted-foreground" />
            <input
              type="text"
              placeholder="Search users..."
              value={searchQuery}
              onChange={(e) => setSearchQuery(e.target.value)}
              className="w-full pl-11 pr-4 py-2.5 bg-background border border-border rounded-lg focus:ring-2 focus:ring-primary focus:border-transparent"
            />
          </div>

          {/* Status Filter */}
          <select
            value={statusFilter}
            onChange={(e) => setStatusFilter(e.target.value)}
            className="px-4 py-2.5 bg-background border border-border rounded-lg focus:ring-2 focus:ring-primary focus:border-transparent"
          >
            <option value="all">All Statuses</option>
            <option value="active">Active</option>
            <option value="suspended">Suspended</option>
            <option value="pending">Pending</option>
          </select>

          {/* Role Filter */}
          <select
            value={roleFilter}
            onChange={(e) => setRoleFilter(e.target.value)}
            className="px-4 py-2.5 bg-background border border-border rounded-lg focus:ring-2 focus:ring-primary focus:border-transparent"
          >
            <option value="all">All Roles</option>
            <option value="admin">Admin</option>
            <option value="user">User</option>
            <option value="reseller">Reseller</option>
          </select>
        </div>
      </div>

      {/* Users Table */}
      <div className="bg-card border border-border rounded-lg overflow-hidden">
        <div className="overflow-x-auto">
          <table className="w-full">
            <thead className="bg-muted/50 border-b border-border">
              <tr>
                <th className="text-left py-3 px-4 text-sm font-semibold text-card-foreground">User</th>
                <th className="text-left py-3 px-4 text-sm font-semibold text-card-foreground">Contact</th>
                <th className="text-left py-3 px-4 text-sm font-semibold text-card-foreground">Role</th>
                <th className="text-left py-3 px-4 text-sm font-semibold text-card-foreground">Status</th>
                <th className="text-left py-3 px-4 text-sm font-semibold text-card-foreground">Database</th>
                <th className="text-left py-3 px-4 text-sm font-semibold text-card-foreground">Joined</th>
                <th className="text-left py-3 px-4 text-sm font-semibold text-card-foreground">Last Login</th>
                <th className="text-left py-3 px-4 text-sm font-semibold text-card-foreground">Actions</th>
              </tr>
            </thead>
            <tbody>
              {filteredUsers.length === 0 ? (
                <tr>
                  <td colSpan={8} className="py-12 text-center">
                    <User className="w-12 h-12 text-muted-foreground mx-auto mb-3" />
                    <p className="text-muted-foreground">No users found</p>
                  </td>
                </tr>
              ) : (
                filteredUsers.map((user) => (
                  <tr key={user.id} className="border-b border-border hover:bg-muted/30 transition-colors">
                    <td className="py-4 px-4">
                      <div className="flex items-center gap-3">
                        <div className="w-10 h-10 bg-primary/10 rounded-full flex items-center justify-center">
                          <User className="w-5 h-5 text-primary" />
                        </div>
                        <div>
                          <p className="font-semibold text-card-foreground">{user.full_name}</p>
                          <p className="text-sm text-muted-foreground">@{user.username}</p>
                        </div>
                      </div>
                    </td>
                    <td className="py-4 px-4">
                      <p className="text-sm text-card-foreground">{user.email}</p>
                      {user.phone && (
                        <p className="text-xs text-muted-foreground">{user.phone}</p>
                      )}
                    </td>
                    <td className="py-4 px-4">
                      <span className={`inline-flex items-center gap-1 px-2.5 py-1 rounded-full text-xs font-semibold border ${getRoleBadge(user.role)}`}>
                        {user.role === 'admin' && <Shield className="w-3 h-3" />}
                        {user.role}
                      </span>
                    </td>
                    <td className="py-4 px-4">
                      <span className={`inline-block px-2.5 py-1 rounded-full text-xs font-semibold border ${getStatusBadge(user.status)}`}>
                        {user.status}
                      </span>
                    </td>
                    <td className="py-4 px-4">
                      <div className="flex items-center gap-2 text-sm text-muted-foreground">
                        <Database className="w-4 h-4" />
                        <span className="font-mono text-xs">{user.database_name}</span>
                      </div>
                    </td>
                    <td className="py-4 px-4">
                      <div className="flex items-center gap-2 text-sm text-muted-foreground">
                        <Calendar className="w-4 h-4" />
                        {new Date(user.created_at).toLocaleDateString()}
                      </div>
                    </td>
                    <td className="py-4 px-4">
                      {user.last_login ? (
                        <div className="flex items-center gap-2 text-sm text-muted-foreground">
                          <Activity className="w-4 h-4" />
                          {new Date(user.last_login).toLocaleDateString()}
                        </div>
                      ) : (
                        <span className="text-sm text-muted-foreground">Never</span>
                      )}
                    </td>
                    <td className="py-4 px-4">
                      {user.role !== 'admin' && (
                        <div className="flex items-center gap-2">
                          {user.status === 'pending' ? (
                            <>
                              <button
                                onClick={() => approveUser(user.id)}
                                className="px-3 py-1.5 text-xs bg-emerald-500/10 hover:bg-emerald-500/20 text-emerald-500 rounded-lg transition-colors font-semibold"
                              >
                                Approve & Provision
                              </button>
                              <button
                                onClick={() => deleteUser(user.id, user.username)}
                                className="p-1.5 text-xs bg-red-500/10 hover:bg-red-500/20 text-red-500 rounded-lg transition-colors"
                                title="Delete user"
                              >
                                <Trash2 className="w-4 h-4" />
                              </button>
                            </>
                          ) : user.status === 'active' ? (
                            <>
                              <button
                                onClick={() => updateUserStatus(user.id, 'suspended')}
                                className="px-3 py-1.5 text-xs bg-red-500/10 hover:bg-red-500/20 text-red-500 rounded-lg transition-colors"
                              >
                                Suspend
                              </button>
                              <button
                                onClick={() => deleteUser(user.id, user.username)}
                                className="p-1.5 text-xs bg-red-500/10 hover:bg-red-500/20 text-red-500 rounded-lg transition-colors"
                                title="Delete user and database"
                              >
                                <Trash2 className="w-4 h-4" />
                              </button>
                            </>
                          ) : (
                            <>
                              <button
                                onClick={() => updateUserStatus(user.id, 'active')}
                                className="px-3 py-1.5 text-xs bg-emerald-500/10 hover:bg-emerald-500/20 text-emerald-500 rounded-lg transition-colors"
                              >
                                Activate
                              </button>
                              <button
                                onClick={() => deleteUser(user.id, user.username)}
                                className="p-1.5 text-xs bg-red-500/10 hover:bg-red-500/20 text-red-500 rounded-lg transition-colors"
                                title="Delete user and database"
                              >
                                <Trash2 className="w-4 h-4" />
                              </button>
                            </>
                          )}
                        </div>
                      )}
                    </td>
                  </tr>
                ))
              )}
            </tbody>
          </table>
        </div>
      </div>

      {/* Results Summary */}
      <div className="mt-4 text-sm text-muted-foreground text-center">
        Showing {filteredUsers.length} of {users.length} users
      </div>
    </div>
  );
}
