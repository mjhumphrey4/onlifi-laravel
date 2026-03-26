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
} from 'lucide-react';
import { useAuth } from '../context/AuthContext';

interface Announcement {
  id: number;
  title: string;
  content: string;
  type: 'info' | 'warning' | 'success' | 'error';
  created_at: string;
  is_read?: boolean;
}

const menuItems = [
  { path: '/',               label: 'Dashboard',          icon: LayoutDashboard, adminOnly: false },
  { path: '/clients',        label: 'Clients',            icon: Users, adminOnly: false },
  { path: '/devices',        label: 'Devices',            icon: Server, adminOnly: false },
  { path: '/vouchers',       label: 'Vouchers',           icon: Ticket, adminOnly: false },
  { path: '/voucher-types',  label: 'Voucher Types',      icon: Clock, adminOnly: false },
  { path: '/voucher-templates', label: 'Voucher Templates', icon: Ticket, adminOnly: false },
  { path: '/users',          label: 'User Management',    icon: Users, adminOnly: true },
  { path: '/transactions',   label: 'Transactions',       icon: ArrowLeftRight, adminOnly: false },
  { path: '/withdrawals',    label: 'Withdrawals',        icon: Wallet, adminOnly: false },
  { path: '/performance',    label: 'Analyze Performance',icon: TrendingUp, adminOnly: false },
  { path: '/voucher-stock',  label: 'Voucher Stock',      icon: Package, adminOnly: false },
  { path: '/import-vouchers', label: 'Import Vouchers',    icon: Upload, adminOnly: false },
  { path: '/settings',       label: 'Settings',           icon: SettingsIcon, adminOnly: false },
];

export function Layout() {
  const location = useLocation();
  const navigate = useNavigate();
  const { user, loading, logout } = useAuth();
  const [isMobileMenuOpen, setIsMobileMenuOpen] = useState(false);
  const [selectedSite, setSelectedSite] = useState('Main Site');
  const [isSiteDropdownOpen, setIsSiteDropdownOpen] = useState(false);
  const [expandedMenus, setExpandedMenus] = useState<Record<string, boolean>>({});
  const [announcements, setAnnouncements] = useState<Announcement[]>([]);
  const [showNotifications, setShowNotifications] = useState(false);
  const [unreadCount, setUnreadCount] = useState(0);
  
  // Placeholder sites - will be populated dynamically later
  const availableSites = ['Main Site', 'Branch Office', 'Remote Location'];

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
                <span className="text-sm text-sidebar-foreground truncate">{selectedSite}</span>
              </div>
              <ChevronDown className={`w-4 h-4 text-sidebar-foreground/60 flex-shrink-0 transition-transform ${isSiteDropdownOpen ? 'rotate-180' : ''}`} />
            </button>
            
            {/* Dropdown Menu */}
            {isSiteDropdownOpen && (
              <div className="absolute top-full left-0 right-0 mt-1 bg-card border border-border rounded-lg shadow-lg z-50 overflow-hidden">
                {availableSites.map((site) => (
                  <button
                    key={site}
                    onClick={() => {
                      setSelectedSite(site);
                      setIsSiteDropdownOpen(false);
                    }}
                    className={`w-full px-3 py-2 text-left text-sm transition-colors ${
                      selectedSite === site
                        ? 'bg-primary text-primary-foreground'
                        : 'text-card-foreground hover:bg-muted'
                    }`}
                  >
                    {site}
                  </button>
                ))}
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
                const hasSubmenu = false; // Submenus not currently used
                const isExpanded = expandedMenus[item.path] || false;
                const isActive =
                  item.path === '/'
                    ? location.pathname === '/'
                    : location.pathname.startsWith(item.path);
                
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

        <Outlet />
      </main>
    </div>
  );
}