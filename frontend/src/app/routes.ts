import { createBrowserRouter } from 'react-router';
import { Layout } from './components/Layout';
import { Dashboard } from './pages/DashboardEnhanced';
import { Transactions } from './pages/Transactions';
import { Withdrawals } from './pages/Withdrawals';
import { AnalyzePerformance } from './pages/AnalyzePerformance';
import { VoucherStock } from './pages/VoucherStock';
import { ImportVouchers } from './pages/ImportVouchers';
import { Clients } from './pages/Clients';
import { Devices } from './pages/Devices';
import { Vouchers } from './pages/Vouchers';
import { VoucherTypes } from './pages/VoucherTypes';
import { Settings } from './pages/Settings';
import { Signup } from './pages/Signup';
import { Login } from './pages/Login';
import { Users } from './pages/Users';

export const router = createBrowserRouter([
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
      { path: 'settings', Component: Settings },
      { path: 'users', Component: Users },
    ],
  },
  { path: '/login', Component: Login },
  { path: '/signup', Component: Signup },
]);
