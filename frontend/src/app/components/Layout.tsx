import { Link, Outlet, useLocation, Navigate, useNavigate } from 'react-router';
import { useState, useEffect } from 'react';
import {
  LayoutDashboard,
  ArrowLeftRight,
  Wallet,
  TrendingUp,
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
  BarChart3,
  Network,
  ShieldCheck,
  UserCog,
} from 'lucide-react';
import { useAuth } from '../context/AuthContext';
import { useSite } from '../context/SiteContext';
import { API_BASE } from '../utils/api';

interface Announcement {
  id: number | string;
  title: string;
  content: string;
  type: 'info' | 'warning' | 'success' | 'error';
  created_at: string;
  is_read?: boolean;
  ticket_id?: number;
}

interface MenuItem {
  path: string;
  label: string;
  icon: any;
  adminOnly: boolean;
  tenantAdminOnly?: boolean;
  permission?: string;
  children?: MenuItem[];
}

const menuItems: MenuItem[] = [
  { path: '/dashboard',      label: 'Dashboard',          icon: LayoutDashboard, adminOnly: false },
  { path: '/clients',        label: 'Clients',            icon: Users, adminOnly: false, permission: 'view_clients' },
  { path: '/devices',        label: 'Monitor Router',     icon: Server, adminOnly: false, permission: 'view_routers' },
  { path: '/routers',        label: 'Accesspoints',       icon: Router, adminOnly: false, permission: 'view_routers' },
  { 
    path: '/vouchers',       
    label: 'Manage Vouchers',           
    icon: Ticket, 
    adminOnly: false,
    permission: 'manage_vouchers',
    children: [
      { path: '/vouchers',       label: 'Vouchers',           icon: Ticket, adminOnly: false },
      { path: '/manual-vouchers', label: 'Manual Vouchers', icon: Plus, adminOnly: false },
      { path: '/expired-vouchers', label: 'Expired Vouchers', icon: Clock, adminOnly: false },
      { path: '/voucher-types',  label: 'Voucher Types',      icon: Clock, adminOnly: false },
      { path: '/voucher-templates', label: 'Templates', icon: Ticket, adminOnly: false },
      { path: '/import-vouchers', label: 'Import',    icon: Upload, adminOnly: false },
    ]
  },
  { path: '/users',          label: 'User Management',    icon: Users, adminOnly: true },
  { path: '/transactions',   label: 'Transactions',       icon: ArrowLeftRight, adminOnly: false, permission: 'view_transactions' },
  { path: '/withdrawals',    label: 'Withdrawals',        icon: Wallet, adminOnly: false, tenantAdminOnly: true },
  { path: '/performance',    label: 'Analyze Performance',icon: TrendingUp, adminOnly: false, permission: 'view_transactions' },
  { path: '/reports',        label: 'Reports',            icon: BarChart3, adminOnly: false, permission: 'view_transactions' },
  { path: '/support-tickets', label: 'Support Tickets',    icon: MessageSquare, adminOnly: false },
  { path: '/sms-gateway',     label: 'SMS Gateway',        icon: MessageSquare, adminOnly: false, tenantAdminOnly: true },
  {
    path: '/router-users',
    label: 'Manage Router',
    icon: Router,
    adminOnly: false,
    permission: 'view_routers',
    children: [
      { path: '/router-users', label: 'Users', icon: UserCog, adminOnly: false },
      { path: '/dhcp', label: 'DHCP', icon: Network, adminOnly: false },
      { path: '/pppoe', label: 'PPPoE', icon: Network, adminOnly: false },
      { path: '/ip-bindings', label: 'IP Bindings', icon: ShieldCheck, adminOnly: false },
      { path: '/remote-access', label: 'Remote Access', icon: Network, adminOnly: false },
      { path: '/captive-portal', label: 'Captive Page', icon: Paintbrush, adminOnly: false },
      { path: '/provisioning', label: 'Provisioning', icon: Router, adminOnly: false },
    ],
  },
  {
    path: '/settings',
    label: 'Settings',
    icon: SettingsIcon,
    adminOnly: false,
    tenantAdminOnly: true,
    children: [
      { path: '/settings', label: 'General Settings', icon: SettingsIcon, adminOnly: false },
      { path: '/account-users', label: 'Account Users', icon: UserCog, adminOnly: false },
    ],
  },
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
  const [ticketUnreadCount, setTicketUnreadCount] = useState(0);
  const [activeClientCount, setActiveClientCount] = useState(0);
  const [showAddSiteModal, setShowAddSiteModal] = useState(false);
  const [newSiteName, setNewSiteName] = useState('');
  const [newSiteDescription, setNewSiteDescription] = useState('');
  const [newSiteType, setNewSiteType] = useState<'mikrotik' | 'omada'>('mikrotik');
  const [newOmadaSiteName, setNewOmadaSiteName] = useState('');
  const [savingSite, setSavingSite] = useState(false);
  const [applicationTime, setApplicationTime] = useState('');

  const handleAddSite = async () => {
    if (!newSiteName.trim()) return;
    
    setSavingSite(true);
    try {
      const token = localStorage.getItem('tenant_token');
      if (!token || user?.role !== 'tenant' || !user?.tenant_id) {
        alert('Please sign in as a tenant before creating a site.');
        return;
      }

      const response = await fetch(`${API_BASE}/sites`, {
        method: 'POST',
        headers: {
          'Authorization': `Bearer ${token}`,
          'Content-Type': 'application/json',
          'Accept': 'application/json',
        },
        credentials: 'include',
        body: JSON.stringify({
          name: newSiteName.trim(),
          description: newSiteDescription.trim() || null,
          site_type: newSiteType,
          omada_site_name: newSiteType === 'omada'
            ? (newOmadaSiteName.trim() || newSiteName.trim())
            : null,
        }),
      });

      if (response.ok) {
        const data = await response.json();
        const newSite = data.site;
        await refreshSites();
        setSelectedSite(newSite);
        setShowAddSiteModal(false);
        setNewSiteName('');
        setNewSiteDescription('');
        setNewSiteType('mikrotik');
        setNewOmadaSiteName('');
      } else {
        const error = await readJson(response);
        alert(error.message || error.error || 'Failed to create site');
      }
    } catch (error) {
      console.error('Failed to create site:', error);
      alert('Failed to create site');
    } finally {
      setSavingSite(false);
    }
  };

  useEffect(() => {
    const updateClock = () => {
      setApplicationTime(new Date().toLocaleString('en-GB', {
        timeZone: 'Africa/Nairobi',
        weekday: 'short',
        hour: '2-digit',
        minute: '2-digit',
      }));
    };

    updateClock();
    const interval = window.setInterval(updateClock, 30000);
    return () => window.clearInterval(interval);
  }, []);

  const closeMobileMenu = () => setIsMobileMenuOpen(false);
  const canSeeMenuItem = (item: MenuItem) => {
    if (item.adminOnly && user?.role !== 'super_admin') return false;
    if (item.tenantAdminOnly && user?.role !== 'tenant') return false;
    if (item.permission && user?.role === 'sub_user' && !user.permissions?.includes(item.permission)) return false;
    return true;
  };

  // Fetch announcements and ticket notifications
  useEffect(() => {
    const fetchAnnouncements = async () => {
      try {
        const token = localStorage.getItem('tenant_token');
        if (!token) return;
        
        const headers: Record<string, string> = {
          'Authorization': `Bearer ${token}`,
          'Accept': 'application/json',
        };
        const siteId = localStorage.getItem('selected_site_id');
        if (siteId) headers['X-Site-ID'] = siteId;

        const [response, ticketResponse, clientsResponse] = await Promise.all([
          fetch(`${API_BASE}/announcements/active`, {
            headers,
          }),
          fetch(`${API_BASE}/tenant/support-tickets/notifications`, {
            headers,
          }),
          fetch(`${API_BASE}/clients?limit=1`, {
            headers,
          }),
        ]);

        let items: Announcement[] = [];

        if (response.ok) {
          const data = await response.json();
          items = data.announcements || data.data || [];
        }

        if (ticketResponse.ok) {
          const ticketData = await ticketResponse.json();
          items = [
            ...(ticketData.notifications || []),
            ...items,
          ];
        }

        if (clientsResponse.ok) {
          const clientsData = await clientsResponse.json();
          setActiveClientCount(Number(clientsData.total || 0));
        }

        setAnnouncements(items);

        // Count unread (check localStorage for read IDs)
        const readIds = JSON.parse(localStorage.getItem('read_announcements') || '[]');
        const unread = items.filter((a: Announcement) => !readIds.includes(a.id)).length;
        const unreadTickets = items.filter((a: Announcement) => a.ticket_id && !readIds.includes(a.id)).length;
        setUnreadCount(unread);
        setTicketUnreadCount(unreadTickets);
      } catch (error) {
        console.error('Failed to fetch announcements:', error);
      }
    };

    fetchAnnouncements();
    // Refresh ticket notifications quickly while still carrying announcements.
    const interval = setInterval(fetchAnnouncements, 60000);
    return () => clearInterval(interval);
  }, [selectedSite?.id]);

  const markAsRead = (id: number | string) => {
    const readIds = JSON.parse(localStorage.getItem('read_announcements') || '[]');
    if (!readIds.includes(id)) {
      readIds.push(id);
      localStorage.setItem('read_announcements', JSON.stringify(readIds));
      setUnreadCount(prev => Math.max(0, prev - 1));
      const item = announcements.find((announcement) => announcement.id === id);
      if (item?.ticket_id) {
        setTicketUnreadCount(prev => Math.max(0, prev - 1));
      }
    }
  };

  const markAllAsRead = () => {
    const readIds = announcements.map(a => a.id);
    localStorage.setItem('read_announcements', JSON.stringify(readIds));
    setUnreadCount(0);
    setTicketUnreadCount(0);
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
          <h1 className="text-2xl text-primary font-semibold">ONLIFI</h1>
          <p className="text-sm text-sidebar-foreground/70 mt-1">Network Management System</p>
          
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
              .filter(canSeeMenuItem)
              .map((item) => {
                const Icon = item.icon;
                const visibleChildren = item.children?.filter(canSeeMenuItem) || [];
                const hasChildren = visibleChildren.length > 0;
                const isExpanded = expandedMenus[item.path] || false;
                const isActive = hasChildren
                  ? visibleChildren.some(child => location.pathname === child.path || location.pathname.startsWith(child.path + '/'))
                  : item.path === '/'
                    ? location.pathname === '/'
                    : location.pathname.startsWith(item.path);
                
                if (hasChildren) {
                  return (
                    <li key={item.path}>
                      <button
                        onClick={() => {
                          navigate(item.path);
                          setExpandedMenus(prev => ({ ...prev, [item.path]: true }));
                        }}
                        className={`w-full flex items-center justify-between gap-3 px-4 py-3 rounded-lg transition-colors ${
                          isActive
                            ? 'bg-primary/10 text-primary'
                            : 'text-sidebar-foreground hover:bg-sidebar-accent'
                        }`}
                      >
                        <div className="flex items-center gap-3">
                          <Icon className="w-5 h-5 flex-shrink-0" />
                          <span className="text-sm">{item.label}</span>
                          {item.path === '/clients' && (
                            <span className="ml-auto flex h-5 min-w-5 items-center justify-center rounded-full bg-primary/15 px-1 text-[10px] font-semibold text-primary">
                              {activeClientCount > 99 ? '99+' : activeClientCount}
                            </span>
                          )}
                          {item.path === '/support-tickets' && ticketUnreadCount > 0 && (
                            <span className="ml-auto flex h-5 min-w-5 items-center justify-center rounded-full bg-destructive px-1 text-[10px] font-semibold text-destructive-foreground">
                              {ticketUnreadCount > 9 ? '9+' : ticketUnreadCount}
                            </span>
                          )}
                        </div>
                        <ChevronRight className={`w-4 h-4 transition-transform ${isExpanded ? 'rotate-90' : ''}`} />
                      </button>
                      {isExpanded && (
                        <ul className="mt-1 ml-4 pl-4 border-l border-sidebar-border space-y-1">
                          {visibleChildren.map((child) => {
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
                      className={`flex w-full items-center gap-3 px-4 py-3 rounded-lg transition-colors ${
                        isActive
                          ? 'bg-primary text-primary-foreground'
                          : 'text-sidebar-foreground hover:bg-sidebar-accent'
                      }`}
                    >
                      <Icon className="w-5 h-5 flex-shrink-0" />
                      <span className="text-sm">{item.label}</span>
                      {item.path === '/clients' && (
                        <span className="ml-auto flex h-5 min-w-5 items-center justify-center rounded-full bg-primary/15 px-1 text-[10px] font-semibold text-primary">
                          {activeClientCount > 99 ? '99+' : activeClientCount}
                        </span>
                      )}
                      {item.path === '/support-tickets' && ticketUnreadCount > 0 && (
                        <span className="ml-auto flex h-5 min-w-5 items-center justify-center rounded-full bg-destructive px-1 text-[10px] font-semibold text-destructive-foreground">
                          {ticketUnreadCount > 9 ? '9+' : ticketUnreadCount}
                        </span>
                      )}
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
          <h1 className="text-lg font-semibold text-primary">ONLIFI</h1>
          <div className="flex items-center gap-2">
            <div className="hidden sm:flex items-center gap-1 text-xs text-sidebar-foreground/80 bg-sidebar-accent px-2 py-1 rounded-lg">
              <Clock className="w-3 h-3" />
              {applicationTime}
            </div>
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
        </div>

        {/* Desktop top bar */}
        <div className="hidden lg:flex sticky top-0 z-30 h-16 items-center justify-between border-b border-border bg-background/95 px-6 backdrop-blur">
          <div className="flex min-w-0 items-center gap-4">
            <div className="flex min-w-0 items-center gap-2 rounded-lg border border-border bg-card px-3 py-2 text-sm text-foreground shadow-sm">
              <User className="h-4 w-4 shrink-0 text-primary" />
              <span className="max-w-[220px] truncate font-medium">{user?.name || user?.email || 'User'}</span>
            </div>
            <div className="min-w-0">
              <p className="text-xs font-medium uppercase tracking-wide text-muted-foreground">Active Site</p>
              <p className="truncate text-sm font-semibold text-foreground">
                {selectedSite?.name || 'Default Site'}
              </p>
            </div>
          </div>
          <div className="flex items-center gap-3">
            <div className="flex min-w-[190px] items-center justify-center gap-2 rounded-lg border border-border bg-card px-4 py-2 text-sm text-foreground shadow-sm">
              <Clock className="w-4 h-4 text-primary" />
              <span className="font-medium">{applicationTime}</span>
              <span className="text-xs text-muted-foreground">EAT</span>
            </div>
            <button
              onClick={() => setShowNotifications(!showNotifications)}
              className="relative flex h-10 w-12 items-center justify-center rounded-lg border border-border bg-card shadow-sm transition-colors hover:bg-muted"
              aria-label="Open notifications"
            >
              <Bell className="w-5 h-5 text-foreground" />
              {unreadCount > 0 && (
                <span className="absolute -top-1 -right-1 min-w-5 h-5 px-1 bg-destructive text-destructive-foreground text-xs rounded-full flex items-center justify-center">
                  {unreadCount > 9 ? '9+' : unreadCount}
                </span>
              )}
            </button>
          </div>
        </div>

        {/* Notifications Panel */}
        {showNotifications && (
          <>
            <div 
              className="fixed inset-0 z-40" 
              onClick={() => setShowNotifications(false)} 
            />
            <div className="fixed top-20 right-4 lg:right-6 z-50 w-80 max-h-[70vh] bg-card border border-border rounded-lg shadow-xl overflow-hidden">
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
                        onClick={() => {
                          markAsRead(announcement.id);
                          if (announcement.ticket_id) {
                            setShowNotifications(false);
                            navigate(`/support-tickets?ticket=${announcement.ticket_id}`);
                          }
                        }}
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

        <Outlet />
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
              <div>
                <label className="block text-sm font-medium text-card-foreground mb-2">
                  Site Type
                </label>
                <div className="grid grid-cols-2 gap-2">
                  {(['mikrotik', 'omada'] as const).map((type) => (
                    <label
                      key={type}
                      className={`flex items-center gap-2 rounded-lg border px-3 py-2 cursor-pointer transition-colors ${
                        newSiteType === type ? 'border-primary bg-primary/10 text-primary' : 'border-input text-card-foreground hover:bg-muted'
                      }`}
                    >
                      <input
                        type="radio"
                        name="site_type"
                        value={type}
                        checked={newSiteType === type}
                        onChange={() => setNewSiteType(type)}
                        className="accent-primary"
                      />
                      <span className="text-sm font-medium">{type === 'mikrotik' ? 'Mikrotik' : 'TP-Link Omada'}</span>
                    </label>
                  ))}
                </div>
                <p className="text-xs text-muted-foreground mt-2">Omada sites use controller APIs after an administrator links the Omada Site ID.</p>
              </div>
              {newSiteType === 'omada' && (
                <div className="space-y-3 rounded-lg border border-orange-200 bg-orange-50 p-3 text-sm text-orange-950">
                  <div>
                    <label className="block text-sm font-medium mb-2">
                      Omada Site Name *
                    </label>
                    <input
                      type="text"
                      value={newOmadaSiteName}
                      onChange={(e) => setNewOmadaSiteName(e.target.value)}
                      placeholder="Exact site name in Omada Controller"
                      className="w-full px-3 py-2 bg-background border border-orange-200 rounded-lg focus:outline-none focus:ring-2 focus:ring-orange-400 text-foreground"
                    />
                  </div>
                  <p className="text-xs leading-relaxed">
                    Omada routers must be linked to omada.onlifi.net before API data can appear. If they are not linked yet,
                    open a support ticket and an administrator will help map the Omada Site ID.
                  </p>
                </div>
              )}
            </div>
            <div className="p-6 border-t border-border flex gap-3">
              <button
                onClick={() => {
                  setShowAddSiteModal(false);
                  setNewSiteName('');
                  setNewSiteDescription('');
                  setNewSiteType('mikrotik');
                  setNewOmadaSiteName('');
                }}
                className="flex-1 px-4 py-2 border border-border text-card-foreground rounded-lg hover:bg-muted transition-colors"
              >
                Cancel
              </button>
              <button
                onClick={handleAddSite}
                disabled={!newSiteName.trim() || (newSiteType === 'omada' && !newOmadaSiteName.trim()) || savingSite}
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

async function readJson(response: Response) {
  const text = await response.text();
  try {
    return text ? JSON.parse(text) : {};
  } catch {
    return { message: `Server returned ${response.status} ${response.statusText}` };
  }
}
