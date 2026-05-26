import { Link, Outlet, useLocation, Navigate, useNavigate } from 'react-router';
import { useState, useEffect } from 'react';
import {
  LayoutDashboard,
  ArrowLeftRight,
  Wallet,
  TrendingUp,
  Package,
  Upload,
  User,
  Menu,
  X,
  LogOut,
  Users,
  Server,
  Ticket,
  Building2,
  ChevronDown,
  ChevronRight,
  Clock,
  Settings as SettingsIcon,
  Bell,
  Plus,
  Paintbrush,
  MessageSquare,
  Router,
} from 'lucide-react';
import { useAuth } from '../context/AuthContext';
import { useSite } from '../context/SiteContext';
import { BillingGate } from './BillingGate';

interface Announcement {
  id: number;
  title: string;
  content: string;
  type: 'info' | 'warning' | 'success' | 'error';
  created_at: string;
  is_read?: boolean;
}

interface MenuItem {
  path: string;
  label: string;
  icon: any;
  adminOnly: boolean;
  children?: MenuItem[];
}

const menuItems: MenuItem[] = [
  { path: '/',               label: 'Dashboard',          icon: LayoutDashboard, adminOnly: false },
  { path: '/clients',        label: 'Clients',            icon: Users, adminOnly: false },
  { path: '/devices',        label: 'Devices',            icon: Server, adminOnly: false },
  { 
    path: '/vouchers',       
    label: 'Manage Vouchers',           
    icon: Ticket, 
    adminOnly: false,
    children: [
      { path: '/vouchers',       label: 'Vouchers',           icon: Ticket, adminOnly: false },
      { path: '/voucher-types',  label: 'Voucher Types',      icon: Clock, adminOnly: false },
      { path: '/voucher-templates', label: 'Templates', icon: Ticket, adminOnly: false },
      { path: '/voucher-stock',  label: 'Stock',      icon: Package, adminOnly: false },
      { path: '/import-vouchers', label: 'Import',    icon: Upload, adminOnly: false },
    ]
  },
  { path: '/users',          label: 'User Management',    icon: Users, adminOnly: true },
  { path: '/transactions',   label: 'Transactions',       icon: ArrowLeftRight, adminOnly: false },
  { path: '/withdrawals',    label: 'Withdrawals',        icon: Wallet, adminOnly: false },
  { path: '/performance',    label: 'Analyze Performance',icon: TrendingUp, adminOnly: false },
  { path: '/captive-portal',  label: 'Captive Page',       icon: Paintbrush, adminOnly: false },
  { path: '/sms-gateway',     label: 'SMS Gateway',        icon: MessageSquare, adminOnly: false },
  { path: '/radius-setup',   label: 'RADIUS Setup',       icon: Server, adminOnly: false },
  { path: '/provisioning',    label: 'Provisioning',       icon: Router, adminOnly: false },
  { path: '/settings',       label: 'Settings',           icon: SettingsIcon, adminOnly: false },
];

