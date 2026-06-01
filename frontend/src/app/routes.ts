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
import { RadiusSetup } from './pages/RadiusSetup';
import { Provisioning } from './pages/Provisioning';
import { CaptivePortal } from './pages/CaptivePortal';
import { SmsGateway } from './pages/SmsGateway';
import { RemoteAccess } from './pages/RemoteAccess';
import { Reports } from './pages/Reports';
import { PppoeClients } from './pages/PppoeClients';
import { Routers } from './pages/Routers';
import { IpBindings } from './pages/IpBindings';
import { Dhcp } from './pages/Dhcp';
import { RouterUsers } from './pages/RouterUsers';
import { SupportTickets } from './pages/SupportTickets';
import { Signup } from './pages/Signup';
import { Login } from './pages/Login';
import { ForgotPassword } from './pages/ForgotPassword';
import { ResetPassword } from './pages/ResetPassword';
import { Users } from './pages/Users';
import { SubUsers } from './pages/SubUsers';

// Admin pages
import AdminLogin from './pages/admin/AdminLogin';
import AdminDashboard from './pages/admin/AdminDashboard';
import TenantApproval from './pages/admin/TenantApproval';
import TenantList from './pages/admin/TenantList';
import Announcements from './pages/admin/Announcements';
import SystemSettings from './pages/admin/SystemSettings';
import PlatformFees from './pages/admin/PlatformFees';
import AdminSupportTickets from './pages/admin/SupportTickets';

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
      { path: 'reports', Component: Reports },
      { path: 'support-tickets', Component: SupportTickets },
      { path: 'remote-access', Component: RemoteAccess },
      { path: 'pppoe', Component: PppoeClients },
      { path: 'routers', Component: Routers },
      { path: 'router-users', Component: RouterUsers },
      { path: 'ip-bindings', Component: IpBindings },
      { path: 'dhcp', Component: Dhcp },
      { path: 'captive-portal', Component: CaptivePortal },
      { path: 'sms-gateway', Component: SmsGateway },
      { path: 'voucher-stock', Component: VoucherStock },
      { path: 'import-vouchers', Component: ImportVouchers },
      { path: 'clients', Component: Clients },
      { path: 'devices', Component: Devices },
      { path: 'vouchers', Component: Vouchers },
      { path: 'voucher-types', Component: VoucherTypes },
      { path: 'voucher-templates', Component: VoucherTemplates },
      { path: 'settings', Component: Settings },
      { path: 'radius-setup', Component: RadiusSetup },
      { path: 'provisioning', Component: Provisioning },
      { path: 'users', Component: Users },
      { path: 'sub-users', Component: SubUsers },
    ],
  },
  { path: '/login', Component: Login },
  { path: '/signup', Component: Signup },
  { path: '/forgot-password', Component: ForgotPassword },
  { path: '/reset-password', Component: ResetPassword },

  // Admin Routes with AdminLayout
  { path: '/admin/login', Component: AdminLogin },
  {
    path: '/admin',
    Component: AdminLayout,
    children: [
      { path: 'dashboard', Component: AdminDashboard },
      { path: 'tenants', Component: TenantList },
      { path: 'vpn-management', Component: TenantList },
      { path: 'tenants/pending', Component: TenantApproval },
      { path: 'support-tickets', Component: AdminSupportTickets },
      { path: 'announcements', Component: Announcements },
      { path: 'settings', Component: SystemSettings },
      { path: 'platform-fees', Component: PlatformFees },
    ],
  },
]);
