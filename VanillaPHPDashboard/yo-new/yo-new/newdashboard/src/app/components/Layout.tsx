import { Link, Outlet, useLocation } from 'react-router';
import { useState } from 'react';
import {
  LayoutDashboard,
  ArrowLeftRight,
  Wallet,
  Building2,
  MessageSquareText,
  TrendingUp,
  BarChart3,
  Package,
  Upload,
  Eye,
  Globe,
  User,
  Menu,
  X,
  LogOut,
} from 'lucide-react';
import { useAuth } from '../context/AuthContext';

const menuItems = [
  { path: '/',               label: 'Dashboard',          icon: LayoutDashboard },
  { path: '/sites',          label: 'Sites',              icon: Building2 },
  { path: '/transactions',   label: 'Transactions',       icon: ArrowLeftRight },
  { path: '/withdrawals',    label: 'Withdrawals',        icon: Wallet },
  { path: '/sms-logs',       label: 'SMS Logs',           icon: MessageSquareText },
  { path: '/performance',    label: 'Analyze Performance',icon: TrendingUp },
  { path: '/performance-graphs', label: 'Performance Graphs', icon: BarChart3 },
  { path: '/voucher-stock',  label: 'Voucher Stock',      icon: Package },
  { path: '/import-vouchers', label: 'Import Vouchers',    icon: Upload },
  { path: '/monitor-vouchers', label: 'Monitor Vouchers',  icon: Eye },
  { path: '/captive-page',   label: 'Captive Page',       icon: Globe },
];

export function Layout() {
  const location = useLocation();
  const { user, logout } = useAuth();
  const [isMobileMenuOpen, setIsMobileMenuOpen] = useState(false);

  const closeMobileMenu = () => setIsMobileMenuOpen(false);

  const handleLogout = async () => {
    await logout();
  };

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
          <h1 className="text-2xl text-primary font-semibold">Onlifi Lite</h1>
          <p className="text-sm text-sidebar-foreground/70 mt-1">Mobile Money Payments Dashboard</p>
        </div>

        <nav className="flex-1 p-4 overflow-y-auto">
          <ul className="space-y-1">
            {menuItems.map((item) => {
              const Icon = item.icon;
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
          <h1 className="text-lg font-semibold text-primary">Onlifi Lite</h1>
          <div className="w-10" />
        </div>

        <Outlet />
      </main>
    </div>
  );
}
