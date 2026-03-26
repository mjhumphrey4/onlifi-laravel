import { createBrowserRouter } from 'react-router';
import { Layout } from './components/Layout';
import { AdminLayout } from './components/AdminLayout';
import { Dashboard } from './pages/Dashboard';
import { Transactions } from './pages/Transactions';
import { Withdrawals } from './pages/Withdrawals';
import { AnalyzePerformance } from './pages/AnalyzePerformance';
import { VoucherStock } from './pages/VoucherStock';
import { ImportVouchers } from './pages/ImportVouchers';
import { Clients } from './pages/Clients';
import { Devices } from './pages/Devices';
import { Vouchers } from './pages/Vouchers';
import { VoucherTypes } from './pages/VoucherTypes';
import { VoucherTemplates } from './pages/VoucherTemplates';
import { Settings } from './pages/Settings';
import { Signup } from './pages/Signup';
import { Login } from './pages/Login';
import { Users } from './pages/Users';

// Admin pages
import AdminLogin from './pages/admin/AdminLogin';
import AdminDashboard from './pages/admin/AdminDashboard';
import TenantApproval from './pages/admin/TenantApproval';
import TenantList from './pages/admin/TenantList';
import Announcements from './pages/admin/Announcements';
import SystemSettings from './pages/admin/SystemSettings';
import PlatformFees from './pages/admin/PlatformFees';

export const router = createBrowserRouter([
  // Tenant Dashboard Routes
  {
    path: '/',
    Component: Layout,
    children: [
      { index: true, Component: Dashboard },
      { path: 'transactions', Component: Transactions },
      { path: 'withdrawals', Component: Withdrawals },
      { path: 'performance', Component: AnalyzePerformance },
      { path: 'voucher-stock', Component: VoucherStock },
      { path: 'import-vouchers', Component: ImportVouchers },
      { path: 'clients', Component: Clients },
      { path: 'devices', Component: Devices },
      { path: 'vouchers', Component: Vouchers },
      { path: 'voucher-types', Component: VoucherTypes },
      { path: 'voucher-templates', Component: VoucherTemplates },
      { path: 'settings', Component: Settings },
      { path: 'users', Component: Users },
    ],
  },
  { path: '/login', Component: Login },
  { path: '/signup', Component: Signup },

  // Admin Routes with AdminLayout
  { path: '/admin/login', Component: AdminLogin },
  {
    path: '/admin',
    Component: AdminLayout,
    children: [
      { path: 'dashboard', Component: AdminDashboard },
      { path: 'tenants', Component: TenantList },
      { path: 'tenants/pending', Component: TenantApproval },
      { path: 'announcements', Component: Announcements },
      { path: 'settings', Component: SystemSettings },
      { path: 'platform-fees', Component: PlatformFees },
    ],
  },
]);