export function Layout() {
  const location = useLocation();
  const navigate = useNavigate();
  const { user, loading, logout } = useAuth();
  const { sites, selectedSite, setSelectedSite, refreshSites } = useSite();
  const [isMobileMenuOpen, setIsMobileMenuOpen] = useState(false);
  const [isSiteDropdownOpen, setIsSiteDropdownOpen] = useState(false);
  const [expandedMenus, setExpandedMenus] = useState<Record<string, boolean>>({});
  const [announcements, setAnnouncements] = useState<Announcement[]>([]);
  const [showNotifications, setShowNotifications] = useState(false);
  const [unreadCount, setUnreadCount] = useState(0);
  const [showAddSiteModal, setShowAddSiteModal] = useState(false);
  const [newSiteName, setNewSiteName] = useState('');
  const [newSiteDescription, setNewSiteDescription] = useState('');
  const [savingSite, setSavingSite] = useState(false);

  const handleAddSite = async () => {
    if (!newSiteName.trim()) return;
    
    setSavingSite(true);
    try {
      const token = localStorage.getItem('tenant_token') || localStorage.getItem('admin_token');
      const response = await fetch('/api/sites', {
        method: 'POST',
        headers: {
          'Authorization': `Bearer ${token}`,
          'Content-Type': 'application/json',
          'Accept': 'application/json',
        },
        body: JSON.stringify({
          name: newSiteName.trim(),
          description: newSiteDescription.trim() || null,
        }),
      });

      if (response.ok) {
        const data = await response.json();
        const newSite = data.site;
        setSelectedSite(newSite);
        await refreshSites();
        setShowAddSiteModal(false);
        setNewSiteName('');
        setNewSiteDescription('');
      } else {
        const error = await response.json();
        alert(error.message || error.error || 'Failed to create site');
      }
    } catch (error) {
      console.error('Failed to create site:', error);
      alert('Failed to create site');
    } finally {
      setSavingSite(false);
    }
  };

  const closeMobileMenu = () => setIsMobileMenuOpen(false);

  // Fetch announcements
  useEffect(() => {
    const fetchAnnouncements = async () => {
      try {
        const token = localStorage.getItem('tenant_token') || localStorage.getItem('admin_token');
        if (!token) return;
        
        const response = await fetch('/api/announcements/active', {
          headers: {
            'Authorization': `Bearer ${token}`,
            'Accept': 'application/json',
          },
        });
        
        if (response.ok) {
          const data = await response.json();
          const items = data.announcements || data.data || [];
          setAnnouncements(items);
          
          // Count unread (check localStorage for read IDs)
          const readIds = JSON.parse(localStorage.getItem('read_announcements') || '[]');
          const unread = items.filter((a: Announcement) => !readIds.includes(a.id)).length;
          setUnreadCount(unread);
        }
      } catch (error) {
        console.error('Failed to fetch announcements:', error);
      }
    };

    fetchAnnouncements();
    // Refresh every 5 minutes
    const interval = setInterval(fetchAnnouncements, 300000);
    return () => clearInterval(interval);
  }, []);

  const markAsRead = (id: number) => {
    const readIds = JSON.parse(localStorage.getItem('read_announcements') || '[]');
    if (!readIds.includes(id)) {
      readIds.push(id);
      localStorage.setItem('read_announcements', JSON.stringify(readIds));
      setUnreadCount(prev => Math.max(0, prev - 1));
    }
  };

  const markAllAsRead = () => {
    const readIds = announcements.map(a => a.id);
    localStorage.setItem('read_announcements', JSON.stringify(readIds));
    setUnreadCount(0);
  };

  const handleLogout = async () => {
    await logout();
    navigate('/login');
  };

  // Show loading spinner while checking authentication
  if (loading) {
    return (
      <div className="min-h-screen bg-background flex items-center justify-center">
        <div className="w-8 h-8 border-2 border-primary border-t-transparent rounded-full animate-spin" />
      </div>
    );
  }

  // Redirect to login if not authenticated
  if (!user) {
    return <Navigate to="/login" replace />;
  }

  return (
    <div className="flex h-screen bg-background overflow-hidden">
      {/* Mobile overlay */}
      {isMobileMenuOpen && (
        <div className="fixed inset-0 bg-black/50 z-40 lg:hidden" onClick={closeMobileMenu} />
      )}

      {/* Sidebar */}
      <aside
        className={`
          fixed lg:static inset-y-0 left-0 z-50
          w-64 bg-sidebar border-r border-sidebar-border flex flex-col
          transform transition-transform duration-300 ease-in-out
          ${isMobileMenuOpen ? 'translate-x-0' : '-translate-x-full lg:translate-x-0'}
        `}
      >
        <button
          onClick={closeMobileMenu}
          className="lg:hidden absolute top-4 right-4 p-2 text-sidebar-foreground hover:bg-sidebar-accent rounded-lg"
        >
          <X className="w-5 h-5" />
        </button>

        <div className="p-6 border-b border-sidebar-border">
          <h1 className="text-2xl text-primary font-semibold">LITE</h1>
          <p className="text-sm text-sidebar-foreground/70 mt-1">Mobile Money Payments Dashboard</p>
          
          {/* Site Selector */}
          <div className="mt-4 relative">
            <button
              onClick={() => setIsSiteDropdownOpen(!isSiteDropdownOpen)}
              className="w-full flex items-center justify-between gap-2 px-3 py-2 bg-sidebar-accent rounded-lg hover:bg-sidebar-accent/80 transition-colors text-left"
            >
              <div className="flex items-center gap-2 flex-1 min-w-0">
                <Building2 className="w-4 h-4 text-primary flex-shrink-0" />
                <span className="text-sm text-sidebar-foreground truncate">{selectedSite?.name || 'Select Site'}</span>
              </div>
              <ChevronDown className={`w-4 h-4 text-sidebar-foreground/60 flex-shrink-0 transition-transform ${isSiteDropdownOpen ? 'rotate-180' : ''}`} />
            </button>
            
            {/* Dropdown Menu */}
            {isSiteDropdownOpen && (
              <div className="absolute top-full left-0 right-0 mt-1 bg-card border border-border rounded-lg shadow-lg z-50 overflow-hidden">
                {sites.length === 0 ? (
                  <div className="px-3 py-2 text-sm text-muted-foreground">No sites yet</div>
                ) : (
                  sites.map((site) => (
                    <button
                      key={site.id}
                      onClick={() => {
                        setSelectedSite(site);
                        setIsSiteDropdownOpen(false);
                      }}
                      className={`w-full px-3 py-2 text-left text-sm transition-colors ${
                        selectedSite?.id === site.id
                          ? 'bg-primary text-primary-foreground'
                          : 'text-card-foreground hover:bg-muted'
                      }`}
                    >
                      {site.name}
                    </button>
                  ))
                )}
                {/* Add New Site Option */}
                <button
                  onClick={() => {
                    setIsSiteDropdownOpen(false);
                    setShowAddSiteModal(true);
                  }}
                  className="w-full px-3 py-2 text-left text-sm transition-colors text-primary hover:bg-muted border-t border-border flex items-center gap-2"
                >
                  <Plus className="w-4 h-4" />
                  Add New Site
                </button>
              </div>
            )}
          </div>
        </div>

        <nav className="flex-1 p-4 overflow-y-auto">
          <ul className="space-y-1">
            {menuItems
              .filter(item => !item.adminOnly || user?.role === 'super_admin')
              .map((item) => {
                const Icon = item.icon;
                const hasChildren = item.children && item.children.length > 0;
                const isExpanded = expandedMenus[item.path] || false;
                const isActive = hasChildren
                  ? item.children!.some(child => location.pathname === child.path || location.pathname.startsWith(child.path + '/'))
                  : item.path === '/'
                    ? location.pathname === '/'
                    : location.pathname.startsWith(item.path);
                
                if (hasChildren) {
                  return (
                    <li key={item.path}>
                      <button
                        onClick={() => setExpandedMenus(prev => ({ ...prev, [item.path]: !prev[item.path] }))}
                        className={`w-full flex items-center justify-between gap-3 px-4 py-3 rounded-lg transition-colors ${
                          isActive
                            ? 'bg-primary/10 text-primary'
                            : 'text-sidebar-foreground hover:bg-sidebar-accent'
                        }`}
                      >
                        <div className="flex items-center gap-3">
                          <Icon className="w-5 h-5 flex-shrink-0" />
                          <span className="text-sm">{item.label}</span>
                        </div>
                        <ChevronRight className={`w-4 h-4 transition-transform ${isExpanded ? 'rotate-90' : ''}`} />
                      </button>
                      {isExpanded && (
                        <ul className="mt-1 ml-4 pl-4 border-l border-sidebar-border space-y-1">
                          {item.children!.map((child) => {
                            const ChildIcon = child.icon;
                            const isChildActive = location.pathname === child.path || location.pathname.startsWith(child.path + '/');
                            return (
                              <li key={child.path}>
                                <Link
                                  to={child.path}
                                  onClick={closeMobileMenu}
                                  className={`flex items-center gap-3 px-3 py-2 rounded-lg transition-colors text-sm ${
                                    isChildActive
                                      ? 'bg-primary text-primary-foreground'
                                      : 'text-sidebar-foreground hover:bg-sidebar-accent'
                                  }`}
                                >
                                  <ChildIcon className="w-4 h-4 flex-shrink-0" />
                                  <span>{child.label}</span>
                                </Link>
                              </li>
                            );
                          })}
                        </ul>
                      )}
                    </li>
                  );
                }
                
                return (
                  <li key={item.path}>
                    <Link
                      to={item.path}
                      onClick={closeMobileMenu}
                      className={`flex items-center gap-3 px-4 py-3 rounded-lg transition-colors ${
                        isActive
                          ? 'bg-primary text-primary-foreground'
                          : 'text-sidebar-foreground hover:bg-sidebar-accent'
                      }`}
                    >
                      <Icon className="w-5 h-5 flex-shrink-0" />
                      <span className="text-sm">{item.label}</span>
                    </Link>
                  </li>
                );
              })}
          </ul>
        </nav>

        {/* User info + logout */}
        <div className="p-4 border-t border-sidebar-border space-y-2">
          <div className="flex items-center gap-3 px-4 py-3 bg-sidebar-accent rounded-lg">
            <div className="w-9 h-9 bg-primary rounded-full flex items-center justify-center flex-shrink-0">
              <User className="w-4 h-4 text-primary-foreground" />
            </div>
            <div className="overflow-hidden flex-1 min-w-0">
              <p className="text-sm text-sidebar-foreground truncate font-medium">{user?.name}</p>
              <p className="text-xs text-sidebar-foreground/60 truncate">{user?.email}</p>
            </div>
          </div>
          <button
            onClick={handleLogout}
            className="w-full flex items-center justify-center gap-2 px-4 py-2.5 rounded-lg text-sm text-destructive hover:bg-destructive/10 transition-colors"
          >
            <LogOut className="w-4 h-4" />
            Sign out
          </button>
        </div>
      </aside>

      {/* Main content */}
      <main className="flex-1 overflow-auto w-full">
        {/* Mobile header */}
        <div className="lg:hidden sticky top-0 z-30 bg-sidebar border-b border-sidebar-border px-4 py-3 flex items-center justify-between">
          <button
            onClick={() => setIsMobileMenuOpen(true)}
            className="p-2 text-sidebar-foreground hover:bg-sidebar-accent rounded-lg"
          >
            <Menu className="w-6 h-6" />
          </button>
          <h1 className="text-lg font-semibold text-primary">PayLITE</h1>
          {/* Mobile Notification Bell */}
          <button
            onClick={() => setShowNotifications(!showNotifications)}
            className="relative p-2 text-sidebar-foreground hover:bg-sidebar-accent rounded-lg"
          >
            <Bell className="w-5 h-5" />
            {unreadCount > 0 && (
              <span className="absolute -top-1 -right-1 w-5 h-5 bg-destructive text-destructive-foreground text-xs rounded-full flex items-center justify-center">
                {unreadCount > 9 ? '9+' : unreadCount}
              </span>
            )}
          </button>
        </div>

        {/* Desktop Notification Bell - Fixed Position */}
        <div className="hidden lg:block fixed top-4 right-4 z-40">
          <button
            onClick={() => setShowNotifications(!showNotifications)}
            className="relative p-3 bg-card border border-border rounded-full shadow-lg hover:bg-muted transition-colors"
          >
            <Bell className="w-5 h-5 text-foreground" />
            {unreadCount > 0 && (
              <span className="absolute -top-1 -right-1 w-5 h-5 bg-destructive text-destructive-foreground text-xs rounded-full flex items-center justify-center">
                {unreadCount > 9 ? '9+' : unreadCount}
              </span>
            )}
          </button>
        </div>

        {/* Notifications Panel */}
        {showNotifications && (
          <>
            <div 
              className="fixed inset-0 z-40" 
              onClick={() => setShowNotifications(false)} 
            />
            <div className="fixed top-16 right-4 z-50 w-80 max-h-[70vh] bg-card border border-border rounded-lg shadow-xl overflow-hidden">
              <div className="p-4 border-b border-border flex items-center justify-between">
                <h3 className="font-semibold text-card-foreground">Notifications</h3>
                {unreadCount > 0 && (
                  <button
                    onClick={markAllAsRead}
                    className="text-xs text-primary hover:text-primary/80"
                  >
                    Mark all as read
                  </button>
                )}
              </div>
              <div className="max-h-[calc(70vh-60px)] overflow-y-auto">
                {announcements.length === 0 ? (
                  <div className="p-8 text-center">
                    <Bell className="w-10 h-10 text-muted-foreground mx-auto mb-2" />
                    <p className="text-sm text-muted-foreground">No notifications</p>
                  </div>
                ) : (
                  announcements.map((announcement) => {
                    const readIds = JSON.parse(localStorage.getItem('read_announcements') || '[]');
                    const isRead = readIds.includes(announcement.id);
                    
                    return (
                      <div
                        key={announcement.id}
                        onClick={() => markAsRead(announcement.id)}
                        className={`p-4 border-b border-border/50 cursor-pointer hover:bg-muted/50 transition-colors ${
                          !isRead ? 'bg-primary/5' : ''
                        }`}
                      >
                        <div className="flex items-start gap-3">
                          <div className={`w-2 h-2 rounded-full mt-2 flex-shrink-0 ${
                            announcement.type === 'warning' ? 'bg-yellow-500' :
                            announcement.type === 'error' ? 'bg-destructive' :
                            announcement.type === 'success' ? 'bg-emerald-500' :
                            'bg-primary'
                          }`} />
                          <div className="flex-1 min-w-0">
                            <p className="text-sm font-medium text-card-foreground">{announcement.title}</p>
                            <p className="text-xs text-muted-foreground mt-1 line-clamp-2">{announcement.content}</p>
                            <p className="text-xs text-muted-foreground/60 mt-2">
                              {new Date(announcement.created_at).toLocaleDateString('en-GB', {
                                day: '2-digit',
                                month: 'short',
                                hour: '2-digit',
                                minute: '2-digit'
                              })}
                            </p>
                          </div>
                        </div>
                      </div>
                    );
                  })
                )}
              </div>
            </div>
          </>
        )}

        <BillingGate>
          <Outlet />
        </BillingGate>
      </main>

      {/* Add New Site Modal */}
      {showAddSiteModal && (
        <div className="fixed inset-0 bg-black/50 flex items-center justify-center z-50 p-4">
          <div className="bg-card border border-border rounded-lg w-full max-w-md">
            <div className="p-6 border-b border-border">
              <h2 className="text-xl font-semibold text-card-foreground flex items-center gap-2">
                <Building2 className="w-5 h-5 text-primary" />
                Add New Site
              </h2>
              <p className="text-sm text-muted-foreground mt-1">
                Create a new site to manage separately
              </p>
            </div>
            <div className="p-6 space-y-4">
              <div>
                <label className="block text-sm font-medium text-card-foreground mb-2">
                  Site Name *
                </label>
                <input
                  type="text"
                  value={newSiteName}
                  onChange={(e) => setNewSiteName(e.target.value)}
                  placeholder="e.g., Branch Office, Remote Location"
                  className="w-full px-3 py-2 bg-background border border-input rounded-lg focus:outline-none focus:ring-2 focus:ring-primary text-foreground"
                />
              </div>
              <div>
                <label className="block text-sm font-medium text-card-foreground mb-2">
                  Description (Optional)
                </label>
                <input
                  type="text"
                  value={newSiteDescription}
                  onChange={(e) => setNewSiteDescription(e.target.value)}
                  placeholder="e.g., Main branch, Remote office"
                  className="w-full px-3 py-2 bg-background border border-input rounded-lg focus:outline-none focus:ring-2 focus:ring-primary text-foreground"
                />
              </div>
            </div>
            <div className="p-6 border-t border-border flex gap-3">
              <button
                onClick={() => {
                  setShowAddSiteModal(false);
                  setNewSiteName('');
                  setNewSiteDescription('');
                }}
                className="flex-1 px-4 py-2 border border-border text-card-foreground rounded-lg hover:bg-muted transition-colors"
              >
                Cancel
              </button>
              <button
                onClick={handleAddSite}
                disabled={!newSiteName.trim() || savingSite}
                className="flex-1 px-4 py-2 bg-primary text-primary-foreground rounded-lg hover:bg-primary/90 transition-colors disabled:opacity-50"
              >
                {savingSite ? 'Creating...' : 'Create Site'}
              </button>
            </div>
          </div>
        </div>
      )}
    </div>
  );
}
